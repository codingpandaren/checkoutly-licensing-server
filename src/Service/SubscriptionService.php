<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\License;
use App\Entity\User;
use App\Repository\LicenseRepository;
use App\Repository\UserRepository;
use Stripe\Subscription;

/**
 * Turns Stripe subscription events into License state. One Stripe subscription
 * maps to one License, keyed by the subscription id (idempotent - a replayed
 * checkout event updates rather than duplicates). Keys are perpetual and
 * domain-less; access is governed by the synced status + revoked flag and the
 * portal-registered domain, per the enforcement model.
 */
class SubscriptionService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LicenseRepository $licenses,
        private readonly LicenseSigner $signer,
    ) {
    }

    /**
     * Issue (or refresh) the license for a completed checkout / active subscription.
     */
    public function provision(string $customerId, Subscription $subscription, string $tier = 'pro'): ?License
    {
        $user = $this->users->findOneByStripeCustomerId($customerId);
        if (!$user instanceof User) {
            return null;
        }

        $license = $this->licenses->findOneByStripeSubscriptionId($subscription->id);
        if (!$license instanceof License) {
            $issued = $this->signer->issue($user->getEmail(), $tier, 0, '');
            $license = (new License())
                ->setLicenseId($issued['id'])
                ->setUser($user)
                ->setTier($tier)
                ->setLicenseKey($issued['key'])
                ->setStripeSubscriptionId($subscription->id)
                ->setStripeCustomerId($customerId)
                ->setExpiresAt(0);
        }

        $this->applyState($license, $subscription);
        $this->licenses->save($license);

        return $license;
    }

    /**
     * Sync status + scheduled-cancellation state from a subscription object. Used
     * for customer.subscription.updated (a scheduled cancel arrives here with the
     * status still active and cancel_at set) and .deleted (status canceled).
     */
    public function syncFromSubscription(Subscription $subscription): void
    {
        $license = $this->licenses->findOneByStripeSubscriptionId($subscription->id);
        if ($license instanceof License) {
            $this->applyState($license, $subscription);
            $this->licenses->save($license);
        }
    }

    /**
     * Refund / chargeback kill switch: revoke every license for the customer.
     */
    public function revokeByCustomer(string $customerId): void
    {
        foreach ($this->licenses->findBy(['stripeCustomerId' => $customerId]) as $license) {
            $license->setRevoked(true);
            $this->licenses->save($license, false);
        }
        $this->licenses->flush();
    }

    private function applyState(License $license, Subscription $subscription): void
    {
        $license->setStatus($this->mapStatus((string) $subscription->status));

        // Stripe schedules a cancellation either via the cancel_at_period_end
        // boolean or a concrete cancel_at timestamp (which API version / the portal
        // uses varies). Treat either as "scheduled to cancel" and keep the date.
        $scheduledEnd = $subscription->cancel_at ?? null;
        $license->setCancelAtPeriodEnd((bool) ($subscription->cancel_at_period_end ?? false) || $scheduledEnd !== null);

        $end = $scheduledEnd ?? $subscription->ended_at ?? null;
        $license->setEndsAt($end !== null ? new \DateTimeImmutable('@' . (int) $end) : null);

        $periodEnd = $this->periodEnd($subscription);
        $license->setCurrentPeriodEnd($periodEnd !== null ? new \DateTimeImmutable('@' . $periodEnd) : null);
    }

    /**
     * The date the current period ends: trial end while trialing, otherwise the
     * billing-period end. current_period_end was a top-level field historically
     * but newer Stripe API versions expose it on the subscription item instead.
     */
    private function periodEnd(Subscription $subscription): ?int
    {
        if (($subscription->status ?? '') === 'trialing' && !empty($subscription->trial_end)) {
            return (int) $subscription->trial_end;
        }

        if (!empty($subscription->current_period_end)) {
            return (int) $subscription->current_period_end;
        }

        $item = $subscription->items->data[0] ?? null;
        if ($item !== null && !empty($item->current_period_end)) {
            return (int) $item->current_period_end;
        }

        return null;
    }

    private function mapStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active' => License::STATUS_ACTIVE,
            'trialing' => License::STATUS_TRIALING,
            'past_due' => License::STATUS_PAST_DUE,
            'unpaid' => License::STATUS_UNPAID,
            'canceled', 'incomplete_expired' => License::STATUS_CANCELED,
            default => License::STATUS_INCOMPLETE,
        };
    }
}

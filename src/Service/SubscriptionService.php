<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\License;
use App\Entity\User;
use App\Repository\LicenseRepository;
use App\Repository\UserRepository;

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
    public function provision(string $customerId, string $subscriptionId, string $stripeStatus, string $tier = 'pro'): ?License
    {
        $user = $this->users->findOneByStripeCustomerId($customerId);
        if (!$user instanceof User) {
            return null;
        }

        $license = $this->licenses->findOneByStripeSubscriptionId($subscriptionId);
        if (!$license instanceof License) {
            $issued = $this->signer->issue($user->getEmail(), $tier, 0, '');
            $license = (new License())
                ->setLicenseId($issued['id'])
                ->setUser($user)
                ->setTier($tier)
                ->setLicenseKey($issued['key'])
                ->setStripeSubscriptionId($subscriptionId)
                ->setStripeCustomerId($customerId)
                ->setExpiresAt(0);
        }

        $license->setStatus($this->mapStatus($stripeStatus));
        $this->licenses->save($license);

        return $license;
    }

    public function syncStatus(string $subscriptionId, string $stripeStatus): void
    {
        $license = $this->licenses->findOneByStripeSubscriptionId($subscriptionId);
        if ($license instanceof License) {
            $license->setStatus($this->mapStatus($stripeStatus));
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

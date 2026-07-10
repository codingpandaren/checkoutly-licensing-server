<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Thin wrapper over the Stripe SDK: customer/checkout/billing-portal creation,
 * subscription retrieval and webhook signature verification. Holds no business
 * logic (that lives in SubscriptionService) and touches no database.
 */
class StripeService
{
    private StripeClient $client;

    public function __construct(
        #[Autowire('%env(STRIPE_SECRET_KEY)%')]
        string $secretKey,
        #[Autowire('%env(STRIPE_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret = '',
    ) {
        $this->client = new StripeClient($secretKey);
    }

    /**
     * Return the user's Stripe customer id, creating the customer on first use.
     * The caller is responsible for persisting a newly created id on the User.
     */
    public function ensureCustomer(User $user): string
    {
        $existing = $user->getStripeCustomerId();
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $customer = $this->client->customers->create([
            'email' => $user->getEmail(),
            'metadata' => ['user_id' => (string) $user->getId()],
        ]);

        return $customer->id;
    }

    public function createCheckoutSession(string $customerId, string $priceId, string $successUrl, string $cancelUrl, int $trialDays = 0): CheckoutSession
    {
        $params = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'allow_promotion_codes' => true,
        ];

        // A trial still collects a card up front (Checkout's default), so the
        // subscription converts to paid automatically when the trial ends.
        if ($trialDays > 0) {
            $params['subscription_data'] = ['trial_period_days' => $trialDays];
        }

        return $this->client->checkout->sessions->create($params);
    }

    public function createBillingPortalSession(string $customerId, string $returnUrl): PortalSession
    {
        return $this->client->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);
    }

    public function retrieveSubscription(string $subscriptionId): Subscription
    {
        return $this->client->subscriptions->retrieve($subscriptionId);
    }

    public function constructEvent(string $payload, string $signatureHeader): Event
    {
        return Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\StripeService;
use App\Service\SubscriptionService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stripe webhook endpoint. Public (no login) but authenticated by the Stripe
 * signature. Drives the license lifecycle:
 *   - checkout.session.completed      -> issue/activate the license
 *   - customer.subscription.updated   -> sync status (active/past_due/…)
 *   - customer.subscription.deleted   -> mark canceled
 *   - charge.refunded / dispute       -> revoke (kill switch)
 * Always answers 200 for handled/ignored events so Stripe stops retrying; only
 * a bad signature or a processing error returns non-2xx.
 */
class StripeWebhookController extends AbstractController
{
    #[Route('/webhook/stripe', name: 'app_stripe_webhook', methods: ['POST'])]
    public function handle(
        Request $request,
        StripeService $stripe,
        SubscriptionService $subscriptions,
        LoggerInterface $logger,
    ): Response {
        try {
            $event = $stripe->constructEvent(
                $request->getContent(),
                (string) $request->headers->get('Stripe-Signature', ''),
            );
        } catch (\Throwable $e) {
            $logger->warning('Stripe webhook signature verification failed: ' . $e->getMessage());

            return new Response('invalid signature', Response::HTTP_BAD_REQUEST);
        }

        $object = $event->data->object;
        $log = ['event' => $event->id, 'type' => $event->type];

        try {
            switch ($event->type) {
                case 'checkout.session.completed':
                    if (($object->mode ?? null) === 'subscription' && !empty($object->subscription)) {
                        $subscription = $stripe->retrieveSubscription((string) $object->subscription);
                        $license = $subscriptions->provision(
                            (string) $object->customer,
                            $subscription->id,
                            (string) $subscription->status,
                        );
                        if ($license === null) {
                            $logger->warning('Stripe webhook: no user for customer, license not provisioned', $log + ['customer' => (string) $object->customer]);
                        } else {
                            $logger->info('Stripe webhook: license provisioned', $log + ['license' => $license->getLicenseId()]);
                        }
                    }
                    break;

                case 'customer.subscription.updated':
                    $subscriptions->syncStatus((string) $object->id, (string) $object->status);
                    $logger->info('Stripe webhook: subscription status synced', $log + ['status' => (string) $object->status]);
                    break;

                case 'customer.subscription.deleted':
                    $subscriptions->syncStatus((string) $object->id, 'canceled');
                    $logger->info('Stripe webhook: subscription canceled', $log);
                    break;

                case 'charge.refunded':
                case 'charge.dispute.created':
                    if (!empty($object->customer)) {
                        $subscriptions->revokeByCustomer((string) $object->customer);
                        $logger->info('Stripe webhook: licenses revoked for customer', $log + ['customer' => (string) $object->customer]);
                    }
                    break;

                default:
                    $logger->debug('Stripe webhook: ignored event', $log);
            }
        } catch (\Throwable $e) {
            $logger->error('Stripe webhook processing error: ' . $e->getMessage(), $log);

            return new Response('processing error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response('ok', Response::HTTP_OK);
    }
}

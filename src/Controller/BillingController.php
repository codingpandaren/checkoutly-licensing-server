<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\StripeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class BillingController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(STRIPE_PRICE_PRO_MONTHLY)%')]
        private readonly string $priceMonthly,
        #[Autowire('%env(STRIPE_PRICE_PRO_YEARLY)%')]
        private readonly string $priceYearly,
    ) {
    }

    #[Route('/billing/subscribe', name: 'app_billing_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, StripeService $stripe, UserRepository $users): Response
    {
        if (!$this->isCsrfTokenValid('billing_subscribe', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $plan = (string) $request->request->get('plan');
        $priceId = $plan === 'yearly' ? $this->priceYearly : $this->priceMonthly;
        if ($priceId === '') {
            $this->addFlash('error', 'That plan is not configured yet. Please try again later.');

            return $this->redirectToRoute('app_dashboard');
        }

        /** @var User $user */
        $user = $this->getUser();

        $customerId = $stripe->ensureCustomer($user);
        if ($user->getStripeCustomerId() !== $customerId) {
            $user->setStripeCustomerId($customerId);
            $users->save($user);
        }

        $session = $stripe->createCheckoutSession(
            $customerId,
            $priceId,
            $this->generateUrl('app_dashboard', ['checkout' => 'success'], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->generateUrl('app_dashboard', ['checkout' => 'cancel'], UrlGeneratorInterface::ABSOLUTE_URL),
        );

        return new RedirectResponse($session->url);
    }

    #[Route('/billing/portal', name: 'app_billing_portal', methods: ['POST'])]
    public function portal(Request $request, StripeService $stripe): Response
    {
        if (!$this->isCsrfTokenValid('billing_portal', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $customerId = $user->getStripeCustomerId();
        if ($customerId === null || $customerId === '') {
            $this->addFlash('error', 'No billing account found yet. Subscribe first.');

            return $this->redirectToRoute('app_dashboard');
        }

        $session = $stripe->createBillingPortalSession(
            $customerId,
            $this->generateUrl('app_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
        );

        return new RedirectResponse($session->url);
    }
}

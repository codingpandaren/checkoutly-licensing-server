<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    /**
     * Display pricing. These are marketing figures only - the actual charge is
     * driven by the Stripe price IDs (STRIPE_PRICE_PRO_MONTHLY / _YEARLY); keep
     * these in sync with those prices.
     */
    private const PRICING = [
        'currency' => '€',
        'monthly' => '19.99',
        'yearly' => '199.99',
        'yearly_per_month' => '16.67',
        'yearly_saving' => '39.89',
    ];

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('dashboard/index.html.twig', [
            'licenses' => $user->getLicenses(),
            'pricing' => self::PRICING,
        ]);
    }
}

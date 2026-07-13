<?php

declare(strict_types=1);

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Kicks off the OAuth redirect for each provider. The matching /check routes
 * carry no body - the firewall's authenticator intercepts them on return.
 */
class ConnectController extends AbstractController
{
    #[Route('/connect/google', name: 'connect_google', methods: ['GET'])]
    public function google(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry->getClient('google')->redirect(['email', 'profile'], []);
    }

    #[Route('/connect/google/check', name: 'connect_google_check', methods: ['GET'])]
    public function googleCheck(): void
    {
    }

    #[Route('/connect/facebook', name: 'connect_facebook', methods: ['GET'])]
    public function facebook(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry->getClient('facebook')->redirect(['email', 'public_profile'], []);
    }

    #[Route('/connect/facebook/check', name: 'connect_facebook_check', methods: ['GET'])]
    public function facebookCheck(): void
    {
    }

    #[Route('/connect/apple', name: 'connect_apple', methods: ['GET'])]
    public function apple(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry->getClient('apple')->redirect(['name', 'email'], []);
    }

    /**
     * Apple returns the callback as a form_post (cross-site POST), so this route
     * accepts POST as well as GET - unlike the Google/Facebook GET redirects.
     */
    #[Route('/connect/apple/check', name: 'connect_apple_check', methods: ['GET', 'POST'])]
    public function appleCheck(): void
    {
    }
}

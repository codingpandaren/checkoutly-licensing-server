<?php

declare(strict_types=1);

namespace App\Security;

use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;

class GoogleAuthenticator extends OAuthAuthenticator
{
    protected function clientKey(): string
    {
        return 'google';
    }

    protected function checkRoute(): string
    {
        return 'connect_google_check';
    }

    protected function identify(OAuth2ClientInterface $client, AccessToken $token): array
    {
        /** @var GoogleUser $owner */
        $owner = $client->fetchUserFromToken($token);

        return [
            'provider' => 'google',
            'id' => (string) $owner->getId(),
            'email' => (string) $owner->getEmail(),
            'name' => $owner->getName(),
        ];
    }
}

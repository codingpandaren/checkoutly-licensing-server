<?php

declare(strict_types=1);

namespace App\Security;

use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use League\OAuth2\Client\Provider\AppleResourceOwner;
use League\OAuth2\Client\Token\AccessToken;

class AppleAuthenticator extends OAuthAuthenticator
{
    protected function clientKey(): string
    {
        return 'apple';
    }

    protected function checkRoute(): string
    {
        return 'connect_apple_check';
    }

    protected function identify(OAuth2ClientInterface $client, AccessToken $token): array
    {
        /** @var AppleResourceOwner $owner */
        $owner = $client->fetchUserFromToken($token);

        $name = trim(($owner->getFirstName() ?? '') . ' ' . ($owner->getLastName() ?? ''));

        return [
            'provider' => 'apple',
            'id' => (string) $owner->getId(),
            'email' => (string) $owner->getEmail(),
            'name' => $name !== '' ? $name : null,
        ];
    }
}

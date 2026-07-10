<?php

declare(strict_types=1);

namespace App\Security;

use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;

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

    protected function mapOwner(ResourceOwnerInterface $owner): array
    {
        /* @var GoogleUser $owner */
        return [
            'provider' => 'google',
            'id' => (string) $owner->getId(),
            'email' => (string) $owner->getEmail(),
            'name' => $owner->getName(),
        ];
    }
}

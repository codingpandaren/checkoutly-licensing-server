<?php

declare(strict_types=1);

namespace App\Security;

use League\OAuth2\Client\Provider\FacebookUser;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;

class FacebookAuthenticator extends OAuthAuthenticator
{
    protected function clientKey(): string
    {
        return 'facebook';
    }

    protected function checkRoute(): string
    {
        return 'connect_facebook_check';
    }

    protected function mapOwner(ResourceOwnerInterface $owner): array
    {
        /* @var FacebookUser $owner */
        return [
            'provider' => 'facebook',
            'id' => (string) $owner->getId(),
            'email' => (string) $owner->getEmail(),
            'name' => $owner->getName(),
        ];
    }
}

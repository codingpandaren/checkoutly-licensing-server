<?php

declare(strict_types=1);

namespace App\Security;

use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use League\OAuth2\Client\Token\AccessToken;

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

    /**
     * Fetch only id/name/email directly from the Graph API. The league provider's
     * default resource-owner request also asks for permission-gated fields
     * (hometown, picture, …) which newer Graph versions reject, taking the whole
     * response — email included — down with them.
     */
    protected function identify(OAuth2ClientInterface $client, AccessToken $token): array
    {
        $provider = $client->getOAuth2Provider();
        $url = 'https://graph.facebook.com/v21.0/me?fields=id,name,email&access_token='
            . urlencode($token->getToken());

        /** @var array<string, mixed> $data */
        $data = $provider->getParsedResponse($provider->getRequest('GET', $url));

        return [
            'provider' => 'facebook',
            'id' => (string) ($data['id'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'name' => isset($data['name']) ? (string) $data['name'] : null,
        ];
    }
}

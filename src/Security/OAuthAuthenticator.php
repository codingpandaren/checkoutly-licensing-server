<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\OAuthUserProvisioner;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Shared OAuth login flow. Each provider subclass declares its client key,
 * callback route and how to read {id, email, name} from the access token; this
 * base handles the token exchange, the find-or-create user badge and the
 * success/failure redirects.
 */
abstract class OAuthAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        protected readonly ClientRegistry $clientRegistry,
        protected readonly RouterInterface $router,
        protected readonly OAuthUserProvisioner $provisioner,
    ) {
    }

    abstract protected function clientKey(): string;

    abstract protected function checkRoute(): string;

    /**
     * @return array{provider: string, id: string, email: string, name: ?string}
     */
    abstract protected function identify(OAuth2ClientInterface $client, AccessToken $token): array;

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === $this->checkRoute();
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient($this->clientKey());
        $data = $this->identify($client, $this->fetchAccessToken($client));

        if ($data['email'] === '') {
            throw new CustomUserMessageAuthenticationException('We could not get an email address from your account, which we need to manage your license. Please sign in with Google, or add a confirmed email to your account and try again.');
        }

        return new SelfValidatingPassport(
            new UserBadge($data['email'], fn (): object => $this->provisioner->provision($data))
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add(
            'error',
            strtr($exception->getMessageKey(), $exception->getMessageData())
        );

        return new RedirectResponse($this->router->generate('app_login'));
    }
}

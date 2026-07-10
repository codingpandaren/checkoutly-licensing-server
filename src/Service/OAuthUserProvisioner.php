<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Turns an OAuth identity into a persisted User. Email is the account identity:
 * signing in with a different provider but the same verified email resolves to
 * the same account (and one license belongs to one email), so we never create a
 * duplicate account for the same person across Google/Facebook.
 */
class OAuthUserProvisioner
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @param array{provider: string, id: string, email: string, name: ?string} $data
     */
    public function provision(array $data): User
    {
        $user = $this->users->findOneByEmail($data['email']);
        if (!$user instanceof User) {
            $user = (new User())->setEmail($data['email']);
        }

        $user->setOauthProvider($data['provider']);
        $user->setOauthId($data['id']);
        if ($data['name'] !== null && $data['name'] !== '') {
            $user->setDisplayName($data['name']);
        }

        $this->users->save($user);

        return $user;
    }
}

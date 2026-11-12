<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The user provider of the application.
 *
 * Symfony 7 removed the generic `UserChecker`, which used to refuse the
 * accounts flagged as disabled. The check is therefore made here, where the
 * account is loaded: a disabled account is reported as **not found**, exactly
 * like an unknown email address — the visitor learns nothing about the
 * accounts that exist.
 */
class ActiveUserProvider extends EntityUserProvider
{
    public function loadUserByIdentifier(string $identifier/* , ?array $attributes = null */): UserInterface
    {
        return $this->assertActive(parent::loadUserByIdentifier($identifier));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->assertActive(parent::refreshUser($user));
    }

    /**
     * @throws UserNotFoundException when the account is disabled
     */
    private function assertActive(UserInterface $user): UserInterface
    {
        if ($user instanceof User && !$user->isActive()) {
            throw new UserNotFoundException(sprintf('The account "%s" is disabled.', $user->getUserIdentifier()));
        }

        return $user;
    }
}

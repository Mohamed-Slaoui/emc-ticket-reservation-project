<?php

namespace App\Service\User;

use App\Entity\User;
use App\Exception\AccountCannotBeDeletedException;
use App\Repository\ReservationRepository;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Account lifecycle: creation, updates and password changes.
 *
 * The password is never handled by the controller — it is hashed here, with
 * the hasher configured in `config/packages/security.yaml`, and the plain
 * value never reaches the entity.
 */
class UserService
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $users,
        private readonly ValidatorInterface $validator,
        private readonly ReservationRepository $reservations,
    ) {
    }

    /**
     * Creates an account with the given plain password.
     *
     * @param list<string> $roles Business roles (`ROLE_CUSTOMER`, …).
     *
     * @throws \InvalidArgumentException when the data is invalid
     */
    public function create(
        string $email,
        string $plainPassword,
        string $firstName,
        string $lastName,
        array $roles = [User::ROLE_CUSTOMER],
        ?string $phone = null,
    ): User {
        $user = new User($email, '', $firstName, $lastName, $roles, true, $phone);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->validate($user);

        $this->users->save($user);

        return $user;
    }

    /**
     * Updates the profile of an account. The password is left untouched.
     *
     * @param list<string> $roles
     *
     * @throws \InvalidArgumentException when the data is invalid
     */
    public function update(User $user, string $firstName, string $lastName, array $roles, ?string $phone = null): void
    {
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPhone($phone);
        $user->setRoles($roles);

        $this->validate($user);

        $this->users->save($user);
    }

    /**
     * Sets a new password on an account.
     *
     * @throws \InvalidArgumentException when the data is invalid
     */
    public function resetPassword(User $user, string $plainPassword): void
    {
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->validate($user);

        $this->users->save($user);
    }

    /**
     * Enables or disables an account. A disabled account cannot log in.
     */
    public function setActive(User $user, bool $active): void
    {
        $user->setIsActive($active);

        $this->users->save($user);
    }

    /**
     * Deletes an account that has never been used.
     *
     * The history is preserved: an account that already appears on a
     * reservation can only be **disabled**, never deleted — a sale must
     * always keep the name of the person who made it and of the customer who
     * bought it (docs/MODELE.md §1.3).
     *
     * @param User|null $actor The signed-in administrator, when there is one.
     *
     * @throws AccountCannotBeDeletedException when the account must be kept
     */
    public function delete(User $user, ?User $actor = null): void
    {
        if (null !== $actor && $user->getId() === $actor->getId()) {
            throw new AccountCannotBeDeletedException('You cannot delete the account you are signed in with.');
        }

        if ($this->reservations->countForUser($user) > 0) {
            throw new AccountCannotBeDeletedException(
                'This account appears on a reservation: disable it instead of deleting it, so the sales history is preserved.',
            );
        }

        if ($user->hasRole(User::ROLE_ADMIN) && $this->users->countAdministrators() <= 1) {
            throw new AccountCannotBeDeletedException('The last administrator cannot be deleted: the venue would be left without anyone able to configure it.');
        }

        $this->users->remove($user);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validate(User $user): void
    {
        $violations = $this->validator->validate($user);

        if (count($violations) > 0) {
            throw new \InvalidArgumentException(sprintf(
                'The account is invalid: %s',
                (string) $violations->get(0)->getMessage(),
            ));
        }
    }
}

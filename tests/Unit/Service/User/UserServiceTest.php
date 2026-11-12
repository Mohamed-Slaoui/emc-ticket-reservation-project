<?php

namespace App\Tests\Unit\Service\User;

use App\Entity\User;
use App\Service\User\UserService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The account lifecycle: the password is hashed, the roles are explicit and
 * the plain password never reaches the entity.
 */
class UserServiceTest extends TestCase
{
    private UserService $service;

    private User $user;

    protected function setUp(): void
    {
        $this->user = new User('owner@venue.test', 'hash', 'Karim', 'Ouazzani');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-password');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new \Symfony\Component\Validator\ConstraintViolationList());

        $this->service = new UserService(
            $hasher,
            $this->createMock(\App\Repository\UserRepository::class),
            $validator,
            $this->createMock(\App\Repository\ReservationRepository::class),
        );
    }

    public function test_the_password_is_hashed_and_never_stored_in_clear(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with(self::anything(), 'a-very-secret-password')
            ->willReturn('hashed-password');

        $service = new UserService(
            $hasher,
            $this->createMock(\App\Repository\UserRepository::class),
            $this->createMock(ValidatorInterface::class),
            $this->createMock(\App\Repository\ReservationRepository::class),
        );

        $created = $service->create(
            'new@venue.test',
            'a-very-secret-password',
            'Nadia',
            'Bennani',
            [User::ROLE_BOX_OFFICE],
        );

        self::assertSame('hashed-password', $created->getPassword());
        self::assertNotSame('a-very-secret-password', $created->getPassword());
        self::assertSame('Nadia', $created->getFirstName());
        self::assertTrue($created->hasRole(User::ROLE_BOX_OFFICE));
    }

    public function test_the_roles_are_explicit_and_cumulative(): void
    {
        $created = new User('a@venue.test', 'h', 'A', 'B', [User::ROLE_ADMIN]);

        // The business role is stored as-is…
        self::assertSame([User::ROLE_ADMIN], $created->getBusinessRoles());
        // …and ROLE_USER is always granted, but never stored.
        self::assertContains(User::ROLE_USER, $created->getRoles());
        self::assertNotContains(User::ROLE_USER, $created->getBusinessRoles());
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown role');

        new User('a@venue.test', 'h', 'A', 'B', ['ROLE_SUPERUSER']);
    }

    public function test_the_role_user_cannot_be_stored(): void
    {
        $user = new User('a@venue.test', 'h', 'A', 'B', [User::ROLE_USER]);

        self::assertSame([], $user->getBusinessRoles());
        self::assertSame([User::ROLE_USER], $user->getRoles());
    }
}

<?php

namespace App\Entity;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Account carried by the three roles of the system (docs/DECISIONS.md §3.8):
 * CUSTOMER (buys online), BOX_OFFICE (sells at the counter), ADMIN
 * (configures, cancels, refunds). A user with no explicit role is a customer.
 */
#[ORM\Entity(repositoryClass: \App\Repository\UserRepository::class)]
#[ORM\Table(name: 'user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_CUSTOMER = 'ROLE_CUSTOMER';
    public const ROLE_BOX_OFFICE = 'ROLE_BOX_OFFICE';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    /**
     * The roles an account may carry — `ROLE_USER` is granted implicitly and
     * is therefore not offered as a choice.
     */
    public const AVAILABLE_ROLES = [
        self::ROLE_CUSTOMER,
        self::ROLE_BOX_OFFICE,
        self::ROLE_ADMIN,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $email;

    /**
     * Never exposed, never validated in plain: fixtures and forms pass an
     * already hashed value through hashPassword()/the password hasher.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: 'string', length: 100)]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 100)]
    private string $lastName;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $phone = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $email,
        string $hashedPassword,
        string $firstName,
        string $lastName,
        array $roles = [self::ROLE_CUSTOMER],
        bool $isActive = true,
        ?string $phone = null,
    ) {
        $this->email = $email;
        $this->passwordHash = $hashedPassword;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->phone = $phone;
        $this->createdAt = new \DateTimeImmutable();
        $this->roles = [];
        $this->setRoles($roles);
        $this->isActive = $isActive;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /* ------------------------- Security layer ------------------------- */

    /**
     * The roles granted to the user.
     *
     * `ROLE_USER` is always present: Symfony does **not** add it by itself
     * for a custom user entity, and any `access_control` rule or voter
     * relying on it would otherwise deny every account of the application.
     * The business roles (CUSTOMER, BOX_OFFICE, ADMIN) stay explicit and are
     * never cumulative.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique(array_merge([self::ROLE_USER], $this->roles)));
    }

    /**
     * The business roles only — `ROLE_USER` excluded.
     *
     * @return list<string>
     */
    public function getBusinessRoles(): array
    {
        return array_values(array_diff($this->roles, [self::ROLE_USER]));
    }

    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
    }

    /**
     * Replaces the business roles of the account.
     *
     * `ROLE_USER` is implicit and is therefore ignored here: it is never
     * stored, only granted by {@see self::getRoles()}.
     *
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = [];

        foreach ($roles as $role) {
            $this->addRole($role);
        }
    }

    public function addRole(string $role): void
    {
        if (self::ROLE_USER === $role) {
            // Implicit — never stored.
            return;
        }

        if (!\in_array($role, self::AVAILABLE_ROLES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown role "%s".', $role));
        }
        if (!\in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase: the plain password is never stored on the entity.
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /**
     * Stores an already hashed password.
     *
     * The plain password must never be passed here — use the password
     * hasher, through {@see \App\Service\User\UserService}.
     */
    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }
}

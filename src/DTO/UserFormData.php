<?php

namespace App\DTO;

use App\Entity\User;

/**
 * The data carried by the account form.
 *
 * The form does not map the `User` entity directly: the roles are stored as
 * a JSON array and the plain password must never reach the entity. A
 * dedicated model keeps the mapping explicit and the form testable.
 */
final class UserFormData
{
    public ?string $email = null;

    public string $firstName = '';

    public string $lastName = '';

    public ?string $phone = null;

    /**
     * The business role of the account.
     *
     * A single role, because the roles are cumulative: the administrator
     * already inherits the box-office and the customer permissions.
     */
    public ?string $role = User::ROLE_CUSTOMER;

    public ?string $plainPassword = null;

    public bool $isActive = true;
}

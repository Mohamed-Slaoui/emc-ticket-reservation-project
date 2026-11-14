<?php

namespace App\DTO;

/**
 * The values of the self sign-up form. The account is always a customer —
 * box-office and administrator accounts are created by the administration.
 */
final class RegistrationFormData
{
    public string $email = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $password = '';

    public string $passwordConfirm = '';
}

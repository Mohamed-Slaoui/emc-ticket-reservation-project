<?php

namespace App\Exception;

/**
 * Raised when an account cannot be deleted.
 *
 * The message is written for the administrator: it explains what to do
 * instead of deleting.
 */
class AccountCannotBeDeletedException extends \RuntimeException
{
}

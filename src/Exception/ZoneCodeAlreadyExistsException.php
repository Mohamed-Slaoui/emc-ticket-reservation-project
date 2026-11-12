<?php

namespace App\Exception;

/**
 * Raised when a zone code is already used by another zone of the same
 * configuration.
 */
class ZoneCodeAlreadyExistsException extends \RuntimeException
{
}

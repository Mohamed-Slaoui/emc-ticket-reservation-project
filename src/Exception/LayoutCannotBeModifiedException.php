<?php

namespace App\Exception;

/**
 * Raised when a seating layout that is already used by a performance is
 * edited or deleted.
 *
 * Once a performance refers to a configuration, the configuration is frozen:
 * any evolution creates a new version.
 */
class LayoutCannotBeModifiedException extends \RuntimeException
{
}

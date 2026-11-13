<?php

namespace App\Exception;

/**
 * Raised when the chosen tariff may not be applied in the chosen channel —
 * the online channel sells the full tariff only (docs/DECISIONS.md Q5) — or
 * when the group rule is not met (BR-12: at least 5 seats, all at the group rate).
 */
class TariffNotAuthorizedException extends ReservationEngineException
{
}

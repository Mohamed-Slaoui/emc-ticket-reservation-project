<?php

namespace App\Enum;

/**
 * Payment method: simulated online payment, cash or terminal at the box office.
 */
enum PaymentMethod: string
{
    case Online = 'ONLINE';
    case Cash = 'CASH';
    case CardTerminal = 'CARD_TERMINAL';
}

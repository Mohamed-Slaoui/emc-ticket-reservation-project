<?php

namespace App\Service\Payment;

/**
 * A payment provider — the thing that authorizes a payment.
 *
 * No online provider is imposed (docs/DECISIONS.md A1): the MVP ships a
 * **simulated** provider, and this interface is where a real one would plug
 * in. The application never talks to a provider directly — it always goes
 * through this contract, so the rest of the code does not change when a
 * provider does.
 */
interface PaymentProvider
{
    /**
     * Asks the provider to authorize the payment.
     *
     * @param string               $amount   The amount in the smallest unit of the currency, as a decimal string.
     * @param string               $currency The currency (MAD).
     * @param array<string, mixed> $details  What the customer gave: the card holder, the card number, the expiry…
     */
    public function authorize(string $amount, string $currency, array $details): PaymentOutcome;
}

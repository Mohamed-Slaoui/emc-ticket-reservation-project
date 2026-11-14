<?php

namespace App\Service\Payment;

/**
 * The simulated provider (docs/DECISIONS.md A1 — no online provider is
 * imposed, the MVP provides a simulated payment).
 *
 * It approves every payment, **except** the test cards: a card number
 * ending in `0000` is refused. That gives the two failure paths a
 * deterministic way to be exercised — a refused payment (the hold stays
 * until it expires) and a late payment (captured after the expiry, then
 * refunded in full).
 */
class SimulatedPaymentProvider implements PaymentProvider
{
    /** The suffix of the card numbers the simulator refuses. */
    public const REFUSED_CARD_SUFFIX = '0000';

    public function authorize(string $amount, string $currency, array $details): PaymentOutcome
    {
        $reference = 'sim_'.bin2hex(random_bytes(8));

        $number = str_replace(' ', '', strtoupper((string) ($details['cardNumber'] ?? '')));

        if ('' !== $number && str_ends_with($number, self::REFUSED_CARD_SUFFIX)) {
            return PaymentOutcome::refused($reference, 'The payment was refused by the bank.');
        }

        if ('' === $number) {
            return PaymentOutcome::refused($reference, 'The card details are incomplete.');
        }

        return PaymentOutcome::approved($reference, sprintf('The payment of %s %s is approved.', $amount, $currency));
    }
}

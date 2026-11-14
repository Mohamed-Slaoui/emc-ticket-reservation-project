<?php

namespace App\Service\Payment;

/**
 * The answer of a payment provider: approved or refused, with the reference
 * the provider gives the transaction.
 */
final class PaymentOutcome
{
    private function __construct(
        public readonly bool $approved,
        public readonly string $reference,
        public readonly string $message,
    ) {
    }

    public static function approved(string $reference, string $message = 'The payment is approved.'): self
    {
        return new self(true, $reference, $message);
    }

    public static function refused(string $reference, string $message): self
    {
        return new self(false, $reference, $message);
    }
}

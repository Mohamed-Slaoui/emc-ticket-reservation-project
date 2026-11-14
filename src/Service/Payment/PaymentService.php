<?php

namespace App\Service\Payment;

use App\Entity\OutboxMessage;
use App\Entity\Payment;
use App\Entity\Performance;
use App\Entity\Refund;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Enum\ReservationStatus;
use App\Enum\SalesChannel;
use App\Enum\TariffCode;
use App\Service\Reservation\ReservationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The payments (docs/WORKFLOWS.md WF-1 §2.3).
 *
 * An online payment goes through a {@see PaymentProvider} — the MVP ships the
 * simulated one (docs/DECISIONS.md A1: no online provider is imposed). The
 * counter takes the money directly: cash or card terminal, no provider.
 *
 * Three outcomes, exactly as the workflow describes them:
 *
 * 1. **approved before the expiry** — the reservation is confirmed, the seats
 *    are SOLD, a captured payment is recorded, one ticket is issued per item
 *    and one outbox message per ticket is written (INV-03, INV-06);
 * 2. **refused** — the attempt is recorded as FAILED and the reservation
 *    **stays held**: the seats are not released before the hold expires
 *    (BR-05), and the customer may retry;
 * 3. **approved after the expiry** — the capture is recorded, it is refunded
 *    in full and **no ticket is issued** (BR-06).
 */
class PaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReservationService $reservations,
        private readonly PaymentProvider $provider,
    ) {
    }

    /**
     * Sells seats at the box-office counter — hold, confirm, payment and
     * tickets in **one** transaction, so a failed sale never leaves a lone
     * hold behind.
     *
     * @param list<int> $inventoryPlaceIds
     *
     * @throws \App\Exception\SeatNotAvailableException when a selected seat is no longer on sale
     * @throws \App\Exception\TariffNotAuthorizedException when the tariff may not be applied
     * @throws \App\Exception\ReservationEngineException when the sale cannot be completed
     */
    public function sellAtCounter(
        Performance $performance,
        array $inventoryPlaceIds,
        TariffCode $tariffCode,
        User $createdBy,
        ?User $customer = null,
        bool $eligibilityVerified = false,
        PaymentMethod $method = PaymentMethod::Cash,
    ): Reservation {
        return $this->entityManager->wrapInTransaction(
            function () use ($performance, $inventoryPlaceIds, $tariffCode, $createdBy, $customer, $eligibilityVerified, $method): Reservation {
                $reservation = $this->reservations->hold(
                    $performance,
                    $inventoryPlaceIds,
                    $tariffCode,
                    SalesChannel::BoxOffice,
                    $createdBy,
                    $customer,
                    $eligibilityVerified,
                );

                $this->complete($reservation, $method);

                return $reservation;
            },
        );
    }

    /**
     * Takes the money at the counter — cash or card terminal, no provider.
     *
     * @throws \LogicException when the reservation cannot be confirmed — the
     *                         hold has expired (INV-03)
     */
    public function complete(Reservation $reservation, PaymentMethod $method = PaymentMethod::Cash): ?Payment
    {
        // The engine runs its own transaction; Doctrine reuses the connection,
        // so both still see one atomic commit.
        return $this->entityManager->wrapInTransaction(function () use ($reservation, $method): ?Payment {
            $this->reservations->confirm($reservation);

            // A free reservation (invitation tariff) issues no payment —
            // there is nothing to capture.
            if (bccomp($reservation->getTotalAmount(), '0.00', 2) === 0) {
                $this->issueTickets($reservation);
                $this->entityManager->flush();

                return null;
            }

            $payment = new Payment(
                $reservation,
                $reservation->getTotalAmount(),
                $reservation->getCurrency(),
                $method,
            );
            $payment->markSucceeded('sim_'.bin2hex(random_bytes(8)));
            $this->entityManager->persist($payment);

            $this->issueTickets($reservation);

            $this->entityManager->flush();

            return $payment;
        });
    }

    /**
     * Takes the money online, through the provider.
     *
     * The three outcomes of the workflow (docs/WORKFLOWS.md WF-1 §2.3):
     *
     * - **approved** and the hold is alive: the reservation is confirmed, the
     *   seats are sold, the tickets are issued;
     * - **refused**: the attempt is recorded as FAILED and the reservation
     *   stays held — the customer may retry until the hold expires (BR-05);
     * - **approved after the expiry**: the capture is recorded, refunded in
     *   full, and no ticket is issued (BR-06).
     *
     * @param array<string, mixed> $details What the customer gave (the card details).
     */
    public function pay(Reservation $reservation, array $details = []): PaymentOutcome
    {
        return $this->entityManager->wrapInTransaction(function () use ($reservation, $details): PaymentOutcome {
            $payment = new Payment(
                $reservation,
                $reservation->getTotalAmount(),
                $reservation->getCurrency(),
                PaymentMethod::Online,
            );
            $this->entityManager->persist($payment);
            $this->entityManager->flush();

            $outcome = $this->provider->authorize($payment->getAmount(), $payment->getCurrency(), $details);

            if (!$outcome->approved) {
                // BR-05 — a failed payment releases the seat at expiry, not
                // immediately: the reservation stays held.
                $payment->markFailed($outcome->reference);
                $this->entityManager->flush();

                return $outcome;
            }

            $payment->markSucceeded($outcome->reference);

            if (ReservationStatus::Held !== $reservation->getStatus() || $reservation->isExpired()) {
                // BR-06 — a late payment is refunded in full and issues no
                // ticket; the seat may already belong to somebody else.
                // The refund registers itself on the payment (it is what
                // makes the cap enforceable in memory).
                $refund = new Refund($payment, $payment->getAmount(), 'Late payment: the hold had expired.');
                $this->entityManager->persist($refund);
                $this->entityManager->flush();

                return PaymentOutcome::approved($outcome->reference, 'The payment arrived after the hold expired: it is refunded in full.');
            }

            $this->reservations->confirm($reservation);
            $this->issueTickets($reservation);
            $this->entityManager->flush();

            return $outcome;
        });
    }

    /**
     * Issues one ticket per item (INV-06) and one outbox message per
     * ticket — the Google Sheets synchronisation picks them up later
     * (docs/WORKFLOWS.md §7.3).
     */
    private function issueTickets(Reservation $reservation): void
    {
        foreach ($reservation->getItems() as $item) {
            $ticket = new Ticket($item);
            $this->entityManager->persist($ticket);
            $this->entityManager->flush();

            $this->entityManager->persist(new OutboxMessage(
                'ticket.issued',
                'ticket',
                (int) $ticket->getId(),
                [
                    'ticketNumber' => $ticket->getTicketNumber(),
                    'reservation' => $reservation->getReference(),
                    'performance' => $reservation->getPerformance()->getId(),
                    'startsAt' => $reservation->getPerformance()->getStartsAt()->format(DATE_ATOM),
                    'seat' => $item->getPerformancePlace()->getLayoutPlace()->getPlace()->getCode(),
                    'tariff' => $item->getTariffNameSnapshot(),
                    'amount' => $item->getUnitPriceSnapshot(),
                ],
                'ticket-'.(int) $ticket->getId(),
                $reservation,
            ));
        }
    }
}

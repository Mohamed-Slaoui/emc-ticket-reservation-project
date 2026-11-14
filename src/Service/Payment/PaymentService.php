<?php

namespace App\Service\Payment;

use App\Entity\Payment;
use App\Entity\Performance;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Enum\SalesChannel;
use App\Enum\TariffCode;
use App\Service\Reservation\ReservationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The simulated online payment (docs/DECISIONS.md A1 — no online provider is
 * imposed; the MVP provides a simulated payment, plus cash / terminal at the
 * box office).
 *
 * Completing a payment does three things, in one transaction:
 *
 * 1. the reservation is confirmed (and the seats SOLD) — INV-03 applies;
 * 2. a captured `Payment` is recorded (method ONLINE for the shop, CASH or
 *    CARD_TERMINAL at the counter, status SUCCEEDED);
 * 3. one `Ticket` is issued per item — one item = one seat = one ticket
 *    (INV-06).
 *
 * The simulator approves. A real provider would notify us asynchronously;
 * the interface and the entity API (`markSucceeded` / `markFailed`, refunds)
 * are already in place for that day.
 */
class PaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReservationService $reservations,
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
     * Completes a payment — online (simulated), or cash / card terminal at the
     * box-office counter (docs/DECISIONS.md A1).
     *
     * @throws \LogicException when the reservation cannot be confirmed — the
     *                         hold has expired (INV-03)
     */
    public function complete(Reservation $reservation, PaymentMethod $method = PaymentMethod::Online): Payment
    {
        // The engine runs its own transaction; Doctrine reuses the connection,
        // so both still see one atomic commit.
        return $this->entityManager->wrapInTransaction(function () use ($reservation, $method): Payment {
            $this->reservations->confirm($reservation);

            $payment = new Payment(
                $reservation,
                $reservation->getTotalAmount(),
                $reservation->getCurrency(),
                $method,
            );
            $payment->markSucceeded('sim_'.bin2hex(random_bytes(8)));
            $this->entityManager->persist($payment);

            foreach ($reservation->getItems() as $item) {
                $this->entityManager->persist(new Ticket($item));
            }

            $this->entityManager->flush();

            return $payment;
        });
    }
}

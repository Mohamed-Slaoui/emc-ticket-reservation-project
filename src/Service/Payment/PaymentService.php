<?php

namespace App\Service\Payment;

use App\Entity\Payment;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Enum\PaymentMethod;
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
 * 2. a captured `Payment` is recorded (method ONLINE, status SUCCEEDED);
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
     * Completes an online payment.
     *
     * @throws \LogicException when the reservation cannot be confirmed — the
     *                         hold has expired (INV-03)
     */
    public function complete(Reservation $reservation): Payment
    {
        // The engine runs its own transaction; Doctrine reuses the connection,
        // so both still see one atomic commit.
        return $this->entityManager->wrapInTransaction(function () use ($reservation): Payment {
            $this->reservations->confirm($reservation);

            $payment = new Payment(
                $reservation,
                $reservation->getTotalAmount(),
                $reservation->getCurrency(),
                PaymentMethod::Online,
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

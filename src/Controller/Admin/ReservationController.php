<?php

namespace App\Controller\Admin;

use App\Entity\Reservation;
use App\Exception\ReservationEngineException;
use App\Repository\ReservationRepository;
use App\Repository\TicketRepository;
use App\Service\Booking\PerformanceLabelService;
use App\Service\Reservation\ReservationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The reservations, seen from the administration — a read-only overview.
 * Records are never edited here: the sales are irreversible, and the only
 * writes go through the reservation engine.
 */
#[Route('/admin/reservations')]
class ReservationController extends AbstractController
{
    #[Route('', name: 'admin_reservations', methods: ['GET'])]
    public function index(ReservationRepository $reservations, PerformanceLabelService $labels): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $recent = $reservations->findRecent();

        return $this->render('admin/reservations/index.html.twig', [
            'reservations' => $recent,
            'labels' => $labels->labelsFor(array_map(
                static fn (Reservation $reservation): \App\Entity\Performance => $reservation->getPerformance(),
                $recent,
            )),
        ]);
    }

    #[Route('/{id}', name: 'admin_reservation', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(
        #[MapEntity(id: 'id')] Reservation $reservation,
        TicketRepository $tickets,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/reservations/show.html.twig', [
            'reservation' => $reservation,
            'tickets' => $tickets->findForReservation($reservation),
        ]);
    }

    #[Route('/{id}/cancel', name: 'admin_reservation_cancel', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function cancel(
        Request $request,
        #[MapEntity(id: 'id')] Reservation $reservation,
        ReservationService $reservationService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('cancel_reservation_'.$reservation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_reservation', ['id' => $reservation->getId()]);
        }

        try {
            $reservationService->cancelReservation($reservation, $this->getUser());
            $this->addFlash('success', 'The reservation has been cancelled and its seats released.');
        } catch (ReservationEngineException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_reservation', ['id' => $reservation->getId()]);
    }
}

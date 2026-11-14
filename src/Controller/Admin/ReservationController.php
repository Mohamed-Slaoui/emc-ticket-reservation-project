<?php

namespace App\Controller\Admin;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use App\Repository\TicketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
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
    public function index(ReservationRepository $reservations): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/reservations/index.html.twig', [
            'reservations' => $reservations->findRecent(),
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
}

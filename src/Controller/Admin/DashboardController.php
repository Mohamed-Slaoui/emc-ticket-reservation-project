<?php

namespace App\Controller\Admin;

use App\Entity\BookingPolicy;
use App\Entity\Event;
use App\Entity\Performance;
use App\Entity\Place;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Entity\User;use App\Repository\BookingPolicyRepository;
use App\Repository\EventRepository;
use App\Repository\PerformanceRepository;
use App\Repository\PlaceRepository;
use App\Repository\RoomRepository;
use App\Repository\SeatingLayoutRepository;
use App\Repository\UserRepository;
use App\Repository\VenueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The administration home — an overview of the venue configuration.
 *
 * The operational dashboard (occupancy, revenue) is delivered with the
 * reporting phase; this screen shows what has been configured so far.
 */
#[Route('/admin')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function index(
        VenueRepository $venues,
        RoomRepository $rooms,
        PlaceRepository $places,
        SeatingLayoutRepository $layouts,
        EventRepository $events,
        PerformanceRepository $performances,
        UserRepository $users,
        BookingPolicyRepository $policies,
    ): Response {
        $policy = $policies->findOneBy([]);

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                ['label' => 'Venues', 'value' => $venues->count([]), 'icon' => 'venue'],
                ['label' => 'Rooms', 'value' => $rooms->count([]), 'icon' => 'room'],
                ['label' => 'Seats', 'value' => $places->count([]), 'icon' => 'seat'],
                ['label' => 'Layouts', 'value' => $layouts->count([]), 'icon' => 'layout'],
                ['label' => 'Events', 'value' => $events->count([]), 'icon' => 'calendar'],
                ['label' => 'Performances', 'value' => $performances->count([]), 'icon' => 'clock'],
                ['label' => 'Accounts', 'value' => $users->count([]), 'icon' => 'users'],
            ],
            'hold_duration' => $policy?->getHoldDurationMinutes(),
        ]);
    }
}

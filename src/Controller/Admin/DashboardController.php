<?php

namespace App\Controller\Admin;

use App\Entity\BookingPolicy;
use App\Entity\Event;
use App\Entity\Performance;
use App\Entity\Place;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Entity\User;
use App\Repository\BookingPolicyRepository;
use App\Repository\EventRepository;
use App\Repository\PerformancePlaceRepository;
use App\Repository\PerformanceRepository;
use App\Repository\PlaceRepository;
use App\Repository\PaymentRepository;
use App\Repository\RefundRepository;
use App\Repository\ReservationItemRepository;
use App\Repository\RoomRepository;
use App\Repository\SeatingLayoutRepository;
use App\Repository\UserRepository;
use App\Repository\VenueRepository;
use App\Service\Booking\PerformanceLabelService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The operational dashboard — occupancy, revenue, the ticketing activity.
 *
 * The configuration statistics (venues, rooms, seats, layouts, events,
 * performances, accounts) live at the bottom, as a reminder of what has
 * been configured.
 */
#[Route('/admin')]
class DashboardController extends AbstractController
{
    public const OCCUPANCY_LIMIT = 6;

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
        PerformancePlaceRepository $performancePlaces,
        PaymentRepository $payments,
        RefundRepository $refunds,
        ReservationItemRepository $items,
        PerformanceLabelService $labels,
    ): Response {
        $policy = $policies->findOneBy([]);

        // The most recent published performances, the earliest first.
        $shows = array_slice($performances->findOnSale(), 0, self::OCCUPANCY_LIMIT);

        $occupancy = [];

        foreach ($shows as $show) {
            $byZone = $performancePlaces->occupancy($show);

            $saleable = 0;
            $sold = 0;

            foreach ($byZone as $zone) {
                $saleable += $zone['available'] + $zone['held'] + $zone['sold'];
                $sold += $zone['sold'];
            }

            $occupancy[] = [
                'performance' => $show,
                'byZone' => $byZone,
                'saleable' => $saleable,
                'sold' => $sold,
                'occupancy' => $saleable > 0 ? round(100 * $sold / $saleable, 1) : 0.0,
            ];
        }

        $gross = $payments->sumSucceeded();
        $refundTotal = $refunds->sumSucceeded();
        $net = bcsub($gross, $refundTotal, 2);

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
            'gross' => $gross,
            'refunds' => $refundTotal,
            'net' => $net,
            'revenueByTariff' => $items->revenueByTariff(),
            'occupancy' => $occupancy,
            'labels' => $labels->labelsFor(array_map(static fn (Performance $performance): Performance => $performance, $shows)),
        ]);
    }
}

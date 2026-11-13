<?php

namespace App\Service\Event;

use App\Entity\Event;
use App\Enum\EventStatus;
use App\Enum\EventType;
use App\Exception\EventCannotBePublishedException;
use App\Exception\EventCannotBeDeletedException;
use App\Repository\EventRepository;
use App\Repository\PerformancePlaceRepository;
use App\Repository\PerformanceRepository;
use App\Service\Pricing\PerformancePriceService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The events: a concert, a theatre piece, a conference.
 *
 * An event groups the **performances** — the dated shows of the same programme.
 *
 * An event is published only when it is **complete**: it has a performance, the
 * performance has a configuration, its inventory is generated, and its prices
 * are set. Until then it stays a draft.
 */
class EventService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $events,
        private readonly PerformanceRepository $performances,
        private readonly PerformancePlaceRepository $performancePlaces,
        private readonly PerformancePriceService $performancePrices,
    ) {
    }

    public function create(string $title, EventType $type, ?string $description = null): Event
    {
        $event = new Event($title, $type, $description);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    public function update(Event $event, string $title, EventType $type, ?string $description): void
    {
        $event->setTitle($title);
        $event->setType($type);
        $event->setDescription($description);

        $this->entityManager->flush();
    }

    /**
     * Deletes the event.
     *
     * An event that has performances is **kept**: the shows are the programme
     * itself, and deleting them by hand — one by one — is the deliberate
     * decision of the administrator.
     *
     * @throws EventCannotBeDeletedException when the event has performances
     */
    public function delete(Event $event): void
    {
        $performanceCount = $this->performances->countForEvent($event);

        if ($performanceCount > 0) {
            throw new EventCannotBeDeletedException(sprintf(
                'This event has %d performance%s: delete %s first, or keep the event as it is.',
                $performanceCount,
                $performanceCount > 1 ? 's' : '',
                $performanceCount > 1 ? 'them' : 'it',
            ));
        }

        $this->entityManager->remove($event);
        $this->entityManager->flush();
    }

    /**
     * Publishes the event, once it is complete.
     *
     * @throws EventCannotBePublishedException when a check fails
     */
    public function publish(Event $event): void
    {
        $failed = array_values(array_filter(
            $this->publicationChecks($event),
            static fn (array $check): bool => !$check['ok'],
        ));

        if ([] !== $failed) {
            throw new EventCannotBePublishedException(sprintf(
                'This event cannot be published yet: %s.',
                implode('; ', array_map(static fn (array $check): string => $check['detail'], $failed)),
            ));
        }

        $event->setStatus(EventStatus::Published);

        $this->entityManager->flush();
    }

    /**
     * Archives the event: it leaves the programme, and its history stays.
     */
    public function archive(Event $event): void
    {
        $event->setStatus(EventStatus::Archived);

        $this->entityManager->flush();
    }

    /**
     * The publication checks, one by one.
     *
     * They are shown to the administrator, so the reason a publication is
     * refused is never a mystery.
     *
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public function publicationChecks(Event $event): array
    {
        $performances = $this->performances->findForEvent($event);

        $checks = [];

        $checks[] = [
            'label' => 'The event has a performance',
            'ok' => [] !== $performances,
            'detail' => [] === $performances
                ? 'no performance has been scheduled yet'
                : sprintf('%d performance(s) scheduled', count($performances)),
        ];

        $withLayout = array_filter(
            $performances,
            static fn ($performance): bool => null !== $performance->getSeatingLayout(),
        );

        $checks[] = [
            'label' => 'The performance has a configuration',
            'ok' => [] !== $withLayout,
            'detail' => [] === $withLayout
                ? 'the performance has no seating configuration'
                : 'the configuration is chosen',
        ];

        $withInventory = array_filter(
            $withLayout,
            fn ($performance): bool => $this->performancePlaces->countForPerformance($performance) > 0,
        );

        $checks[] = [
            'label' => 'The inventory is generated',
            'ok' => [] !== $withInventory,
            'detail' => [] === $withInventory
                ? 'the inventory of the performance is empty'
                : 'the seats of the configuration are on sale',
        ];

        // The prices are set per zone and per tariff, for every performance.
        $missingPrices = 0;

        foreach ($performances as $performance) {
            $missingPrices += $this->performancePrices->missingPriceCount($performance);
        }

        $checks[] = [
            'label' => 'The prices are set',
            'ok' => [] !== $performances && 0 === $missingPrices,
            'detail' => 0 === $missingPrices && [] !== $performances
                ? 'the prices are set for every zone and every tariff'
                : sprintf('%d price(s) missing', $missingPrices),
        ];

        return $checks;
    }
}

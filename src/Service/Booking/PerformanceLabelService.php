<?php

namespace App\Service\Booking;

use App\Entity\Performance;
use App\Repository\PerformanceRepository;

/**
 * The label of a performance: the event title, followed by its number when
 * the event has **several** performances — "Soirée de gala - Performance 1".
 * A single-performance event keeps its plain title.
 *
 * The numbering follows the chronological order of the event's performances,
 * whatever the list the label is shown in — a show that is not on sale still
 * takes its number.
 */
class PerformanceLabelService
{
    public function __construct(
        private readonly PerformanceRepository $performances,
    ) {
    }

    /**
     * The label of every performance of the given list.
     *
     * @param list<Performance> $performances
     *
     * @return array<int, string> performance id => label
     */
    public function labelsFor(array $performances): array
    {
        $labels = [];
        $events = [];

        foreach ($performances as $performance) {
            $events[(int) $performance->getEvent()->getId()] = $performance->getEvent();
        }

        foreach ($events as $event) {
            $siblings = $this->performances->findForEvent($event);

            if (count($siblings) < 2) {
                continue;
            }

            foreach ($siblings as $index => $sibling) {
                $labels[(int) $sibling->getId()] = sprintf('%s - Performance %d', $event->getTitle(), $index + 1);
            }
        }

        return $labels;
    }

    /**
     * The label of one performance among the performances of its event.
     */
    public function labelFor(Performance $performance): string
    {
        $title = $performance->getEvent()->getTitle();
        $siblings = $this->performances->findForEvent($performance->getEvent());

        if (count($siblings) < 2) {
            return $title;
        }

        foreach ($siblings as $index => $sibling) {
            if ($sibling->getId() === $performance->getId()) {
                return sprintf('%s - Performance %d', $title, $index + 1);
            }
        }

        return $title;
    }
}

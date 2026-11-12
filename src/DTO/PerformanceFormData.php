<?php

namespace App\DTO;

use App\Entity\SeatingLayout;

/**
 * The data carried by the performance form.
 *
 * The dates are kept as `DateTimeImmutable`: the form converts them.
 */
final class PerformanceFormData
{
    public ?SeatingLayout $layout = null;

    public ?\DateTimeImmutable $startsAt = null;

    public ?\DateTimeImmutable $endsAt = null;

    public ?\DateTimeImmutable $salesOpenAt = null;

    public ?\DateTimeImmutable $salesCloseAt = null;
}

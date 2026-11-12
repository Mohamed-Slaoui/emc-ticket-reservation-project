<?php

namespace App\DTO;

use App\Enum\EventType;

/**
 * The data carried by the event form.
 *
 * The type is kept as its **backing value** (`CONCERT`): the choice list of a
 * form works with scalars, and the enum is rebuilt when the event is saved.
 */
final class EventFormData
{
    public string $title = '';

    public string $type = EventType::Concert->value;

    public ?string $description = null;

    public function getEventType(): EventType
    {
        return EventType::tryFrom($this->type) ?? EventType::Other;
    }
}

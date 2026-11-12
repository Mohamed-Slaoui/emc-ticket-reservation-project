<?php

namespace App\Entity;

use App\Enum\EventStatus;
use App\Enum\EventType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cultural event: concert, theatre, conference…
 * An event can hold one or more performances.
 */
#[ORM\Entity(repositoryClass: \App\Repository\EventRepository::class)]
#[ORM\Table(name: 'event')]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 150)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 20, enumType: EventType::class)]
    private EventType $type;

    #[ORM\Column(type: 'string', length: 20, enumType: EventStatus::class)]
    private EventStatus $status = EventStatus::Draft;

    /** @var Collection<int, Performance> */
    #[ORM\OneToMany(mappedBy: 'event', targetEntity: Performance::class)]
    private Collection $performances;

    public function __construct(string $title, EventType $type, ?string $description = null, EventStatus $status = EventStatus::Draft)
    {
        $this->title = $title;
        $this->type = $type;
        $this->description = $description;
        $this->status = $status;
        $this->performances = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getType(): EventType
    {
        return $this->type;
    }

    public function setType(EventType $type): void
    {
        $this->type = $type;
    }

    public function getStatus(): EventStatus
    {
        return $this->status;
    }

    public function setStatus(EventStatus $status): void
    {
        $this->status = $status;
    }

    /** @return Collection<int, Performance> */
    public function getPerformances(): Collection
    {
        return $this->performances;
    }
}

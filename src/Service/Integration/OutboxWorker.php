<?php

namespace App\Service\Integration;

use App\Entity\OutboxMessage;
use App\Enum\OutboxStatus;
use App\Repository\OutboxMessageRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Drains the transactional outbox (docs/WORKFLOWS.md §7.3, INV-12): one
 * row per ticket, written in the same transaction as the sale. A Google
 * Sheets outage never blocks or cancels a sale — the worker retries later.
 */
class OutboxWorker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OutboxMessageRepository $repository,
        private readonly GoogleSheetsClient $sheets,
    ) {
    }

    /**
     * Processes up to $limit messages. Returns the number sent.
     */
    public function process(int $limit = 50): int
    {
        $now = new \DateTimeImmutable();

        // Requeue failed messages whose delay elapsed.
        foreach ($this->repository->findDueFailed($now, $limit) as $failed) {
            $this->entityManager->wrapInTransaction(function () use ($failed): void {
                $locked = $this->entityManager->find(OutboxMessage::class, $failed->getId(), LockMode::PESSIMISTIC_WRITE);
                if (null === $locked || OutboxStatus::Failed !== $locked->getStatus()) {
                    return;
                }
                $locked->retry();
                $this->entityManager->flush();
            });
        }

        $sent = 0;

        foreach ($this->repository->findClaimable($now, $limit) as $message) {
            // Claim atomically.
            $claimed = false;
            $this->entityManager->wrapInTransaction(function () use ($message, &$claimed): void {
                $locked = $this->entityManager->find(OutboxMessage::class, $message->getId(), LockMode::PESSIMISTIC_WRITE);
                if (null === $locked || OutboxStatus::Pending !== $locked->getStatus()) {
                    return;
                }
                $locked->claim();
                $this->entityManager->flush();
                $claimed = true;
            });

            if (!$claimed) {
                continue;
            }

            $locked = $this->repository->find($message->getId());

            try {
                $this->sheets->appendRow('tickets', $locked->getPayload(), $locked->getIdempotencyKey());
                $this->entityManager->wrapInTransaction(function () use ($locked): void {
                    $row = $this->entityManager->find(OutboxMessage::class, $locked->getId(), LockMode::PESSIMISTIC_WRITE);
                    if (null === $row || OutboxStatus::Processing !== $row->getStatus()) {
                        return;
                    }
                    $row->markSent();
                    $this->entityManager->flush();
                });
                ++$sent;
            } catch (\Throwable $exception) {
                $this->entityManager->wrapInTransaction(function () use ($locked): void {
                    $row = $this->entityManager->find(OutboxMessage::class, $locked->getId(), LockMode::PESSIMISTIC_WRITE);
                    if (null === $row || OutboxStatus::Processing !== $row->getStatus()) {
                        return;
                    }
                    $row->markFailed(30);
                    $this->entityManager->flush();
                });
            }
        }

        return $sent;
    }
}
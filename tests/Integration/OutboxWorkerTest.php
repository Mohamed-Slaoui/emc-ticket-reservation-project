<?php

namespace App\Tests\Integration;

use App\Entity\OutboxMessage;
use App\Enum\OutboxStatus;
use App\Repository\OutboxMessageRepository;
use App\Service\Integration\GoogleSheetsClient;
use App\Service\Integration\OutboxWorker;
use App\Service\Integration\SimulatedGoogleSheetsClient;

class OutboxWorkerTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->loadFixtures();
        SimulatedGoogleSheetsClient::reset();
    }

    public function testClaimSendMarksMessageSent(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $message = new OutboxMessage(
            'ticket.issued',
            'ticket',
            1,
            ['ticketNumber' => 'TKT-1'],
            'ticket-1',
        );
        $em->persist($message);
        $em->flush();

        $worker = self::getContainer()->get(OutboxWorker::class);
        $sent = $worker->process(10);

        self::assertSame(1, $sent);

        $refreshed = $em->find(OutboxMessage::class, $message->getId());
        self::assertSame(OutboxStatus::Sent, $refreshed->getStatus());
        self::assertNotNull($refreshed->getSentAt());
    }

    public function testFailedMessageIsRetriedAndSent(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        // Force the client to fail on the first attempt.
        $failing = new class implements GoogleSheetsClient {
            public bool $fail = true;

            public function appendRow(string $sheet, array $row, string $idempotencyKey): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('sheets down');
                }
            }
        };
        self::getContainer()->set(GoogleSheetsClient::class, $failing);

        $message = new OutboxMessage(
            'ticket.issued',
            'ticket',
            2,
            ['ticketNumber' => 'TKT-2'],
            'ticket-2',
        );
        $em->persist($message);
        $em->flush();

        $worker = self::getContainer()->get(OutboxWorker::class);
        $sent = $worker->process(10);
        self::assertSame(0, $sent);

        $refreshed = $em->find(OutboxMessage::class, $message->getId());
        self::assertSame(OutboxStatus::Failed, $refreshed->getStatus());

        // Make the client succeed again and requeue the failed message by
        // moving its next attempt into the past.
        $failing->fail = false;
        $refreshed->retry(0);
        $em->flush();

        $sent = $worker->process(10);
        self::assertSame(1, $sent);

        $final = $em->find(OutboxMessage::class, $message->getId());
        self::assertSame(OutboxStatus::Sent, $final->getStatus());
    }
}
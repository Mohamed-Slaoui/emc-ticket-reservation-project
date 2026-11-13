<?php

namespace App\Command;

use App\Service\Reservation\ReservationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Releases the holds whose expiry passed without payment (BR-05).
 *
 * Intended to be called by the system scheduler — every minute is a good
 * interval. Calling it when nothing expired is a no-op.
 */
#[AsCommand('app:holds:expire', 'Releases every hold that expired without payment and puts its seats back on sale')]
class ExpireHoldsCommand extends Command
{
    public function __construct(
        private readonly ReservationService $reservations,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = $this->reservations->expireDueHolds();
        $io->writeln(sprintf('%d hold(s) expired.', $count));

        return Command::SUCCESS;
    }
}

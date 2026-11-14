<?php

namespace App\Command;

use App\Service\Integration\OutboxWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:outbox:process', 'Drains the outbox: sends pending messages to Google Sheets')]
class ProcessOutboxCommand extends Command
{
    public function __construct(
        private readonly OutboxWorker $worker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of messages to process', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $sent = $this->worker->process($limit > 0 ? $limit : 50);
        $io->writeln(sprintf('%d message(s) sent.', $sent));

        return Command::SUCCESS;
    }
}
<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\Command;

use ElevateDxp\Statistics\Report\ReportRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:statistics:run', description: 'Run a configured statistics report (omit the name to list reports)')]
final class StatisticsRunCommand extends Command
{
    public function __construct(private readonly ReportRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('report', InputArgument::OPTIONAL, 'Report name (omit to list all)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('report');
        if ($name === null) {
            $io->listing($this->runner->names());

            return Command::SUCCESS;
        }

        try {
            $data = $this->runner->run((string) $name);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ($data['rows'] === []) {
            $io->warning('No rows.');

            return Command::SUCCESS;
        }
        $io->title((string) $name);
        $io->table($data['columns'], array_map(
            static fn (array $r): array => array_map(static fn ($v): string => \is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v), $r),
            $data['rows'],
        ));
        if ($data['truncated']) {
            $io->note('Output truncated to the configured max_rows.');
        }

        return Command::SUCCESS;
    }
}

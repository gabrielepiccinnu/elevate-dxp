<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Command;

use ElevateDxp\Experiments\Report\ExperimentReport;
use ElevateDxp\Experiments\Repository\ExperimentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:experiments:report', description: 'Show per-variant results of an experiment')]
final class ReportCommand extends Command
{
    public function __construct(private readonly ExperimentRepository $experiments, private readonly ExperimentReport $report)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Experiment key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $experiment = $this->experiments->findByKey((string) $input->getArgument('key'));
        if ($experiment === null) {
            $io->error('Experiment not found.');

            return Command::FAILURE;
        }
        $io->title(\sprintf('%s [%s] — goal "%s"', $experiment->name, $experiment->status, $experiment->goalEvent ?? '-'));
        $rows = $this->report->build($experiment);
        $io->table(array_keys($rows[0] ?? []), array_map(static fn (array $r) => array_map(static fn ($v) => \is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v, $r), $rows));

        return Command::SUCCESS;
    }
}

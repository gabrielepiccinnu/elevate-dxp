<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Command;

use ElevateDxp\Experiments\Experiment\ExperimentAssigner;
use ElevateDxp\Experiments\Repository\ExperimentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:experiments:distribution', description: 'Simulate the deterministic split of an experiment (no writes)')]
final class DistributionCommand extends Command
{
    public function __construct(private readonly ExperimentRepository $experiments)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED)->addOption('visitors', null, InputOption::VALUE_REQUIRED, 'Simulated visitors', '10000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $experiment = $this->experiments->findByKey((string) $input->getArgument('key'));
        if ($experiment === null) {
            $io->error('Experiment not found.');

            return Command::FAILURE;
        }
        $n = max(1, (int) $input->getOption('visitors'));
        $counts = [];
        for ($i = 0; $i < $n; ++$i) {
            $vid = hash('sha256', 'sim'.$i);
            $counts[ExperimentAssigner::pickWeighted($experiment, $vid)] = ($counts[ExperimentAssigner::pickWeighted($experiment, $vid)] ?? 0) + 1;
        }
        $rows = [];
        foreach ($experiment->variants as $v) {
            $c = $counts[$v->key] ?? 0;
            $rows[] = [$v->key, $v->weight, round($v->weight / max(1, $experiment->totalWeight()) * 100, 2).'%', $c, round($c / $n * 100, 2).'%'];
        }
        $io->table(['Variant', 'Weight', 'Expected', 'Assigned', 'Actual'], $rows);

        return Command::SUCCESS;
    }
}

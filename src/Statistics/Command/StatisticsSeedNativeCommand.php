<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\Command;

use ElevateDxp\Statistics\Admin\StatisticsReportsResource;
use ElevateDxp\Statistics\Report\NativeReportSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Seeds elevate_dxp.statistics.reports as native OpenDXP Custom Reports. Idempotent. */
#[AsCommand(name: 'elevate-dxp:statistics:seed-native', description: 'Create the configured statistics reports as native Custom Reports (idempotent)')]
final class StatisticsSeedNativeCommand extends Command
{
    public function __construct(private readonly NativeReportSeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('reports', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Only these report names');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getArgument('reports');
        $result = $this->seeder->seed($only === [] ? null : array_map('strval', $only));
        $io->success(StatisticsReportsResource::summary($result));

        return $result['unsupported'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}

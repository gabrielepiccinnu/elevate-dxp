<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Command;

use ElevateDxp\Export\Runner\ExportRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:export:run', description: 'Run a configured export job')]
final class ExportRunCommand extends Command
{
    public function __construct(private readonly ExportRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('job', InputArgument::OPTIONAL, 'Job name from elevate_dxp.export.jobs')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List the configured jobs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $job = (string) $input->getArgument('job');

        if ($input->getOption('list') || $job === '') {
            $jobs = $this->runner->listJobs();
            if ($jobs === []) {
                $io->warning('No export jobs configured under elevate_dxp.export.jobs.');
            } else {
                $io->table(['Job', 'Format', 'Source', 'Class', 'Target'], array_map(
                    static fn (array $j): array => [$j['name'], $j['format'], $j['source'], $j['class'], $j['target']],
                    $jobs,
                ));
            }

            return $job === '' && !$input->getOption('list') ? Command::INVALID : Command::SUCCESS;
        }

        try {
            $result = $this->runner->run($job, 'cli');
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Exported %d rows -> %s', $result['rows'], $result['location']));

        return Command::SUCCESS;
    }
}

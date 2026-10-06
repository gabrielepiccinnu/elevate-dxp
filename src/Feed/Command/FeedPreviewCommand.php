<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\Command;

use ElevateDxp\Feed\Runner\FeedRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:feed:preview', description: 'Preview feed rows and validation issues')]
final class FeedPreviewCommand extends Command
{
    public function __construct(private readonly FeedRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('feed', InputArgument::REQUIRED, 'Feed name');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $feed = (string) $input->getArgument('feed');
        $limit = max(1, (int) $input->getOption('limit'));

        try {
            $built = $this->runner->build($feed, $limit);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $rows = $built['rows'];
        if ($rows === []) {
            $io->warning('No rows.');

            return Command::SUCCESS;
        }

        $headers = array_keys($rows[0]);
        $table = array_map(static fn (array $r): array => array_map(
            static fn ($v): string => \is_array($v) ? implode(',', array_map('strval', $v)) : (string) ($v ?? ''),
            array_replace(array_fill_keys($headers, null), $r),
        ), $rows);

        $io->title("Feed preview: $feed");
        $io->table($headers, $table);

        if ($built['issues'] === []) {
            $io->success('All previewed rows pass required-field validation.');
        } else {
            $io->warning(\count($built['issues']).' row(s) with missing required fields:');
            foreach ($built['issues'] as $issue) {
                $io->writeln(\sprintf('  row #%d -> missing: %s', $issue['index'], implode(', ', $issue['missing'])));
            }
        }

        return Command::SUCCESS;
    }
}

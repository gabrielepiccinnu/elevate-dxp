<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\Command;

use ElevateDxp\Feed\Runner\FeedRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:feed:export', description: 'Render and write a product feed to its target')]
final class FeedExportCommand extends Command
{
    public function __construct(private readonly FeedRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('feed', InputArgument::REQUIRED, 'Feed name from elevate_dxp_feed.feeds');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $feed = (string) $input->getArgument('feed');
        try {
            $result = $this->runner->export($feed, 'cli');
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $msg = \sprintf('Feed "%s": %d rows -> %s', $feed, $result['rows'], $result['location']);
        if ($result['issues'] > 0) {
            $io->warning($msg.\sprintf(' (%d row(s) with validation issues)', $result['issues']));
        } else {
            $io->success($msg);
        }

        return Command::SUCCESS;
    }
}

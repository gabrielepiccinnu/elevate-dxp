<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Command;

use ElevateDxp\Portal\Search\PortalSearchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:portal:search-smoke', description: 'Smoke-test the portal search backend (assets + data objects)')]
final class PortalSearchSmokeCommand extends Command
{
    public function __construct(
        private readonly PortalSearchService $search,
        private readonly string $defaultClass = 'Product',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('q', null, InputOption::VALUE_REQUIRED, 'Full-text query', '');
        $this->addOption('class', null, InputOption::VALUE_REQUIRED, 'DataObject class (empty = skip objects)', $this->defaultClass);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $q = (string) $input->getOption('q') ?: null;
        $class = (string) $input->getOption('class');
        $io->writeln('Backends: '.implode(', ', $this->search->backendNames()));

        try {
            if ($class !== '') {
                $objs = $this->search->searchDataObjects($class, $q, 1, 5);
                $io->section("DataObjects [$class] q=".($q ?? '∅'));
                $io->writeln('total: '.$objs['total']);
                foreach ($objs['items'] as $it) {
                    $io->writeln(\sprintf('  #%s %s', (string) $it['id'], (string) $it['fullPath']));
                }
            }

            $assets = $this->search->searchAssets($q, 1, 5);
            $io->section('Assets q='.($q ?? '∅'));
            $io->writeln('total: '.$assets['total']);
            foreach ($assets['items'] as $it) {
                $io->writeln(\sprintf('  #%s %s', (string) $it['id'], (string) $it['fullPath']));
            }
        } catch (\Throwable $e) {
            $io->error($e::class.': '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Portal search OK.');

        return Command::SUCCESS;
    }
}

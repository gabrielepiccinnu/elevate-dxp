<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Command;

use ElevateDxp\Core\Admin\AdminResourceRegistry;
use ElevateDxp\Core\Installer\Installer;
use ElevateDxp\DependencyInjection\Modules;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:diagnostics', description: 'Show Elevate DXP install state, modules and admin resources')]
final class DiagnosticsCommand extends Command
{
    public function __construct(
        private readonly AdminResourceRegistry $registry,
        private readonly Installer $installer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Elevate DXP — diagnostics');

        $io->definitionList(
            ['Installed' => $this->installer->isInstalled() ? 'yes' : 'no (bin/console opendxp:bundle:install ElevateDxpBundle)'],
            ['Modules' => implode(', ', array_map(static fn ($m) => $m->key(), Modules::all()))],
            ['Permissions' => implode(', ', $this->installer->permissions())],
        );

        $io->section('Admin resources');
        $io->table(['Key', 'Group', 'Label', 'Permission', 'Panel'], array_map(
            static fn ($r) => [$r->getKey(), $r->getGroup(), $r->getLabel(), $r->getPermission(), $r->getSchema()['panel'] ?? 'crud'],
            array_values($this->registry->all()),
        ));

        return Command::SUCCESS;
    }
}

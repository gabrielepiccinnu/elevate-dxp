<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Command;

use ElevateDxp\Core\Installer\Installer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotent schema and permission sync, safe to run on every deployment (the bundle installer
 * only runs once). Creates tables and permissions introduced by newer versions of the bundle.
 */
#[AsCommand(name: 'elevate-dxp:setup', description: 'Create missing Elevate DXP tables and permissions (idempotent, run on deploy)')]
final class SetupCommand extends Command
{
    public function __construct(private readonly Installer $installer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->installer->installSchema();
        $this->installer->installPermissions();
        $io->success(\sprintf('Schema and %d permissions are up to date.', \count($this->installer->permissions())));

        return Command::SUCCESS;
    }
}

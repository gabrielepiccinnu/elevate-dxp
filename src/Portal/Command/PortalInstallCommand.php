<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Command;

use ElevateDxp\Portal\Installer\PortalInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:portal:install', description: 'Create the portal tables and permission (idempotent)')]
final class PortalInstallCommand extends Command
{
    public function __construct(private readonly PortalInstaller $installer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->installer->installSchema();
            $this->installer->installPermissions();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success('Portal schema ensured: edxp_portal_collection, edxp_portal_collection_item, edxp_portal_saved_view.');

        return Command::SUCCESS;
    }
}

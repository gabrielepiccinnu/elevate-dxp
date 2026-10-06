<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Command;

use Doctrine\DBAL\Connection;
use ElevateDxp\Core\Installer\Installer;
use ElevateDxp\Webhook\Installer\WebhookInstaller;
use ElevateDxp\Webhook\Repository\WebhookDeliveryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Re-applies the webhook module's idempotent schema and permission without a full bundle (re)install. */
#[AsCommand(name: 'elevate-dxp:webhook:install', description: 'Create the webhook delivery-log table and permission (idempotent)')]
final class WebhookInstallCommand extends Command
{
    public function __construct(
        private readonly WebhookInstaller $installer,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            foreach ($this->installer->getSchema() as $ddl) {
                $this->db->executeStatement($ddl);
            }
            foreach ($this->installer->getPermissions() as $key) {
                $this->db->executeStatement(
                    'INSERT INTO users_permission_definitions (`key`, `category`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `category` = VALUES(`category`)',
                    [$key, Installer::PERMISSION_CATEGORY],
                );
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success('Webhook schema ensured: '.WebhookDeliveryRepository::TABLE);

        return Command::SUCCESS;
    }
}

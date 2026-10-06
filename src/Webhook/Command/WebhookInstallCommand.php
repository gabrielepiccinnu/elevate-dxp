<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Command;

use ElevateDxp\Webhook\Installer\WebhookInstaller;
use ElevateDxp\Webhook\Repository\WebhookDeliveryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Re-applies the idempotent schema and permissions without a full bundle (re)install. */
#[AsCommand(name: 'elevate-dxp:webhook:install', description: 'Create the webhook delivery-log table and permission (idempotent)')]
final class WebhookInstallCommand extends Command
{
    public function __construct(private readonly WebhookInstaller $installer)
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
        $io->success('Webhook schema ensured: '.WebhookDeliveryRepository::TABLE);

        return Command::SUCCESS;
    }
}

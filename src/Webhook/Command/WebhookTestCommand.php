<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Command;

use ElevateDxp\Webhook\Webhook\TestPayload;
use ElevateDxp\Webhook\Webhook\WebhookDispatcher;
use ElevateDxp\Webhook\Webhook\WebhookEvents;
use ElevateDxp\Webhook\Webhook\WebhookSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'elevate-dxp:webhook:test', description: 'Send a test webhook to a configured subscription (sync or --async)')]
final class WebhookTestCommand extends Command
{
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly WebhookSender $sender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('subscription', InputArgument::REQUIRED, 'Subscription name');
        $this->addOption('event', null, InputOption::VALUE_REQUIRED, 'Event key ('.implode('|', WebhookEvents::ALL).')', WebhookEvents::OBJECT_UPDATE);
        $this->addOption('async', null, InputOption::VALUE_NONE, 'Queue via Messenger (elevate_dxp transport) instead of sending synchronously');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('subscription');
        $event = (string) $input->getOption('event');

        $sub = $this->dispatcher->subscription($name);
        if ($sub === null) {
            $io->error("Unknown subscription '$name'. Available: ".implode(', ', $this->dispatcher->subscriptionNames()));

            return Command::INVALID;
        }
        if (!\in_array($event, WebhookEvents::ALL, true)) {
            $io->error("Unknown event '$event'. Allowed: ".implode(', ', WebhookEvents::ALL));

            return Command::INVALID;
        }

        $payload = TestPayload::create($event, 'cli');

        if ($input->getOption('async')) {
            $this->dispatcher->dispatchTo($name, $event, $payload);
            $io->success("Webhook '$name' queued (event=$event). Consume the elevate_dxp transport to deliver.");

            return Command::SUCCESS;
        }

        $result = $this->sender->deliver($name, $sub, $event, $payload);
        $result->success
            ? $io->success("Webhook '$name' delivered synchronously (event=$event): ".$result->describe())
            : $io->warning("Webhook '$name' not delivered: ".$result->describe());

        return $result->success ? Command::SUCCESS : Command::FAILURE;
    }
}

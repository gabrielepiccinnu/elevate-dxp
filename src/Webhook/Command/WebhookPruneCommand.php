<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Command;

use ElevateDxp\Webhook\Repository\WebhookDeliveryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Retention for the webhook delivery log: deletes edxp_webhook_delivery rows older than --days. */
#[AsCommand(name: 'elevate-dxp:webhook:prune', description: 'Delete webhook delivery log rows older than --days (default 30)')]
final class WebhookPruneCommand extends Command
{
    public const DEFAULT_DAYS = 30;

    public function __construct(private readonly WebhookDeliveryRepository $deliveries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Keep the deliveries of the last N days', (string) self::DEFAULT_DAYS);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the rows that would be deleted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (string) $input->getOption('days');
        if (!ctype_digit($days) || (int) $days < 1) {
            $io->error('--days must be a positive integer.');

            return Command::INVALID;
        }
        $cutoff = self::cutoff(new \DateTimeImmutable(), (int) $days);

        if ($input->getOption('dry-run')) {
            $io->success(\sprintf('Dry run: %d delivery row(s) older than %s would be deleted.', $this->deliveries->countBefore($cutoff), $cutoff->format('Y-m-d H:i:s')));

            return Command::SUCCESS;
        }

        $deleted = $this->deliveries->prune($cutoff);
        $io->success(\sprintf('Deleted %d delivery row(s) older than %s.', $deleted, $cutoff->format('Y-m-d H:i:s')));

        return Command::SUCCESS;
    }

    public static function cutoff(\DateTimeImmutable $now, int $days): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d days', max(1, $days)));
    }
}

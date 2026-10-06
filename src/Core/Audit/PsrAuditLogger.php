<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Audit;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PsrAuditLogger implements AuditLoggerInterface
{
    private LoggerInterface $logger;

    public function __construct(private readonly bool $enabled = true, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function log(AuditEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->logger->info('[elevate-dxp][audit] '.$event->action, [
            'actor' => $event->actor,
            'status' => $event->status,
            'context' => SecretMasker::mask($event->context),
        ]);
    }
}

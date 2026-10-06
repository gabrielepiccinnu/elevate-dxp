<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Audit;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use OpenDxp\Bundle\ApplicationLoggerBundle\ApplicationLogger;

/**
 * Audit trail in OpenDXP's Application Logger (Tools → Application Logger).
 */
final class ApplicationLoggerAuditLogger implements AuditLoggerInterface
{
    public function __construct(private readonly bool $enabled = true)
    {
    }

    public function log(AuditEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }
        // Audit must never break the operation it records.
        try {
            $component = 'elevate-dxp.'.explode('.', $event->action, 2)[0];
            $context = SecretMasker::mask($event->context);
            $message = \sprintf(
                '%s — %s (actor=%s)%s',
                $event->action,
                $event->status,
                $event->actor,
                $context !== [] ? ' '.json_encode($context, \JSON_UNESCAPED_SLASHES) : '',
            );
            $level = preg_match('/fail|error|denied|forbidden/i', $event->status) === 1 ? 'error' : 'info';
            ApplicationLogger::getInstance($component, true)->log($level, $message, ['component' => $component]);
        } catch (\Throwable) {
        }
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Export;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;

final class InMemoryAuditLogger implements AuditLoggerInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<string> "action:status" */
    public function summary(): array
    {
        return array_map(static fn (AuditEvent $e): string => $e->action.':'.$e->status, $this->events);
    }
}

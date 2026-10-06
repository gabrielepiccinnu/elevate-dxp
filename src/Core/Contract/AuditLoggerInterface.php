<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Contract;

use ElevateDxp\Core\Dto\AuditEvent;

interface AuditLoggerInterface
{
    public function log(AuditEvent $event): void;
}

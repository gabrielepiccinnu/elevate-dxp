<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Analytics;

use ElevateDxp\Experiments\Message\TrackedEvent;

interface AnalyticsAdapterInterface
{
    public function name(): string;

    public function send(TrackedEvent $event): void;
}

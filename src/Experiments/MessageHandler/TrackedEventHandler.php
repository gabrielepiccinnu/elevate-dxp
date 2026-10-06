<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\MessageHandler;

use ElevateDxp\Experiments\Analytics\AnalyticsAdapterInterface;
use ElevateDxp\Experiments\Message\TrackedEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TrackedEventHandler
{
    public function __construct(private readonly ?AnalyticsAdapterInterface $adapter = null)
    {
    }

    public function __invoke(TrackedEvent $event): void
    {
        $this->adapter?->send($event);
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\EventListener;

use ElevateDxp\Experiments\Experiment\ExperimentRuntime;
use OpenDxp\Bundle\PersonalizationBundle\Event\Targeting\TargetingEvent;
use OpenDxp\Bundle\PersonalizationBundle\Event\TargetingEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * After OpenDXP rules ran (and assigned target groups), assign experiments — including the ones
 * scoped to a target group — and give the visitor each variant's hidden target group, so the
 * document renders the per-target-group content of that variant.
 */
final class TargetingSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ExperimentRuntime $runtime)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [TargetingEvents::POST_RESOLVE => ['onPostResolve', -10]];
    }

    public function onPostResolve(TargetingEvent $event): void
    {
        $this->runtime->assignAll($event->getVisitorInfo());
    }
}

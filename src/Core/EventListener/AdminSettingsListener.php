<?php

declare(strict_types=1);

namespace ElevateDxp\Core\EventListener;

use ElevateDxp\Core\Admin\AdminResourceRegistry;
use OpenDxp\Bundle\AdminBundle\Event\AdminEvents;
use OpenDxp\Bundle\AdminBundle\Event\IndexActionSettingsEvent;
use OpenDxp\Security\User\TokenStorageUserResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ships the per-user feature list with the admin bootstrap settings, so the
 * "Elevate DXP" main menu can be built synchronously in preMenuBuild.
 */
#[AsEventListener(event: AdminEvents::INDEX_ACTION_SETTINGS)]
final class AdminSettingsListener
{
    public function __construct(
        private readonly AdminResourceRegistry $registry,
        private readonly TokenStorageUserResolver $userResolver,
    ) {
    }

    public function __invoke(IndexActionSettingsEvent $event): void
    {
        $event->addSetting('elevatedxp', ['features' => $this->registry->features($this->userResolver->getUser())]);
    }
}

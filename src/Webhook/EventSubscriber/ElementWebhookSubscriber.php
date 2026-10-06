<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\EventSubscriber;

use ElevateDxp\Webhook\Webhook\WebhookDispatcher;
use ElevateDxp\Webhook\Webhook\WebhookEvents;
use OpenDxp\Event\AssetEvents;
use OpenDxp\Event\DataObjectEvents;
use OpenDxp\Event\DocumentEvents;
use OpenDxp\Event\Model\AssetEvent;
use OpenDxp\Event\Model\DataObjectEvent;
use OpenDxp\Event\Model\DocumentEvent;
use OpenDxp\Event\Model\ElementEventInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Fires webhooks on OpenDXP data object / asset / document add, update and delete.
 * Never breaks the save (fail-soft). Editor auto-saves are ignored: they are drafts, not changes.
 */
final class ElementWebhookSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly WebhookDispatcher $dispatcher)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DataObjectEvents::POST_ADD => 'onObjectAdd',
            DataObjectEvents::POST_UPDATE => 'onObjectUpdate',
            DataObjectEvents::POST_DELETE => 'onObjectDelete',
            AssetEvents::POST_ADD => 'onAssetAdd',
            AssetEvents::POST_UPDATE => 'onAssetUpdate',
            AssetEvents::POST_DELETE => 'onAssetDelete',
            DocumentEvents::POST_ADD => 'onDocumentAdd',
            DocumentEvents::POST_UPDATE => 'onDocumentUpdate',
            DocumentEvents::POST_DELETE => 'onDocumentDelete',
        ];
    }

    public function onObjectAdd(DataObjectEvent $e): void
    {
        $this->object(WebhookEvents::OBJECT_ADD, $e);
    }

    public function onObjectUpdate(DataObjectEvent $e): void
    {
        $this->object(WebhookEvents::OBJECT_UPDATE, $e);
    }

    public function onObjectDelete(DataObjectEvent $e): void
    {
        $this->object(WebhookEvents::OBJECT_DELETE, $e);
    }

    public function onAssetAdd(AssetEvent $e): void
    {
        $this->asset(WebhookEvents::ASSET_ADD, $e);
    }

    public function onAssetUpdate(AssetEvent $e): void
    {
        $this->asset(WebhookEvents::ASSET_UPDATE, $e);
    }

    public function onAssetDelete(AssetEvent $e): void
    {
        $this->asset(WebhookEvents::ASSET_DELETE, $e);
    }

    public function onDocumentAdd(DocumentEvent $e): void
    {
        $this->document(WebhookEvents::DOCUMENT_ADD, $e);
    }

    public function onDocumentUpdate(DocumentEvent $e): void
    {
        $this->document(WebhookEvents::DOCUMENT_UPDATE, $e);
    }

    public function onDocumentDelete(DocumentEvent $e): void
    {
        $this->document(WebhookEvents::DOCUMENT_DELETE, $e);
    }

    private function object(string $event, DataObjectEvent $e): void
    {
        $this->fire($event, $e, static function () use ($e): array {
            $o = $e->getObject();

            return [
                'elementType' => 'object',
                'id' => $o->getId(),
                'key' => $o->getKey(),
                'fullPath' => $o->getFullPath(),
                'type' => $o->getType(),
                'className' => method_exists($o, 'getClassName') ? $o->getClassName() : null,
            ];
        });
    }

    private function asset(string $event, AssetEvent $e): void
    {
        $this->fire($event, $e, static function () use ($e): array {
            $a = $e->getAsset();

            return [
                'elementType' => 'asset',
                'id' => $a->getId(),
                'key' => $a->getFilename(),
                'fullPath' => $a->getFullPath(),
                'type' => $a->getType(),
                'mimeType' => $a->getMimeType(),
            ];
        });
    }

    private function document(string $event, DocumentEvent $e): void
    {
        $this->fire($event, $e, static function () use ($e): array {
            $d = $e->getDocument();

            return [
                'elementType' => 'document',
                'id' => $d->getId(),
                'key' => $d->getKey(),
                'fullPath' => $d->getFullPath(),
                'type' => $d->getType(),
            ];
        });
    }

    /** @param callable(): array<string,mixed> $payload */
    private function fire(string $event, ElementEventInterface $e, callable $payload): void
    {
        try {
            if (method_exists($e, 'hasArgument') && $e->hasArgument('isAutoSave') && $e->getArgument('isAutoSave') === true) {
                return;
            }
            $this->dispatcher->dispatch($event, $payload());
        } catch (\Throwable) {
            // fail-soft: a webhook must never break a save
        }
    }
}

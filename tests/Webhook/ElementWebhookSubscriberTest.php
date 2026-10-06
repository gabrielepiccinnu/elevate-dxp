<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook;

use ElevateDxp\Tests\Webhook\Webhook\Fakes;
use ElevateDxp\Tests\Webhook\Webhook\RecordingMessageBus;
use ElevateDxp\Webhook\EventSubscriber\ElementWebhookSubscriber;
use ElevateDxp\Webhook\Message\SendWebhookMessage;
use ElevateDxp\Webhook\Webhook\SubscriptionRegistry;
use ElevateDxp\Webhook\Webhook\WebhookDispatcher;
use ElevateDxp\Webhook\Webhook\WebhookEvents;
use OpenDxp\Event\AssetEvents;
use OpenDxp\Event\DataObjectEvents;
use OpenDxp\Event\DocumentEvents;
use OpenDxp\Event\Model\AssetEvent;
use OpenDxp\Event\Model\DataObjectEvent;
use OpenDxp\Event\Model\DocumentEvent;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\Document;
use PHPUnit\Framework\TestCase;

final class ElementWebhookSubscriberTest extends TestCase
{
    private RecordingMessageBus $bus;

    private function subscriber(?\Throwable $busFailure = null): ElementWebhookSubscriber
    {
        $this->bus = Fakes::bus($busFailure);

        return new ElementWebhookSubscriber(new WebhookDispatcher(new SubscriptionRegistry([
            'all' => ['url' => 'http://x', 'events' => WebhookEvents::ALL],
        ]), $this->bus));
    }

    public function testSubscribesToAllOpenDxpElementEvents(): void
    {
        $events = ElementWebhookSubscriber::getSubscribedEvents();
        foreach ([DataObjectEvents::POST_ADD, DataObjectEvents::POST_UPDATE, DataObjectEvents::POST_DELETE,
            AssetEvents::POST_ADD, AssetEvents::POST_UPDATE, AssetEvents::POST_DELETE,
            DocumentEvents::POST_ADD, DocumentEvents::POST_UPDATE, DocumentEvents::POST_DELETE] as $name) {
            self::assertArrayHasKey($name, $events);
            self::assertTrue(method_exists(ElementWebhookSubscriber::class, $events[$name]));
        }
        self::assertStringStartsWith('opendxp.', DataObjectEvents::POST_UPDATE);
    }

    public function testObjectPayload(): void
    {
        $object = $this->createStub(AbstractObject::class);
        $object->method('getId')->willReturn(42);
        $object->method('getKey')->willReturn('shoe');
        $object->method('getFullPath')->willReturn('/products/shoe');
        $object->method('getType')->willReturn('object');

        $this->subscriber()->onObjectUpdate(new DataObjectEvent($object));

        self::assertCount(1, $this->bus->messages);
        $m = $this->bus->messages[0];
        self::assertInstanceOf(SendWebhookMessage::class, $m);
        self::assertSame(WebhookEvents::OBJECT_UPDATE, $m->event);
        self::assertSame('object', $m->payload['elementType']);
        self::assertSame(42, $m->payload['id']);
        self::assertSame('/products/shoe', $m->payload['fullPath']);
    }

    public function testAssetAndDocumentPayloads(): void
    {
        $asset = $this->createStub(Asset::class);
        $asset->method('getId')->willReturn(7);
        $asset->method('getFilename')->willReturn('a.jpg');
        $asset->method('getFullPath')->willReturn('/a.jpg');
        $asset->method('getType')->willReturn('image');
        $asset->method('getMimeType')->willReturn('image/jpeg');

        $document = $this->createStub(Document::class);
        $document->method('getId')->willReturn(3);
        $document->method('getKey')->willReturn('home');
        $document->method('getFullPath')->willReturn('/home');
        $document->method('getType')->willReturn('page');

        $s = $this->subscriber();
        $s->onAssetAdd(new AssetEvent($asset));
        $s->onDocumentDelete(new DocumentEvent($document));

        self::assertSame(WebhookEvents::ASSET_ADD, $this->bus->messages[0]->event);
        self::assertSame('image/jpeg', $this->bus->messages[0]->payload['mimeType']);
        self::assertSame(WebhookEvents::DOCUMENT_DELETE, $this->bus->messages[1]->event);
        self::assertSame(['elementType' => 'document', 'id' => 3, 'key' => 'home', 'fullPath' => '/home', 'type' => 'page'], $this->bus->messages[1]->payload);
    }

    public function testAutoSaveIsIgnored(): void
    {
        $object = $this->createStub(AbstractObject::class);
        $this->subscriber()->onObjectUpdate(new DataObjectEvent($object, ['saveVersionOnly' => true, 'isAutoSave' => true]));
        self::assertCount(0, $this->bus->messages);
    }

    public function testFailSoft(): void
    {
        $object = $this->createStub(AbstractObject::class);
        $object->method('getId')->willThrowException(new \RuntimeException('boom'));
        $this->subscriber()->onObjectAdd(new DataObjectEvent($object));
        self::assertCount(0, $this->bus->messages);

        $object = $this->createStub(AbstractObject::class);
        $object->method('getFullPath')->willReturn('/x');
        $object->method('getType')->willReturn('object');
        $this->subscriber(new \RuntimeException('bus down'))->onObjectDelete(new DataObjectEvent($object));
        self::assertCount(1, $this->bus->messages); // attempted, exception swallowed
    }
}

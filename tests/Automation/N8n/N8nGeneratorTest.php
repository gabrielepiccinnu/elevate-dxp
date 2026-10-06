<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Automation\N8n;

use ElevateDxp\Automation\N8n\N8nBlueprintGenerator;
use PHPUnit\Framework\TestCase;

/** n8n blueprint generation. */
final class N8nGeneratorTest extends TestCase
{
    private function gen(bool $enabled = true): N8nBlueprintGenerator
    {
        return new N8nBlueprintGenerator([
            'local' => ['events' => ['object.update', 'asset.add'], 'url' => 'http://nginx/sink', 'secret' => 'topsecret'],
        ], $enabled);
    }

    public function testFromWebhookStructure(): void
    {
        $bp = $this->gen()->fromWebhook('local');
        self::assertNotNull($bp);
        $types = array_map(static fn (array $n): string => $n['type'], $bp['nodes']);
        self::assertContains('n8n-nodes-base.webhook', $types);
        self::assertContains('n8n-nodes-base.noOp', $types);
        self::assertContains('n8n-nodes-base.stickyNote', $types);
        self::assertArrayHasKey(N8nBlueprintGenerator::TRIGGER_NODE, $bp['connections']);
        // importable JSON
        self::assertJson((string) json_encode($bp));
        // every connection points to an existing node
        $names = array_column($bp['nodes'], 'name');
        foreach ($bp['connections'] as $from => $c) {
            self::assertContains($from, $names);
            self::assertContains($c['main'][0][0]['node'], $names);
        }
        self::assertSame('elevate-dxp-local', $bp['nodes'][0]['parameters']['path']);
        self::assertSame(['subscription' => 'local', 'events' => ['object.update', 'asset.add']], $bp['meta']['elevatedxp']);
        self::assertStringContainsString('X-ElevateDxp-Signature', $bp['nodes'][2]['parameters']['content']);
    }

    public function testUnknownSubscription(): void
    {
        self::assertNull($this->gen()->fromWebhook('nope'));
        self::assertSame(['local'], $this->gen()->subscriptionNames());
    }

    public function testBlueprintNeverLeaksSecretOrUrl(): void
    {
        $json = $this->gen()->toJson((array) $this->gen()->fromWebhook('local'));
        self::assertStringNotContainsString('topsecret', $json);
        self::assertStringNotContainsString('http://nginx/sink', $json);
    }

    public function testPathIsSanitised(): void
    {
        self::assertSame('elevate-dxp-a-b--c', N8nBlueprintGenerator::webhookPath('a b/.c'));
    }

    public function testDisabled(): void
    {
        self::assertSame([], $this->gen(false)->subscriptionNames());
        self::assertNull($this->gen(false)->fromWebhook('local'));
    }
}

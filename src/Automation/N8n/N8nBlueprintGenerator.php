<?php

declare(strict_types=1);

namespace ElevateDxp\Automation\N8n;

/**
 * Generates importable n8n workflow blueprints from Elevate DXP webhook subscriptions:
 * a Webhook trigger (matching the subscription) → a NoOp node, plus a sticky note documenting the
 * Elevate DXP event/payload/signature contract. Import the JSON into n8n and build the automation there.
 *
 * Only names and event lists are read from the subscriptions; URLs and signing secrets never end up
 * in a blueprint.
 */
final class N8nBlueprintGenerator
{
    public const TRIGGER_NODE = 'Elevate DXP Webhook';

    /** @param array<string,array{events?:array<int,string>,url?:string,active?:bool,secret?:string}> $webhookSubscriptions */
    public function __construct(
        private readonly array $webhookSubscriptions,
        private readonly bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** @return list<string> */
    public function subscriptionNames(): array
    {
        return $this->enabled ? array_map('strval', array_keys($this->webhookSubscriptions)) : [];
    }

    /** @return list<string> events of a subscription ([] if unknown) */
    public function eventsOf(string $name): array
    {
        return array_values(array_map('strval', (array) ($this->webhookSubscriptions[$name]['events'] ?? [])));
    }

    /** Webhook path used by the n8n trigger node; point the subscription url at <n8n>/webhook/<path>. */
    public static function webhookPath(string $name): string
    {
        return 'elevate-dxp-'.preg_replace('/[^a-z0-9_-]/i', '-', $name);
    }

    /** @return array<string,mixed>|null importable n8n workflow */
    public function fromWebhook(string $name): ?array
    {
        if (!$this->enabled || !isset($this->webhookSubscriptions[$name])) {
            return null;
        }
        $eventList = $this->eventsOf($name);
        $events = implode(', ', $eventList);
        $path = self::webhookPath($name);
        $nodeId = preg_replace('/[^a-z0-9_-]/i', '-', $name);

        return [
            'name' => 'Elevate DXP · '.$name,
            'nodes' => [
                [
                    'parameters' => ['httpMethod' => 'POST', 'path' => $path, 'options' => []],
                    'id' => 'webhook-'.$nodeId,
                    'name' => self::TRIGGER_NODE,
                    'type' => 'n8n-nodes-base.webhook',
                    'typeVersion' => 1,
                    'position' => [260, 300],
                    'webhookId' => $path,
                ],
                [
                    'parameters' => [],
                    'id' => 'noop-'.$nodeId,
                    'name' => 'Do something',
                    'type' => 'n8n-nodes-base.noOp',
                    'typeVersion' => 1,
                    'position' => [520, 300],
                ],
                [
                    'parameters' => [
                        'content' => "Elevate DXP events: {$events}\n"
                            ."Set the subscription url to <n8n>/webhook/{$path}.\n"
                            ."Verify HMAC header X-ElevateDxp-Signature (sha256=<hex> of the raw body, shared secret).\n"
                            ."Event header: X-ElevateDxp-Event.\n"
                            .'Payload: { event, data: { elementType, id, key, fullPath, type } }',
                        'height' => 220, 'width' => 360,
                    ],
                    'id' => 'note-'.$nodeId,
                    'name' => 'Elevate DXP contract',
                    'type' => 'n8n-nodes-base.stickyNote',
                    'typeVersion' => 1,
                    'position' => [200, 60],
                ],
            ],
            'connections' => [
                self::TRIGGER_NODE => [
                    'main' => [[['node' => 'Do something', 'type' => 'main', 'index' => 0]]],
                ],
            ],
            'settings' => ['executionOrder' => 'v1'],
            'meta' => ['elevatedxp' => ['subscription' => $name, 'events' => $eventList]],
        ];
    }

    public function toJson(array $blueprint): string
    {
        return (string) json_encode($blueprint, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}

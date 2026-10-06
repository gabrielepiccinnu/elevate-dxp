<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Webhook;

/** Sample payload used by "send test" in the CLI and the admin. */
final class TestPayload
{
    /** @return array<string,mixed> */
    public static function create(string $event, string $source): array
    {
        return [
            'test' => true,
            'elementType' => strstr($event, '.', true) ?: 'test',
            'id' => 0,
            'key' => 'webhook-test',
            'fullPath' => '/webhook-test',
            'source' => $source,
            'note' => 'manual test payload',
        ];
    }
}

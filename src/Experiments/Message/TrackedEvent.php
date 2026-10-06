<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Message;

use ElevateDxp\Core\Messenger\AsyncMessageInterface;

/**
 * Forwarded to the configured analytics adapter (Matomo/PostHog) on the elevate_dxp transport.
 */
final class TrackedEvent implements AsyncMessageInterface
{
    /**
     * @param array<string,string> $assignments experiment key => variant
     */
    public function __construct(
        public readonly string $visitorId,
        public readonly string $name,
        public readonly string $type,
        public readonly ?float $value,
        public readonly ?string $url,
        public readonly array $assignments,
        public readonly array $metadata,
        public readonly string $occurredAt,
    ) {
    }
}

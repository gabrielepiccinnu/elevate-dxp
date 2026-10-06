<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Tracking;

use Doctrine\DBAL\Connection;
use ElevateDxp\Experiments\Message\TrackedEvent;
use Symfony\Component\Messenger\MessageBusInterface;

final class EventRecorder
{
    public const TYPES = ['exposure', 'conversion', 'click', 'pageview', 'custom'];

    public const NAME_PATTERN = '/^[a-z0-9_.:-]{1,64}$/';

    public function __construct(
        private readonly Connection $db,
        private readonly MessageBusInterface $bus,
        private readonly bool $forwardToAnalytics,
    ) {
    }

    /**
     * @param array<string,string> $assignments current experiment assignments of the visitor
     * @param array<string,mixed>  $metadata    extra event properties
     */
    public function record(
        string $visitorId,
        string $name,
        string $type = 'custom',
        ?float $value = null,
        ?string $url = null,
        ?string $experimentKey = null,
        ?string $variantKey = null,
        array $metadata = [],
        array $assignments = [],
    ): bool {
        if (!\in_array($type, self::TYPES, true) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            return false;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            $this->db->insert('edxp_event', [
                'visitor_id' => $visitorId,
                'event_name' => $name,
                'event_type' => $type,
                'event_value' => $value,
                'url' => $url !== null ? mb_substr($url, 0, 500) : null,
                'experiment_key' => $experimentKey,
                'variant_key' => $variantKey,
                'metadata_json' => $metadata === [] ? null : json_encode($metadata, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            return false;
        }

        if ($this->forwardToAnalytics) {
            if ($experimentKey !== null && $variantKey !== null) {
                $assignments[$experimentKey] = $variantKey;
            }
            try {
                $this->bus->dispatch(new TrackedEvent($visitorId, $name, $type, $value, $url, $assignments, $metadata, $now));
            } catch (\Throwable) {
                // analytics forwarding is best effort
            }
        }

        return true;
    }
}

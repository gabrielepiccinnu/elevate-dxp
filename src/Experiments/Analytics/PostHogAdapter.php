<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Analytics;

use ElevateDxp\Experiments\Message\TrackedEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** PostHog capture API; assignments become "$feature/<experiment>" properties. */
final class PostHogAdapter implements AnalyticsAdapterInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $host,
        private readonly string $apiKey,
    ) {
    }

    public function name(): string
    {
        return 'posthog';
    }

    public function send(TrackedEvent $event): void
    {
        if ($this->apiKey === '') {
            return;
        }
        $this->httpClient->request('POST', rtrim($this->host, '/').'/capture/', ['json' => self::payload($this->apiKey, $event)])->getStatusCode();
    }

    public static function payload(string $apiKey, TrackedEvent $event): array
    {
        $properties = ['$current_url' => $event->url, 'edxp_type' => $event->type, 'value' => $event->value] + $event->metadata;
        foreach ($event->assignments as $experiment => $variant) {
            $properties['$feature/'.$experiment] = $variant;
        }

        return [
            'api_key' => $apiKey,
            'event' => $event->name,
            'distinct_id' => $event->visitorId,
            'properties' => $properties,
            'timestamp' => (new \DateTimeImmutable($event->occurredAt))->format(\DATE_ATOM),
        ];
    }
}

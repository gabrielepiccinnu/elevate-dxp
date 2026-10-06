<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Analytics;

use ElevateDxp\Experiments\Message\TrackedEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Matomo HTTP Tracking API: events as category "edxp", experiment variants as custom dimensions
 * are left to the Matomo side; variants travel in the event name ("exp:variant,...").
 */
final class MatomoAdapter implements AnalyticsAdapterInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $url,
        private readonly string $siteId,
        private readonly string $token = '',
    ) {
    }

    public function name(): string
    {
        return 'matomo';
    }

    public function send(TrackedEvent $event): void
    {
        if ($this->url === '') {
            return;
        }
        $variants = implode(',', array_map(static fn ($k, $v) => $k.':'.$v, array_keys($event->assignments), $event->assignments));
        $params = array_filter([
            'idsite' => $this->siteId,
            'rec' => '1',
            'apiv' => '1',
            '_id' => substr($event->visitorId, 0, 16),
            'cid' => substr($event->visitorId, 0, 16),
            'url' => $event->url,
            'e_c' => 'edxp',
            'e_a' => $event->name,
            'e_n' => $variants !== '' ? $variants : null,
            'e_v' => $event->value,
            'cdt' => $this->token !== '' ? $event->occurredAt : null,
            'token_auth' => $this->token !== '' ? $this->token : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $this->httpClient->request('POST', rtrim($this->url, '/').'/matomo.php', ['body' => $params])->getStatusCode();
    }
}

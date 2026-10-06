<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Webhook;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use ElevateDxp\Webhook\Contract\DeliveryRecorderInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Delivers a single webhook: JSON POST with an HMAC-SHA256 signature header so the receiver can verify
 * authenticity. Best-effort: failures are audited and never bubble up to break an OpenDXP save.
 * Every attempt (success or failure) is persisted to the delivery log for history and re-delivery.
 *
 * Uses the OpenDXP Guzzle client (service GuzzleHttp\Client), so the system proxy settings apply.
 */
final class WebhookSender
{
    public const USER_AGENT = 'ElevateDxp-Webhook/1.0';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly AuditLoggerInterface $audit,
        private readonly DeliveryRecorderInterface $deliveries,
        private readonly int $timeout = 5,
    ) {
    }

    /**
     * @param array{url:string,secret?:string} $subscription
     * @param array<string,mixed>              $payload
     */
    public function send(string $subscriptionName, array $subscription, string $event, array $payload): bool
    {
        return $this->deliver($subscriptionName, $subscription, $event, $payload)->success;
    }

    /**
     * @param array{url:string,secret?:string} $subscription
     * @param array<string,mixed>              $payload
     */
    public function deliver(string $subscriptionName, array $subscription, string $event, array $payload): DeliveryResult
    {
        $url = (string) ($subscription['url'] ?? '');
        $body = self::body($event, $payload);
        $signature = WebhookSignature::sign($body, (string) ($subscription['secret'] ?? ''));

        try {
            $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
            if (!\in_array($scheme, ['http', 'https'], true)) {
                throw new \InvalidArgumentException(\sprintf('Webhook URL must be http(s), got "%s".', $url));
            }
            $response = $this->httpClient->request('POST', $url, [
                RequestOptions::HEADERS => array_filter([
                    'Content-Type' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                    WebhookSignature::HEADER_EVENT => $event,
                    WebhookSignature::HEADER_SIGNATURE => $signature !== '' ? $signature : null,
                ]),
                RequestOptions::BODY => $body,
                RequestOptions::TIMEOUT => $this->timeout,
                RequestOptions::CONNECT_TIMEOUT => $this->timeout,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
            ]);
            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            $result = new DeliveryResult($ok, $status, $ok ? null : 'HTTP '.$status);
            $this->audit->log(new AuditEvent('webhook.send', 'system', $ok ? 'ok' : 'http_'.$status, [
                'subscription' => $subscriptionName, 'event' => $event, 'url' => $url,
            ]));
        } catch (\Throwable $e) {
            $result = new DeliveryResult(false, null, $e->getMessage());
            $this->audit->log(new AuditEvent('webhook.send', 'system', 'failure', [
                'subscription' => $subscriptionName, 'event' => $event, 'error' => $e->getMessage(),
            ]));
        }

        try {
            $this->deliveries->record($subscriptionName, $event, $url, $result->success, $result->httpStatus, $result->error, $payload);
        } catch (\Throwable) {
            // the log table may be missing (bundle not installed yet); delivery outcome still stands
        }

        return $result;
    }

    /** @param array<string,mixed> $payload */
    public static function body(string $event, array $payload): string
    {
        return (string) json_encode(['event' => $event, 'data' => $payload], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}

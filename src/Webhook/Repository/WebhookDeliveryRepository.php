<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Repository;

use Doctrine\DBAL\Connection;
use ElevateDxp\Webhook\Contract\DeliveryRecorderInterface;

/** Persists and reads the webhook delivery history (one row per delivery attempt). */
final class WebhookDeliveryRepository implements DeliveryRecorderInterface
{
    public const TABLE = 'edxp_webhook_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $payload */
    public function record(
        string $subscription,
        string $event,
        string $url,
        bool $success,
        ?int $httpStatus,
        ?string $error,
        array $payload,
    ): void {
        $this->db->insert(self::TABLE, [
            'subscription' => mb_substr($subscription, 0, 190),
            'event' => mb_substr($event, 0, 64),
            'url' => mb_substr($url, 0, 500),
            'status' => $success ? self::STATUS_DELIVERED : self::STATUS_FAILED,
            'success' => $success ? 1 : 0,
            'http_status' => $httpStatus,
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'payload_json' => json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string,mixed>> most recent first */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return $this->db->fetchAllAssociative(
            'SELECT id, subscription, event, url, status, success, http_status, error, created_at
             FROM '.self::TABLE.' ORDER BY id DESC LIMIT '.$limit,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = ?', [$id]);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $row stored delivery row
     *
     * @return array<string,mixed> decoded payload of a stored delivery
     */
    public static function payloadOf(array $row): array
    {
        $payload = json_decode((string) ($row['payload_json'] ?? '[]'), true);

        return \is_array($payload) ? $payload : [];
    }
}

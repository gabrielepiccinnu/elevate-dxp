<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Webhook;

/**
 * HMAC-SHA256 signature carried in the X-ElevateDxp-Signature header ("sha256=<hex>" over the raw body).
 * Receivers recompute it with the shared secret and compare in constant time.
 */
final class WebhookSignature
{
    public const HEADER_EVENT = 'X-ElevateDxp-Event';
    public const HEADER_SIGNATURE = 'X-ElevateDxp-Signature';

    public static function sign(string $body, string $secret): string
    {
        return $secret === '' ? '' : 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public static function verify(string $body, string $secret, ?string $signature): bool
    {
        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(self::sign($body, $secret), $signature);
    }
}

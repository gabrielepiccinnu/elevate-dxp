<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Webhook\Webhook;

use ElevateDxp\Webhook\Webhook\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    public function testSignAndVerify(): void
    {
        $sig = WebhookSignature::sign('{"a":1}', 'k');
        self::assertSame('sha256='.hash_hmac('sha256', '{"a":1}', 'k'), $sig);
        self::assertTrue(WebhookSignature::verify('{"a":1}', 'k', $sig));
        self::assertFalse(WebhookSignature::verify('{"a":2}', 'k', $sig));
        self::assertFalse(WebhookSignature::verify('{"a":1}', 'other', $sig));
        self::assertFalse(WebhookSignature::verify('{"a":1}', 'k', null));
    }

    public function testEmptySecretNeverSignsNorVerifies(): void
    {
        self::assertSame('', WebhookSignature::sign('x', ''));
        self::assertFalse(WebhookSignature::verify('x', '', ''));
    }
}

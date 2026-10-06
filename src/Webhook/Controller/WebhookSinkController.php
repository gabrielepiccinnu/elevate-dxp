<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Controller;

use ElevateDxp\Webhook\Webhook\WebhookSignature;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Local debug receiver: appends incoming webhooks to var/elevate-dxp/webhook-sink.log for verification.
 *
 * TESTING ONLY. Disabled by default (elevate_dxp.webhook.sink_enabled=false → 404, deny-by-default).
 * When elevate_dxp.webhook.sink_secret is set the HMAC signature is verified and a mismatch answers 401.
 * Logged bodies are capped so the public endpoint cannot be used to fill the disk quickly.
 */
final class WebhookSinkController
{
    public const MAX_LOGGED_BODY = 65536;

    public function __construct(
        private readonly bool $enabled,
        private readonly string $projectDir,
        private readonly string $secret = '',
    ) {
    }

    #[Route('/elevate-dxp/webhook-sink', name: 'elevate_dxp_webhook_sink', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->enabled) {
            return new Response('sink disabled', Response::HTTP_NOT_FOUND);
        }

        $body = $request->getContent();
        $signature = $request->headers->get(WebhookSignature::HEADER_SIGNATURE);
        $valid = $this->secret !== '' ? WebhookSignature::verify($body, $this->secret, $signature) : null;

        $dir = $this->projectDir.'/var/elevate-dxp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = json_encode([
            'receivedAt' => date(\DATE_ATOM),
            'event' => $request->headers->get(WebhookSignature::HEADER_EVENT),
            'signature' => $signature,
            'signatureValid' => $valid,
            'truncated' => \strlen($body) > self::MAX_LOGGED_BODY,
            'body' => substr($body, 0, self::MAX_LOGGED_BODY),
        ], \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE).\PHP_EOL;
        @file_put_contents($dir.'/webhook-sink.log', $line, \FILE_APPEND | \LOCK_EX);

        if ($valid === false) {
            return new Response('invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}

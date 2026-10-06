<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\Controller;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use ElevateDxp\Feed\Runner\FeedRunner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Public, tokenized feed URL for shopping engines: GET /elevate-dxp/feed/{name}.{format}?token=...
 *
 * Deny-by-default: a feed is only reachable when it has a token of at least 16 characters
 * configured; tokens are compared with hash_equals. Every denial (unknown feed, no token
 * configured, wrong token, wrong format) answers the same 404 so feed names cannot be
 * enumerated. The token may also be sent in the X-Elevate-Dxp-Feed-Token header.
 */
final class PublicFeedController
{
    public const TOKEN_HEADER = 'X-Elevate-Dxp-Feed-Token';

    public function __construct(
        private readonly FeedRunner $runner,
        private readonly AuditLoggerInterface $audit,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    #[Route(
        '/elevate-dxp/feed/{name}.{format}',
        name: 'elevate_dxp_feed_public',
        requirements: ['name' => '[A-Za-z0-9_-]+', 'format' => 'csv|xml'],
        methods: ['GET', 'HEAD'],
    )]
    public function __invoke(string $name, string $format, Request $request): Response
    {
        $given = (string) ($request->query->get('token') ?? $request->headers->get(self::TOKEN_HEADER, ''));
        $actor = 'token:'.($given === '' ? '-' : substr(hash('sha256', $given), 0, 12));

        if (!$this->runner->isEnabled() || !$this->runner->has($name)) {
            return $this->deny($request, $name, $actor, 'unknown_feed');
        }
        $feed = $this->runner->feed($name);
        $expected = FeedRunner::usableToken($feed['token'] ?? null);
        if ($expected === null) {
            return $this->deny($request, $name, $actor, 'no_token_configured');
        }
        if ($given === '' || !hash_equals($expected, $given)) {
            return $this->deny($request, $name, $actor, 'invalid_token');
        }

        try {
            $template = $this->runner->template($name);
            if ($template->extension() !== $format) {
                return $this->deny($request, $name, $actor, 'format_mismatch');
            }
            $rendered = $this->renderCached($name, (int) ($feed['cache_ttl'] ?? 0));
        } catch (\Throwable $e) {
            $this->log($request, $name, $actor, 'error', ['error' => $e->getMessage()]);

            return new Response('Feed temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        $this->log($request, $name, $actor, 'ok', ['rows' => $rendered['rows']]);

        $response = new Response($rendered['content'], Response::HTTP_OK, [
            'Content-Type' => $rendered['contentType'],
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Content-Disposition' => \sprintf('inline; filename="%s.%s"', $name, $format),
        ]);
        // The URL carries a secret: shared caches must not keep it.
        $response->setPrivate();
        $response->setMaxAge(0);

        return $response;
    }

    /** @return array{content:string,contentType:string,extension:string,rows:int,issues:int} */
    private function renderCached(string $name, int $ttl): array
    {
        if ($ttl <= 0 || $this->cache === null) {
            return $this->runner->render($name);
        }

        return $this->cache->get('edxp_feed_'.$name, function (ItemInterface $item) use ($name, $ttl): array {
            $item->expiresAfter($ttl);

            return $this->runner->render($name);
        });
    }

    private function deny(Request $request, string $name, string $actor, string $reason): Response
    {
        $this->log($request, $name, $actor, 'denied', ['reason' => $reason]);

        return new Response('Not found.', Response::HTTP_NOT_FOUND, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param array<string,mixed> $context */
    private function log(Request $request, string $name, string $actor, string $status, array $context = []): void
    {
        $this->audit->log(new AuditEvent('feed.'.$name.'.public', $actor, $status, $context + ['ip' => $request->getClientIp()]));
    }
}

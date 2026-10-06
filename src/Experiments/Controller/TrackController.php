<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Controller;

use ElevateDxp\Experiments\Repository\AssignmentRepository;
use ElevateDxp\Experiments\Repository\ExperimentRepository;
use ElevateDxp\Experiments\Tracking\EventRecorder;
use ElevateDxp\Experiments\Visitor\VisitorIdResolver;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public conversion/event endpoint used by edxp-runtime.js (window.edxp.track()).
 *
 * - the visitor comes only from the signed first-party cookie (never from the payload);
 * - exposures are server-side only, so clients cannot inflate denominators;
 * - same-origin check, payload size cap, per-visitor rate limit, optional event allow-list.
 */
final class TrackController
{
    private RateLimiterFactory $limiter;

    public function __construct(
        private readonly EventRecorder $recorder,
        private readonly VisitorIdResolver $visitorIdResolver,
        private readonly ExperimentRepository $experiments,
        private readonly AssignmentRepository $assignments,
        CacheItemPoolInterface $cache,
        private readonly array $config,
    ) {
        $this->limiter = new RateLimiterFactory(
            ['id' => 'edxp_track', 'policy' => 'sliding_window', 'limit' => max(1, (int) $config['rate_limit_per_minute']), 'interval' => '1 minute'],
            new CacheStorage($cache),
        );
    }

    #[Route('/_edxp/track', name: 'elevate_dxp_experiments_track', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->config['enabled']) {
            return new JsonResponse(['error' => 'tracking_disabled'], Response::HTTP_NOT_FOUND);
        }
        $origin = $request->headers->get('Origin');
        if ($origin !== null && parse_url($origin, \PHP_URL_HOST) !== $request->getHost()) {
            return new JsonResponse(['error' => 'cross_origin'], Response::HTTP_FORBIDDEN);
        }
        $raw = $request->getContent();
        if (\strlen($raw) > (int) $this->config['max_payload_bytes']) {
            return new JsonResponse(['error' => 'payload_too_large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $visitorId = $this->visitorIdResolver->peekVisitorId($request);
        if ($visitorId === null) {
            return new Response('', Response::HTTP_NO_CONTENT); // unknown visitor: nothing to attribute
        }
        if (!$this->limiter->create($visitorId)->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }
        $name = strtolower(trim((string) ($data['event'] ?? '')));
        $allowed = $this->config['allowed_events'] ?? [];
        if (preg_match(EventRecorder::NAME_PATTERN, $name) !== 1 || ($allowed !== [] && !\in_array($name, $allowed, true)) || $name === 'exposure') {
            return new JsonResponse(['error' => 'event_not_allowed'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $isGoal = false;
        foreach ($this->experiments->findRunning() as $experiment) {
            $isGoal = $isGoal || $experiment->goalEvent === $name;
        }
        $type = $isGoal ? 'conversion' : (\in_array($data['type'] ?? null, ['click', 'pageview', 'custom'], true) ? $data['type'] : 'custom');
        $value = isset($data['value']) && is_numeric($data['value']) ? (float) $data['value'] : null;
        $url = \is_string($data['url'] ?? null) ? $data['url'] : $request->headers->get('Referer');
        $meta = \is_array($data['meta'] ?? null) ? \array_slice($data['meta'], 0, 20, true) : [];

        $ok = $this->recorder->record($visitorId, $name, $type, $value, $url, null, null, $meta, $this->assignments->forVisitor($visitorId));

        return $ok ? new Response('', Response::HTTP_NO_CONTENT) : new JsonResponse(['error' => 'not_recorded'], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}

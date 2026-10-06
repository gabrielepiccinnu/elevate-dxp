<?php

declare(strict_types=1);

namespace ElevateDxp\Datahub\Controller;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use ElevateDxp\Datahub\Api\EndpointExecutor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configurable REST surface for data objects and assets. Deny-by-default:
 *  - a static API key (header) is required; an empty configured key denies all access;
 *  - authentication happens before endpoint lookup, so endpoint names cannot be probed;
 *  - only explicitly declared endpoints are exposed; fields are explicitly mapped;
 *  - pagination has a hard maximum; errors are consistent JSON with no stack traces.
 */
final class ApiController
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $apiKeyHeader,
        private readonly string $apiKey,
        private readonly EndpointExecutor $executor,
        private readonly AuditLoggerInterface $audit,
    ) {
    }

    #[Route('/elevate-dxp/api/{endpoint}', name: 'elevate_dxp_datahub_list', requirements: ['endpoint' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function list(string $endpoint, Request $request): Response
    {
        if (($denied = $this->guard($request, $endpoint)) !== null) {
            return $denied;
        }

        return $this->execute($request, $endpoint, 'list', fn (): array => $this->executor->list(
            $endpoint,
            $request->query->get('page', '1'),
            $request->query->get('limit', '20'),
        ));
    }

    #[Route('/elevate-dxp/api/{endpoint}/{id}', name: 'elevate_dxp_datahub_detail', requirements: ['endpoint' => '[A-Za-z0-9_-]+', 'id' => '\d{1,18}'], methods: ['GET'])]
    public function detail(string $endpoint, int $id, Request $request): Response
    {
        if (($denied = $this->guard($request, $endpoint)) !== null) {
            return $denied;
        }

        return $this->execute($request, $endpoint, 'detail', fn (): array => $this->executor->detail($endpoint, $id), ['id' => $id]);
    }

    /**
     * @param callable(): array{status:int,body:array<string,mixed>,count?:int} $fn
     * @param array<string,mixed>                                               $context
     */
    private function execute(Request $request, string $endpoint, string $action, callable $fn, array $context = []): Response
    {
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->log($request, $endpoint, $action, 'error', $context + ['error' => $e->getMessage()]);

            return $this->json(['error' => 'internal_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $status = $result['status'] === 200 ? 'ok' : (string) ($result['body']['error'] ?? 'error');
        if (isset($result['count'])) {
            $context['count'] = $result['count'];
        }
        $this->log($request, $endpoint, $action, $status, $context);

        return $this->json($result['body'], $result['status']);
    }

    private function guard(Request $request, string $endpoint): ?Response
    {
        if (!$this->enabled) {
            return $this->json(['error' => 'disabled'], Response::HTTP_NOT_FOUND);
        }
        $given = (string) $request->headers->get($this->apiKeyHeader, '');
        if ($this->apiKey === '' || $given === '' || !hash_equals($this->apiKey, $given)) {
            $this->log($request, $endpoint, 'auth', 'unauthorized');

            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    /** @param array<string,mixed> $body */
    private function json(array $body, int $status): JsonResponse
    {
        $response = new JsonResponse($body, $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** @param array<string,mixed> $context */
    private function log(Request $request, string $endpoint, string $action, string $status, array $context = []): void
    {
        $key = (string) $request->headers->get($this->apiKeyHeader, '');
        $actor = 'apikey:'.($key === '' ? '-' : substr(hash('sha256', $key), 0, 12));
        $this->audit->log(new AuditEvent(
            'datahub.'.$endpoint.'.'.$action,
            $actor,
            $status,
            $context + ['ip' => $request->getClientIp()],
        ));
    }
}

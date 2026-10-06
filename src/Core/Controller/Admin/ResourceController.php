<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Controller\Admin;

use ElevateDxp\Core\Admin\AdminResourceInterface;
use ElevateDxp\Core\Admin\AdminResourceRegistry;
use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use OpenDxp\Bundle\AdminBundle\Controller\AdminAbstractController;
use OpenDxp\Model\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Single JSON endpoint family serving every Elevate DXP admin resource.
 * Lives under /admin, so the admin firewall, session and CSRF protection apply.
 */
#[Route('/elevate-dxp')]
final class ResourceController extends AdminAbstractController
{
    public function __construct(
        private readonly AdminResourceRegistry $registry,
        private readonly AuditLoggerInterface $audit,
    ) {
    }

    #[Route('/features', name: 'elevate_dxp_admin_features', methods: ['GET'])]
    public function features(): JsonResponse
    {
        return new JsonResponse(['success' => true, 'data' => $this->registry->features($this->user())]);
    }

    #[Route('/r/{key}/schema', name: 'elevate_dxp_admin_resource_schema', methods: ['GET'])]
    public function schema(string $key): JsonResponse
    {
        return $this->guard($key, fn (AdminResourceInterface $r) => [
            'success' => true,
            'key' => $r->getKey(),
            'label' => $r->getLabel(),
            'iconCls' => $r->getIconCls(),
            'schema' => $r->getSchema() + ['idProperty' => 'id', 'panel' => 'crud'],
        ]);
    }

    #[Route('/r/{key}/list', name: 'elevate_dxp_admin_resource_list', methods: ['GET', 'POST'])]
    public function list(string $key, Request $request): JsonResponse
    {
        return $this->guard($key, function (AdminResourceInterface $r) use ($request) {
            $query = [
                'start' => $request->get('start', 0),
                'limit' => $request->get('limit', 50),
                'q' => (string) $request->get('q', ''),
                'filters' => $this->decodeArray($request->get('filters')),
            ];
            // ExtJS sends sort=[{"property":"name","direction":"ASC"}]
            $sort = $this->decodeArray($request->get('sort'));
            if (isset($sort[0]['property'])) {
                $query['sort'] = (string) $sort[0]['property'];
                $query['dir'] = (string) ($sort[0]['direction'] ?? 'ASC');
            }
            $result = $r->list($query);

            return ['success' => true] + $result;
        });
    }

    #[Route('/r/{key}/get', name: 'elevate_dxp_admin_resource_get', methods: ['GET'])]
    public function get(string $key, Request $request): JsonResponse
    {
        return $this->guard($key, function (AdminResourceInterface $r) use ($request) {
            $record = $r->get((string) $request->query->get('id', ''));
            if ($record === null) {
                throw new \InvalidArgumentException('Record not found.');
            }

            return ['success' => true, 'data' => $record];
        });
    }

    #[Route('/r/{key}/save', name: 'elevate_dxp_admin_resource_save', methods: ['POST'])]
    public function save(string $key, Request $request): JsonResponse
    {
        return $this->guard($key, function (AdminResourceInterface $r) use ($request) {
            $schema = $r->getSchema();
            if (($schema['canEdit'] ?? true) === false && ($schema['canCreate'] ?? true) === false) {
                throw new \InvalidArgumentException('Resource is read-only.');
            }
            $data = $this->body($request)['data'] ?? [];
            $record = $r->save(\is_array($data) ? $data : []);
            $this->log($r, 'save', ['id' => $record[$schema['idProperty'] ?? 'id'] ?? null]);

            return ['success' => true, 'data' => $record];
        });
    }

    #[Route('/r/{key}/delete', name: 'elevate_dxp_admin_resource_delete', methods: ['POST', 'DELETE'])]
    public function delete(string $key, Request $request): JsonResponse
    {
        return $this->guard($key, function (AdminResourceInterface $r) use ($request) {
            if (($r->getSchema()['canDelete'] ?? true) === false) {
                throw new \InvalidArgumentException('Delete is not allowed.');
            }
            $id = (string) ($this->body($request)['id'] ?? $request->get('id', ''));
            $r->delete($id);
            $this->log($r, 'delete', ['id' => $id]);

            return ['success' => true];
        });
    }

    #[Route('/r/{key}/action/{action}', name: 'elevate_dxp_admin_resource_action', methods: ['POST'], requirements: ['action' => '[A-Za-z0-9_\-]+'])]
    public function action(string $key, string $action, Request $request): JsonResponse
    {
        return $this->guard($key, function (AdminResourceInterface $r) use ($action, $request) {
            $declared = array_column($r->getSchema()['actions'] ?? [], 'name');
            if (!\in_array($action, $declared, true)) {
                throw new \InvalidArgumentException(\sprintf('Action "%s" is not declared.', $action));
            }
            $body = $this->body($request);
            $id = isset($body['id']) && $body['id'] !== '' ? (string) $body['id'] : null;
            $result = $r->runAction($action, $id, \is_array($body['params'] ?? null) ? $body['params'] : []);
            $this->log($r, 'action.'.$action, ['id' => $id]);

            return $result + ['success' => true];
        });
    }

    /**
     * @param callable(AdminResourceInterface): array<string, mixed> $fn
     */
    private function guard(string $key, callable $fn): JsonResponse
    {
        if (!$this->registry->has($key)) {
            return new JsonResponse(['success' => false, 'message' => 'Unknown resource.'], 404);
        }
        $resource = $this->registry->get($key);
        if (!$this->registry->isAllowed($resource, $this->user())) {
            return new JsonResponse(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        try {
            return new JsonResponse($fn($resource));
        } catch (\InvalidArgumentException|\JsonException|\DomainException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    private function user(): ?User
    {
        $user = $this->getOpenDxpUser();

        return $user instanceof User ? $user : null;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $content = $request->getContent();
        if ($content !== '' && str_starts_with(ltrim($content), '{')) {
            return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        }

        return $request->request->all();
    }

    /** @return array<array-key, mixed> */
    private function decodeArray(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (\is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** @param array<string, mixed> $context */
    private function log(AdminResourceInterface $r, string $what, array $context): void
    {
        $this->audit->log(new AuditEvent($r->getKey().'.'.$what, $this->user()?->getName() ?? 'anonymous', 'ok', $context));
    }
}

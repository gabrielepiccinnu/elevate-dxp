<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Admin;

use OpenDxp\Model\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class AdminResourceRegistry
{
    /** @var array<string, AdminResourceInterface> */
    private array $resources = [];

    /**
     * @param iterable<AdminResourceInterface> $resources
     * @param array<string, bool>              $enabledModules module namespace segment (e.g. "Portal") => enabled
     */
    public function __construct(
        #[AutowireIterator(AdminResourceInterface::TAG)] iterable $resources,
        #[Autowire('%elevate_dxp.enabled_modules%')] array $enabledModules = [],
    ) {
        foreach ($resources as $resource) {
            $segment = explode('\\', $resource::class)[1] ?? '';
            if (($enabledModules[$segment] ?? true) === false) {
                continue;
            }
            $this->resources[$resource->getKey()] = $resource;
        }
        ksort($this->resources);
    }

    public function has(string $key): bool
    {
        return isset($this->resources[$key]);
    }

    public function get(string $key): AdminResourceInterface
    {
        return $this->resources[$key] ?? throw new \InvalidArgumentException(\sprintf('Unknown resource "%s".', $key));
    }

    /** @return array<string, AdminResourceInterface> */
    public function all(): array
    {
        return $this->resources;
    }

    public function isAllowed(AdminResourceInterface $resource, ?User $user): bool
    {
        return $user !== null && ($user->isAdmin() || $user->isAllowed($resource->getPermission()));
    }

    /**
     * Menu payload for the ExtJS startup script.
     *
     * @return list<array{key: string, label: string, group: string, iconCls: string, panel: mixed, jsClass: mixed}>
     */
    public function features(?User $user): array
    {
        $out = [];
        foreach ($this->resources as $r) {
            if (!$this->isAllowed($r, $user)) {
                continue;
            }
            $schema = $r->getSchema();
            $out[] = [
                'key' => $r->getKey(),
                'label' => $r->getLabel(),
                'group' => $r->getGroup(),
                'iconCls' => $r->getIconCls(),
                'panel' => $schema['panel'] ?? 'crud',
                'jsClass' => $schema['jsClass'] ?? null,
            ];
        }

        return $out;
    }
}

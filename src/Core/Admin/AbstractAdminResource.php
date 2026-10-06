<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Admin;

/**
 * Sensible defaults: read-only, no actions. Override what the feature supports.
 */
abstract class AbstractAdminResource implements AdminResourceInterface
{
    public function getGroup(): string
    {
        return 'General';
    }

    public function getIconCls(): string
    {
        return 'opendxp_icon_settings';
    }

    public function list(array $query): array
    {
        return ['data' => [], 'total' => 0];
    }

    public function get(string $id): ?array
    {
        return null;
    }

    public function save(array $data): array
    {
        throw new \InvalidArgumentException(\sprintf('Resource "%s" is read-only.', $this->getKey()));
    }

    public function delete(string $id): void
    {
        throw new \InvalidArgumentException(\sprintf('Resource "%s" does not support delete.', $this->getKey()));
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        throw new \InvalidArgumentException(\sprintf('Unknown action "%s" for resource "%s".', $action, $this->getKey()));
    }

    /**
     * Helper for in-memory lists (config-backed resources): filter by q, sort, page.
     *
     * @param list<array<string,mixed>> $rows
     */
    protected function paginate(array $rows, array $query, array $searchKeys = ['name', 'key', 'id']): array
    {
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($q, $searchKeys): bool {
                foreach ($searchKeys as $k) {
                    if (isset($row[$k]) && \is_scalar($row[$k]) && stripos((string) $row[$k], $q) !== false) {
                        return true;
                    }
                }

                return false;
            }));
        }
        $sort = $query['sort'] ?? null;
        if (\is_string($sort) && $sort !== '') {
            $dir = strtoupper((string) ($query['dir'] ?? 'ASC')) === 'DESC' ? -1 : 1;
            usort($rows, static fn (array $a, array $b): int => $dir * (($a[$sort] ?? null) <=> ($b[$sort] ?? null)));
        }
        $total = \count($rows);
        $start = max(0, (int) ($query['start'] ?? 0));
        $limit = (int) ($query['limit'] ?? 0);

        return ['data' => $limit > 0 ? \array_slice($rows, $start, $limit) : $rows, 'total' => $total];
    }
}

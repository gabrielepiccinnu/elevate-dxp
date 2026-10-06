<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Repository;

use Doctrine\DBAL\Connection;

/**
 * Per-user saved searches/views for the portal (query + type + filters), owner-scoped.
 *
 * @phpstan-type SavedView array{id: int, owner: string, name: string, params: array<string, mixed>, created_at: string}
 */
class SavedViewRepository
{
    private const SORTABLE = ['id', 'name', 'owner', 'created_at'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $params */
    public function create(string $owner, string $name, array $params): int
    {
        $this->db->executeStatement(
            'INSERT INTO edxp_portal_saved_view (owner, name, params_json, created_at) VALUES (?, ?, ?, NOW())',
            [$owner, self::name($name), json_encode($params, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)],
        );

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $params */
    public function update(int $id, ?string $owner, string $name, array $params): void
    {
        $this->db->executeStatement(
            'UPDATE edxp_portal_saved_view SET name = ?, params_json = ? WHERE id = ?'.($owner !== null ? ' AND owner = ?' : ''),
            array_merge([self::name($name), json_encode($params, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), $id], $owner !== null ? [$owner] : []),
        );
    }

    /** @return array{rows: list<SavedView>, total: int} */
    public function search(?string $owner, string $q, int $start, int $limit, string $sort = 'id', string $dir = 'DESC'): array
    {
        $where = [];
        $params = [];
        if ($owner !== null) {
            $where[] = 'owner = ?';
            $params[] = $owner;
        }
        if (trim($q) !== '') {
            $where[] = 'name LIKE ?';
            $params[] = '%'.addcslashes(trim($q), '\\%_').'%';
        }
        $whereSql = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM edxp_portal_saved_view'.$whereSql, $params);
        $sql = 'SELECT id, owner, name, params_json, created_at FROM edxp_portal_saved_view'.$whereSql
            .' ORDER BY '.(\in_array($sort, self::SORTABLE, true) ? $sort : 'id').' '.(strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC');
        if ($limit > 0) {
            $sql .= ' LIMIT '.min(1000, $limit).' OFFSET '.max(0, $start);
        }

        return ['rows' => array_map(self::hydrate(...), $this->db->fetchAllAssociative($sql, $params)), 'total' => $total];
    }

    /** @return SavedView|null */
    public function find(int $id, ?string $owner): ?array
    {
        $row = $owner !== null
            ? $this->db->fetchAssociative('SELECT * FROM edxp_portal_saved_view WHERE id = ? AND owner = ?', [$id, $owner])
            : $this->db->fetchAssociative('SELECT * FROM edxp_portal_saved_view WHERE id = ?', [$id]);

        return $row === false ? null : self::hydrate($row);
    }

    public function delete(int $id, ?string $owner): void
    {
        $this->db->executeStatement(
            'DELETE FROM edxp_portal_saved_view WHERE id = ?'.($owner !== null ? ' AND owner = ?' : ''),
            array_merge([$id], $owner !== null ? [$owner] : []),
        );
    }

    /**
     * @param array<string, mixed> $r raw database row
     *
     * @return SavedView
     */
    private static function hydrate(array $r): array
    {
        $params = json_decode((string) ($r['params_json'] ?? ''), true);

        return [
            'id' => (int) $r['id'],
            'owner' => (string) $r['owner'],
            'name' => (string) $r['name'],
            'params' => \is_array($params) ? $params : [],
            'created_at' => (string) $r['created_at'],
        ];
    }

    private static function name(string $name): string
    {
        $name = trim($name);

        return $name === '' ? 'View' : mb_substr($name, 0, 190);
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Repository;

use Doctrine\DBAL\Connection;

/**
 * DBAL persistence for portal collections (named carts) and their guest share tokens.
 *
 * Every owner-facing method takes an optional $owner scope: a string restricts to that owner,
 * null means "any owner" and is only passed for admins.
 */
class CollectionRepository
{
    public const TABLE = 'edxp_portal_collection';
    public const ITEM_TABLE = 'edxp_portal_collection_item';
    private const SORTABLE = ['id', 'name', 'owner', 'created_at', 'share_expires_at', 'item_count'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @param list<array{type:string,id:int}> $items */
    public function create(string $owner, string $name, array $items): int
    {
        $this->db->executeStatement(
            'INSERT INTO edxp_portal_collection (owner, name, created_at) VALUES (?, ?, NOW())',
            [$owner, self::name($name)],
        );
        $id = (int) $this->db->lastInsertId();
        $this->addItems($id, $items);

        return $id;
    }

    public function rename(int $id, string $name, ?string $owner): void
    {
        $this->db->executeStatement(
            'UPDATE edxp_portal_collection SET name = ? WHERE id = ?'.($owner !== null ? ' AND owner = ?' : ''),
            array_merge([self::name($name), $id], $owner !== null ? [$owner] : []),
        );
    }

    /** @param list<array{type:string,id:int}> $items */
    public function addItems(int $collectionId, array $items): void
    {
        foreach ($items as $it) {
            $this->db->executeStatement(
                'INSERT INTO edxp_portal_collection_item (collection_id, element_type, element_id, created_at)
                 VALUES (:c, :t, :i, NOW()) ON DUPLICATE KEY UPDATE element_id = element_id',
                ['c' => $collectionId, 't' => $it['type'] === 'asset' ? 'asset' : 'object', 'i' => (int) $it['id']],
            );
        }
    }

    /** @param list<array{type:string,id:int}> $items replaces all items */
    public function replaceItems(int $collectionId, array $items): void
    {
        $this->db->transactional(function () use ($collectionId, $items): void {
            $this->db->executeStatement('DELETE FROM edxp_portal_collection_item WHERE collection_id = ?', [$collectionId]);
            $this->addItems($collectionId, $items);
        });
    }

    public function delete(int $id, ?string $owner): bool
    {
        if ($this->find($id, $owner) === null) {
            return false;
        }
        $this->db->transactional(function () use ($id): void {
            $this->db->executeStatement('DELETE FROM edxp_portal_collection_item WHERE collection_id = ?', [$id]);
            $this->db->executeStatement('DELETE FROM edxp_portal_collection WHERE id = ?', [$id]);
        });

        return true;
    }

    /** @return list<array{id:int,name:string,count:int,created_at:string}> legacy shape */
    public function allForOwner(string $owner): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'count' => (int) $r['item_count'],
            'created_at' => (string) $r['created_at'],
        ], $this->search($owner, '', 0, 0)['rows']);
    }

    /**
     * Paged listing with item counts and share state.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(?string $owner, string $q, int $start, int $limit, string $sort = 'id', string $dir = 'DESC', bool $sharedOnly = false): array
    {
        $where = [];
        $params = [];
        if ($owner !== null) {
            $where[] = 'c.owner = ?';
            $params[] = $owner;
        }
        if (trim($q) !== '') {
            $where[] = '(c.name LIKE ? OR c.owner LIKE ?)';
            $like = '%'.addcslashes(trim($q), '\\%_').'%';
            array_push($params, $like, $like);
        }
        if ($sharedOnly) {
            $where[] = 'c.share_token IS NOT NULL';
        }
        $whereSql = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM edxp_portal_collection c'.$whereSql, $params);

        $sort = \in_array($sort, self::SORTABLE, true) ? $sort : 'id';
        $sql = 'SELECT c.id, c.owner, c.name, c.created_at, c.share_token, c.share_expires_at,
                       (c.share_expires_at IS NOT NULL AND c.share_expires_at > NOW()) AS share_active,
                       (SELECT COUNT(*) FROM edxp_portal_collection_item i WHERE i.collection_id = c.id) AS item_count
                FROM edxp_portal_collection c'.$whereSql
            .' ORDER BY '.($sort === 'item_count' ? 'item_count' : 'c.'.$sort).' '.(strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC');
        if ($limit > 0) {
            $sql .= ' LIMIT '.min(1000, $limit).' OFFSET '.max(0, $start);
        }

        return ['rows' => $this->db->fetchAllAssociative($sql, $params), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, ?string $owner): ?array
    {
        $row = $owner !== null
            ? $this->db->fetchAssociative('SELECT * FROM edxp_portal_collection WHERE id = ? AND owner = ?', [$id, $owner])
            : $this->db->fetchAssociative('SELECT * FROM edxp_portal_collection WHERE id = ?', [$id]);

        return $row === false ? null : $row;
    }

    /** Generates and stores a fresh share token with expiry; returns the token (null = not found/not owned). */
    public function share(int $id, ?string $owner, int $ttlDays = 7): ?string
    {
        if ($this->find($id, $owner) === null) {
            return null;
        }
        $token = self::newToken();
        $this->db->executeStatement(
            'UPDATE edxp_portal_collection
             SET share_token = :t, share_expires_at = DATE_ADD(NOW(), INTERVAL :d DAY)
             WHERE id = :id',
            ['t' => $token, 'd' => max(1, $ttlDays), 'id' => $id],
        );

        return $token;
    }

    /** Extends an existing share without changing its token. */
    public function extendShare(int $id, ?string $owner, int $ttlDays): bool
    {
        $row = $this->find($id, $owner);
        if ($row === null || empty($row['share_token'])) {
            return false;
        }
        $this->db->executeStatement(
            'UPDATE edxp_portal_collection SET share_expires_at = DATE_ADD(NOW(), INTERVAL :d DAY) WHERE id = :id',
            ['d' => max(1, $ttlDays), 'id' => $id],
        );

        return true;
    }

    public function revokeShare(int $id, ?string $owner): bool
    {
        if ($this->find($id, $owner) === null) {
            return false;
        }
        $this->db->executeStatement(
            'UPDATE edxp_portal_collection SET share_token = NULL, share_expires_at = NULL WHERE id = ?',
            [$id],
        );

        return true;
    }

    /** Clears expired tokens (within the owner scope); returns the number of links removed. */
    public function revokeExpired(?string $owner): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE edxp_portal_collection SET share_token = NULL, share_expires_at = NULL
             WHERE share_token IS NOT NULL AND (share_expires_at IS NULL OR share_expires_at <= NOW())'
            .($owner !== null ? ' AND owner = ?' : ''),
            $owner !== null ? [$owner] : [],
        );
    }

    /** @return array<string,mixed>|null collection if the token is well-formed, valid AND not expired */
    public function findByToken(string $token): ?array
    {
        if (!self::isWellFormedToken($token)) {
            return null;
        }
        $row = $this->db->fetchAssociative(
            'SELECT * FROM edxp_portal_collection
             WHERE share_token = ? AND share_expires_at IS NOT NULL AND share_expires_at > NOW()',
            [$token],
        );

        return $row === false ? null : $row;
    }

    /** @return list<array{type:string,id:int}> */
    public function items(int $collectionId): array
    {
        return array_map(static fn (array $r): array => [
            'type' => (string) $r['element_type'],
            'id' => (int) $r['element_id'],
        ], $this->db->fetchAllAssociative(
            'SELECT element_type, element_id FROM edxp_portal_collection_item WHERE collection_id = ? ORDER BY id',
            [$collectionId],
        ));
    }

    /** 160-bit random token, hex encoded (40 chars), matching the public route requirement. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    public static function isWellFormedToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{40}$/', $token) === 1;
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Collection';
        }

        return mb_substr($name, 0, 190);
    }
}

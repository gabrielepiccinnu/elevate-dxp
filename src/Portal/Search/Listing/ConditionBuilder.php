<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Search\Listing;

/**
 * Builds a parameterised SQL WHERE fragment for OpenDXP listings (setCondition($sql, $params)).
 *
 * Values are always bound as "?" parameters. Identifiers must match a strict pattern and are
 * backtick-quoted, so request input can never inject SQL; callers additionally restrict field
 * names to an allow-list.
 */
final class ConditionBuilder
{
    public const MAX_TERMS = 8;

    /** @var list<string> */
    private array $parts = [];

    /** @var list<mixed> */
    private array $params = [];

    public static function quoteIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid field name "%s".', $name));
        }

        return '`'.$name.'`';
    }

    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    /** @return list<string> whitespace-separated terms, deduplicated, bounded */
    public static function terms(string $text): array
    {
        $terms = preg_split('/\s+/u', trim($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return \array_slice(array_values(array_unique($terms)), 0, self::MAX_TERMS);
    }

    /**
     * Full-text LIKE: every term must match at least one field (AND of ORs).
     *
     * @param list<string> $fields     column names
     * @param list<string> $rawClauses extra trusted SQL templates containing exactly one "?" each,
     *                                 e.g. "`id` IN (SELECT cid FROM assets_metadata WHERE data LIKE ?)"
     */
    public function text(array $fields, ?string $text, array $rawClauses = []): self
    {
        if ($text === null || trim($text) === '') {
            return $this;
        }
        if ($fields === [] && $rawClauses === []) {
            throw new \InvalidArgumentException('No searchable fields configured.');
        }
        $quoted = array_map(self::quoteIdentifier(...), array_values(array_unique($fields)));
        foreach (self::terms($text) as $term) {
            $like = '%'.self::escapeLike($term).'%';
            $or = [];
            foreach ($quoted as $col) {
                $or[] = $col.' LIKE ?';
                $this->params[] = $like;
            }
            foreach ($rawClauses as $clause) {
                if (substr_count($clause, '?') !== 1) {
                    throw new \LogicException('Raw text clauses must contain exactly one placeholder.');
                }
                $or[] = $clause;
                $this->params[] = $like;
            }
            $this->parts[] = '('.implode(' OR ', $or).')';
        }

        return $this;
    }

    public function range(string $field, ?float $min, ?float $max): self
    {
        $col = self::quoteIdentifier($field);
        if ($min !== null) {
            $this->parts[] = $col.' >= ?';
            $this->params[] = $min;
        }
        if ($max !== null) {
            $this->parts[] = $col.' <= ?';
            $this->params[] = $max;
        }

        return $this;
    }

    /** Restricts to elements below a folder ("path" holds the parent path with a trailing slash). */
    public function pathPrefix(string $column, ?string $prefix): self
    {
        if ($prefix === null || trim($prefix) === '' || trim($prefix) === '/') {
            return $this;
        }
        $this->parts[] = self::quoteIdentifier($column).' LIKE ?';
        $this->params[] = self::escapeLike(self::normaliseFolder($prefix)).'%';

        return $this;
    }

    /**
     * OR of several folder prefixes (deny-by-default allow-list). An empty list matches nothing.
     *
     * @param list<string> $prefixes
     */
    public function anyPathPrefix(string $column, array $prefixes): self
    {
        $col = self::quoteIdentifier($column);
        $or = [];
        foreach ($prefixes as $prefix) {
            if (trim($prefix) === '') {
                continue;
            }
            $or[] = $col.' LIKE ?';
            $this->params[] = self::escapeLike(self::normaliseFolder($prefix)).'%';
        }
        $this->parts[] = $or === [] ? '1 = 0' : '('.implode(' OR ', $or).')';

        return $this;
    }

    public function notEquals(string $field, string|int $value): self
    {
        $this->parts[] = self::quoteIdentifier($field).' != ?';
        $this->params[] = $value;

        return $this;
    }

    /** @return array{0:string,1:list<mixed>} condition ('' when empty) and positional params */
    public function build(): array
    {
        return [implode(' AND ', $this->parts), $this->params];
    }

    public static function normaliseFolder(string $path): string
    {
        $path = '/'.trim(str_replace('\\', '/', $path), '/');

        return $path === '/' ? '/' : $path.'/';
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\Report;

use Doctrine\DBAL\Connection;

/**
 * Executes config-defined, READ-ONLY SQL reports. Every query passes ReadOnlySqlGuard and, when no
 * transaction is already open, runs inside a READ ONLY transaction (defense in depth).
 */
final class ReportRunner
{
    /** @param array<string,array{label?:string,sql:string,chart?:string,x?:?string,y?:?string,columns?:list<string>}> $reports */
    public function __construct(
        private readonly array $reports,
        private readonly Connection $db,
        private readonly int $maxRows = 1000,
    ) {
    }

    /** @return array<int,string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->reports));
    }

    public function has(string $name): bool
    {
        return isset($this->reports[$name]);
    }

    /** @return array{label:string,chart:string,x:?string,y:?string,columns:list<string>,sql:string}|null */
    public function meta(string $name): ?array
    {
        $r = $this->reports[$name] ?? null;
        if ($r === null) {
            return null;
        }
        $label = (string) ($r['label'] ?? '');

        return [
            'label' => $label !== '' ? $label : $name,
            'chart' => (string) ($r['chart'] ?? 'bar'),
            'x' => $r['x'] ?? null,
            'y' => $r['y'] ?? null,
            'columns' => array_values(array_map('strval', $r['columns'] ?? [])),
            'sql' => (string) $r['sql'],
        ];
    }

    /**
     * @return array{columns:array<int,string>,rows:array<int,array<string,mixed>>,truncated:bool}
     *
     * @throws \RuntimeException on unknown report or unsafe SQL
     */
    public function run(string $name, ?int $limit = null): array
    {
        $sql = $this->guardedSql($name);
        $limit = max(1, min($limit ?? $this->maxRows, $this->maxRows));

        $rows = $this->readOnly(fn (): array => $this->db->fetchAllAssociative($sql));
        $truncated = \count($rows) > $limit;
        if ($truncated) {
            $rows = \array_slice($rows, 0, $limit);
        }
        $columns = $rows === [] ? [] : array_map('strval', array_keys($rows[0]));

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Column names of a report: the configured list, otherwise the keys of the first row.
     * SELECT queries are wrapped in "LIMIT 1" so only one row is fetched.
     *
     * @return list<string>
     */
    public function columns(string $name): array
    {
        $meta = $this->meta($name) ?? throw new \RuntimeException("Unknown report '$name'.");
        if ($meta['columns'] !== []) {
            return $meta['columns'];
        }
        $sql = $this->guardedSql($name);
        if (str_starts_with(strtolower($sql), 'select')) {
            $sql = 'SELECT * FROM ('.$sql.') AS edxp_report_columns LIMIT 1';
        }
        $row = $this->readOnly(fn (): array|false => $this->db->fetchAssociative($sql));

        return \is_array($row) ? array_map('strval', array_keys($row)) : [];
    }

    private function guardedSql(string $name): string
    {
        $report = $this->reports[$name] ?? null;
        if ($report === null) {
            throw new \RuntimeException("Unknown report '$name'.");
        }

        return ReadOnlySqlGuard::assertReadOnly((string) $report['sql']);
    }

    /**
     * @template T
     *
     * @param callable():T $fn
     *
     * @return T
     */
    private function readOnly(callable $fn): mixed
    {
        if ($this->db->isTransactionActive()) {
            return $fn();
        }
        $this->db->executeStatement('START TRANSACTION READ ONLY');
        try {
            return $fn();
        } finally {
            $this->db->executeStatement('ROLLBACK');
        }
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Admin;

use Doctrine\DBAL\Connection;

/**
 * Generic CRUD over one table, driven by the schema fields.
 *
 * Only declared field names are ever used as identifiers (sort, filter, write), so request
 * input never reaches SQL as an identifier. Fields of type json/keyvalue/tags are stored as JSON.
 */
abstract class AbstractDbalResource extends AbstractAdminResource
{
    public function __construct(protected readonly Connection $db)
    {
    }

    abstract protected function getTable(): string;

    /** Columns searched by the free-text "q" filter. */
    protected function getSearchColumns(): array
    {
        return ['name'];
    }

    protected function getDefaultSort(): array
    {
        return ['id', 'DESC'];
    }

    /** Hook to validate/normalise data before it is written. */
    protected function beforeSave(array $data, ?array $existing): array
    {
        return $data;
    }

    /** Hook to enrich a row for the UI. */
    protected function decorate(array $row): array
    {
        return $row;
    }

    public function list(array $query): array
    {
        $fields = $this->fieldMap();
        $qb = $this->db->createQueryBuilder()->from($this->getTable(), 't');

        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $or = [];
            foreach ($this->getSearchColumns() as $i => $col) {
                $or[] = 't.'.$this->db->quoteIdentifier($col).' LIKE :q';
            }
            if ($or !== []) {
                $qb->andWhere('('.implode(' OR ', $or).')')->setParameter('q', '%'.addcslashes($q, '%_').'%');
            }
        }
        foreach ((array) ($query['filters'] ?? []) as $name => $value) {
            if (!isset($fields[$name]) || $value === '' || $value === null) {
                continue;
            }
            $param = 'f_'.preg_replace('/\W/', '_', (string) $name);
            $qb->andWhere('t.'.$this->db->quoteIdentifier((string) $name).' = :'.$param)->setParameter($param, $value);
        }

        $total = (int) (clone $qb)->select('COUNT(*)')->executeQuery()->fetchOne();

        [$sort, $dir] = $this->getDefaultSort();
        if (isset($query['sort']) && (isset($fields[$query['sort']]) || $query['sort'] === $this->idProperty())) {
            $sort = (string) $query['sort'];
            $dir = strtoupper((string) ($query['dir'] ?? 'ASC'));
        }
        $qb->select('t.*')->orderBy('t.'.$this->db->quoteIdentifier($sort), $dir === 'DESC' ? 'DESC' : 'ASC');

        $limit = (int) ($query['limit'] ?? 50);
        if ($limit > 0) {
            $qb->setFirstResult(max(0, (int) ($query['start'] ?? 0)))->setMaxResults(min($limit, 1000));
        }

        $rows = array_map(fn (array $r): array => $this->decorate($this->decode($r)), $qb->executeQuery()->fetchAllAssociative());

        return ['data' => $rows, 'total' => $total];
    }

    public function get(string $id): ?array
    {
        $row = $this->db->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE %s = ?', $this->db->quoteIdentifier($this->getTable()), $this->db->quoteIdentifier($this->idProperty())),
            [$id],
        );

        return $row === false ? null : $this->decorate($this->decode($row));
    }

    public function save(array $data): array
    {
        $idProp = $this->idProperty();
        $id = isset($data[$idProp]) && $data[$idProp] !== '' && $data[$idProp] !== null ? (string) $data[$idProp] : null;
        $existing = $id !== null ? $this->get($id) : null;
        if ($id !== null && $existing === null) {
            throw new \InvalidArgumentException('Record not found: '.$id);
        }

        $data = $this->beforeSave($data, $existing);
        $row = $this->encode($data);

        if ($existing === null) {
            $this->db->insert($this->db->quoteIdentifier($this->getTable()), $this->quotedColumns($row));
            $id = (string) $this->db->lastInsertId();
        } else {
            $this->db->update($this->db->quoteIdentifier($this->getTable()), $this->quotedColumns($row), [$this->db->quoteIdentifier($idProp) => $id]);
        }

        return $this->get($id) ?? [];
    }

    public function delete(string $id): void
    {
        $this->db->delete($this->db->quoteIdentifier($this->getTable()), [$this->db->quoteIdentifier($this->idProperty()) => $id]);
    }

    protected function idProperty(): string
    {
        return (string) ($this->getSchema()['idProperty'] ?? 'id');
    }

    /** @return array<string, array<string,mixed>> */
    protected function fieldMap(): array
    {
        $map = [];
        foreach ($this->getSchema()['fields'] ?? [] as $f) {
            $map[$f['name']] = $f;
        }

        return $map;
    }

    /** Keeps only writable, declared columns and serialises structured values. */
    protected function encode(array $data): array
    {
        $row = [];
        foreach ($this->fieldMap() as $name => $f) {
            if ($name === $this->idProperty() || !empty($f['readOnly']) || !empty($f['virtual']) || !\array_key_exists($name, $data)) {
                continue;
            }
            $value = $data[$name];
            $type = $f['type'] ?? 'text';
            if (Field::isStructured($type)) {
                if (\is_string($value) && $value !== '') {
                    json_decode($value, true, 512, \JSON_THROW_ON_ERROR); // validate
                } else {
                    $value = json_encode($value ?? ($type === 'json' ? new \stdClass() : []), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                }
            } elseif ($type === 'bool') {
                $value = filter_var($value, \FILTER_VALIDATE_BOOL) ? 1 : 0;
            } elseif ($type === 'number') {
                $value = $value === '' || $value === null ? null : (is_numeric($value) ? $value + 0 : throw new \InvalidArgumentException(\sprintf('Field "%s" must be numeric.', $name)));
            } elseif (($type === 'date' || $type === 'datetime') && ($value === '' || $value === null)) {
                $value = null;
            }
            if (!empty($f['required']) && ($value === null || $value === '')) {
                throw new \InvalidArgumentException(\sprintf('Field "%s" is required.', $f['label'] ?? $name));
            }
            $row[$name] = $value;
        }

        return $row;
    }

    protected function decode(array $row): array
    {
        foreach ($this->fieldMap() as $name => $f) {
            if (\array_key_exists($name, $row) && Field::isStructured($f['type'] ?? 'text') && \is_string($row[$name])) {
                $row[$name] = json_decode($row[$name], true) ?? [];
            } elseif (\array_key_exists($name, $row) && ($f['type'] ?? '') === 'bool') {
                $row[$name] = (bool) $row[$name];
            }
        }

        return $row;
    }

    private function quotedColumns(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$this->db->quoteIdentifier($k)] = $v;
        }

        return $out;
    }
}

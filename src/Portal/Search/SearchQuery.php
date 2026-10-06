<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Search;

/**
 * Immutable, backend-neutral portal search request.
 *
 * Built from user input with {@see fromArray()} (admin report filters, saved views), which
 * normalises and bounds every value. Field names are validated later by the backend against
 * its own allow-list, never trusted here.
 */
final class SearchQuery
{
    public const TYPE_ASSET = 'asset';
    public const TYPE_OBJECT = 'object';

    /**
     * @param list<NumericRange>   $ranges
     * @param array<string,string> $orderBy field => ASC|DESC
     */
    public function __construct(
        public readonly string $type = self::TYPE_ASSET,
        public readonly ?string $text = null,
        public readonly ?string $className = null,
        public readonly array $ranges = [],
        public readonly ?string $pathPrefix = null,
        public readonly bool $excludeFolders = true,
        public readonly array $orderBy = [],
        public readonly int $page = 1,
        public readonly int $pageSize = 24,
    ) {
        if (!\in_array($type, [self::TYPE_ASSET, self::TYPE_OBJECT], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown search type "%s".', $type));
        }
        if ($page < 1 || $pageSize < 1) {
            throw new \InvalidArgumentException('page and pageSize must be positive.');
        }
    }

    /**
     * Accepted keys: type, q|text, class, path, min, max, range_field, ranges[{field,min,max}],
     * order_by, order_dir, page, page_size (or start + limit).
     *
     * @param array<string, mixed> $p
     */
    public static function fromArray(array $p, int $defaultPageSize = 24, int $maxPageSize = 100, ?string $defaultClass = null): self
    {
        $type = (string) ($p['type'] ?? '') === self::TYPE_OBJECT ? self::TYPE_OBJECT : self::TYPE_ASSET;
        $text = trim((string) ($p['q'] ?? $p['text'] ?? ''));
        $class = trim((string) ($p['class'] ?? ''));
        $path = trim((string) ($p['path'] ?? ''));

        $ranges = [];
        foreach ((array) ($p['ranges'] ?? []) as $r) {
            if (\is_array($r) && isset($r['field']) && (string) $r['field'] !== '') {
                $range = new NumericRange((string) $r['field'], self::num($r['min'] ?? null), self::num($r['max'] ?? null));
                if (!$range->isEmpty()) {
                    $ranges[] = $range;
                }
            }
        }
        $min = self::num($p['min'] ?? null);
        $max = self::num($p['max'] ?? null);
        if ($min !== null || $max !== null) {
            $ranges[] = new NumericRange(trim((string) ($p['range_field'] ?? '')) ?: 'price', $min, $max);
        }

        $orderBy = [];
        $orderField = trim((string) ($p['order_by'] ?? ''));
        if ($orderField !== '') {
            $orderBy[$orderField] = strtoupper((string) ($p['order_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        }

        $pageSize = (int) ($p['page_size'] ?? $p['limit'] ?? $defaultPageSize);
        $pageSize = max(1, min($maxPageSize, $pageSize > 0 ? $pageSize : $defaultPageSize));
        $page = isset($p['page']) ? (int) $p['page'] : intdiv(max(0, (int) ($p['start'] ?? 0)), $pageSize) + 1;

        return new self(
            $type,
            $text !== '' ? mb_substr($text, 0, 200) : null,
            $type === self::TYPE_OBJECT ? ($class !== '' ? $class : $defaultClass) : null,
            $ranges,
            $path !== '' ? $path : null,
            !\array_key_exists('include_folders', $p) || !filter_var($p['include_folders'], \FILTER_VALIDATE_BOOL),
            $orderBy,
            max(1, $page),
            $pageSize,
        );
    }

    /**
     * Serialisable form (saved views); round-trips through fromArray().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['type' => $this->type];
        if ($this->text !== null) {
            $out['q'] = $this->text;
        }
        if ($this->className !== null) {
            $out['class'] = $this->className;
        }
        if ($this->pathPrefix !== null) {
            $out['path'] = $this->pathPrefix;
        }
        if ($this->ranges !== []) {
            $out['ranges'] = array_map(static fn (NumericRange $r): array => $r->toArray(), $this->ranges);
        }
        foreach ($this->orderBy as $field => $dir) {
            $out['order_by'] = $field;
            $out['order_dir'] = $dir;
            break;
        }
        if (!$this->excludeFolders) {
            $out['include_folders'] = true;
        }
        $out['page_size'] = $this->pageSize;

        return $out;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a number.', \is_scalar($v) ? (string) $v : get_debug_type($v)));
        }

        return (float) $v;
    }
}

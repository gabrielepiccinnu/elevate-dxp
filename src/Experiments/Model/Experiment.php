<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Model;

final class Experiment
{
    public const STATUSES = ['draft' => 'Draft', 'running' => 'Running', 'paused' => 'Paused', 'completed' => 'Completed'];

    /**
     * @param list<Variant> $variants
     */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $name,
        public readonly string $status,
        public readonly int $traffic,
        public readonly ?string $goalEvent,
        public readonly array $variants,
        public readonly ?int $targetGroupId = null,
        public readonly ?string $urlPattern = null,
        public readonly ?\DateTimeImmutable $startAt = null,
        public readonly ?\DateTimeImmutable $endAt = null,
    ) {
    }

    /**
     * Builds an experiment from a database row; "variants" may be a JSON string or an already decoded list.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $variants = [];
        $raw = \is_string($row['variants'] ?? null) ? json_decode($row['variants'], true) : ($row['variants'] ?? []);
        foreach (\is_array($raw) ? $raw : [] as $v) {
            if (\is_array($v)) {
                $variants[] = Variant::fromArray($v);
            }
        }
        $date = static fn ($v): ?\DateTimeImmutable => $v ? new \DateTimeImmutable((string) $v) : null;

        return new self(
            (int) $row['id'],
            (string) $row['exp_key'],
            (string) $row['name'],
            (string) $row['status'],
            max(0, min(100, (int) $row['traffic'])),
            ($row['goal_event'] ?? '') !== '' ? (string) $row['goal_event'] : null,
            $variants,
            isset($row['target_group_id']) && $row['target_group_id'] !== '' ? (int) $row['target_group_id'] : null,
            ($row['url_pattern'] ?? '') !== '' ? (string) $row['url_pattern'] : null,
            $date($row['start_at'] ?? null),
            $date($row['end_at'] ?? null),
        );
    }

    public function totalWeight(): int
    {
        return array_sum(array_map(static fn (Variant $v): int => $v->weight, $this->variants));
    }

    public function getVariant(string $key): ?Variant
    {
        foreach ($this->variants as $v) {
            if ($v->key === $key) {
                return $v;
            }
        }

        return null;
    }

    /** The baseline for uplift: a variant named control/A/original, else the first one. */
    public function controlKey(): ?string
    {
        foreach (['control', 'A', 'a', 'original'] as $candidate) {
            if ($this->getVariant($candidate) !== null) {
                return $candidate;
            }
        }

        return $this->variants[0]->key ?? null;
    }

    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        return $this->status === 'running'
            && ($this->startAt === null || $now >= $this->startAt)
            && ($this->endAt === null || $now < $this->endAt);
    }

    /** url_pattern: a path prefix ("/shop") or a delimited regex ("#^/shop/.+#"). */
    public function matchesPath(string $path): bool
    {
        if ($this->urlPattern === null) {
            return true;
        }
        $p = $this->urlPattern;
        if (\strlen($p) > 2 && $p[0] === '#' && strrpos($p, '#') > 0) {
            return @preg_match($p, $path) === 1;
        }

        return str_starts_with($path, $p);
    }
}

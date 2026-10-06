<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Model;

final class Variant
{
    public function __construct(
        public readonly string $key,
        public readonly int $weight = 1,
        public readonly array $payload = [],
        public readonly ?int $targetGroupId = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $key = trim((string) ($data['key'] ?? ''));
        if ($key === '' || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('Invalid variant key "%s" (use letters, digits, _ or -).', $key));
        }
        $weight = (int) ($data['weight'] ?? 1);
        if ($weight < 0) {
            throw new \InvalidArgumentException(\sprintf('Variant "%s" has a negative weight.', $key));
        }
        $tg = $data['target_group_id'] ?? null;

        return new self($key, $weight, \is_array($data['payload'] ?? null) ? $data['payload'] : [], $tg !== null && $tg !== '' ? (int) $tg : null);
    }

    public function toArray(): array
    {
        return ['key' => $this->key, 'weight' => $this->weight, 'payload' => $this->payload, 'target_group_id' => $this->targetGroupId];
    }
}

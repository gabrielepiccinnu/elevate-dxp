<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Search;

/** Inclusive numeric range filter on one field; a null bound is open. */
final class NumericRange
{
    public function __construct(
        public readonly string $field,
        public readonly ?float $min = null,
        public readonly ?float $max = null,
    ) {
        if ($min !== null && $max !== null && $min > $max) {
            throw new \InvalidArgumentException(\sprintf('Range "%s": min must not be greater than max.', $field));
        }
    }

    public function isEmpty(): bool
    {
        return $this->min === null && $this->max === null;
    }

    /** @return array{field:string,min:?float,max:?float} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'min' => $this->min, 'max' => $this->max];
    }
}

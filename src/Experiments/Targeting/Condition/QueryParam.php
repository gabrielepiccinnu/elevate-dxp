<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Targeting\Condition;

use OpenDxp\Bundle\PersonalizationBundle\Targeting\Condition\AbstractVariableCondition;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Model\VisitorInfo;

/** Matches a query string parameter of the current request (e.g. ?vip=1, ?gclid=...). */
final class QueryParam extends AbstractVariableCondition
{
    public function __construct(private readonly string $name, private readonly string $mode, private readonly ?string $value)
    {
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(trim((string) ($config['name'] ?? '')), StringMatcher::mode($config), isset($config['value']) ? (string) $config['value'] : null);
    }

    public function canMatch(): bool
    {
        return $this->name !== '';
    }

    public function match(VisitorInfo $visitorInfo): bool
    {
        $raw = $visitorInfo->getRequest()->query->all()[$this->name] ?? null;
        $actual = \is_scalar($raw) ? (string) $raw : null;
        if (!StringMatcher::matches($actual, $this->mode, $this->value)) {
            return false;
        }
        $this->setMatchedVariable('query_'.$this->name, $actual);

        return true;
    }
}

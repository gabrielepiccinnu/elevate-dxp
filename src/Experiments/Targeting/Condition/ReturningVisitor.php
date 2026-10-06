<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Targeting\Condition;

use ElevateDxp\Experiments\Targeting\DataProvider\VisitorProfileDataProvider;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Condition\AbstractVariableCondition;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\DataProviderDependentInterface;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Model\VisitorInfo;

/** Matches visitors with at least N sessions (default 2 = returning visitor); "inverse" matches new visitors. */
final class ReturningVisitor extends AbstractVariableCondition implements DataProviderDependentInterface
{
    public function __construct(private readonly int $minSessions, private readonly bool $inverse)
    {
    }

    public static function fromConfig(array $config): self
    {
        return new self(max(1, (int) ($config['minSessions'] ?? 2)), filter_var($config['inverse'] ?? false, \FILTER_VALIDATE_BOOL));
    }

    public function getDataProviderKeys(): array
    {
        return [VisitorProfileDataProvider::PROVIDER_KEY];
    }

    public function canMatch(): bool
    {
        return true;
    }

    public function match(VisitorInfo $visitorInfo): bool
    {
        $sessions = (int) (((array) $visitorInfo->get(VisitorProfileDataProvider::PROVIDER_KEY, []))['sessions'] ?? 1);
        $result = ($sessions >= $this->minSessions) !== $this->inverse;
        if ($result) {
            $this->setMatchedVariable('sessions', $sessions);
        }

        return $result;
    }
}

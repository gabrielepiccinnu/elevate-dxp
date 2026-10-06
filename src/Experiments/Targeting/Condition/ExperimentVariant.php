<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Targeting\Condition;

use ElevateDxp\Experiments\Targeting\DataProvider\ExperimentsDataProvider;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Condition\AbstractVariableCondition;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\DataProviderDependentInterface;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Model\VisitorInfo;

/** Matches visitors assigned to a given experiment variant (or to any variant if empty). */
final class ExperimentVariant extends AbstractVariableCondition implements DataProviderDependentInterface
{
    public function __construct(private readonly ?string $experiment, private readonly ?string $variant)
    {
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            ($config['experiment'] ?? '') !== '' ? (string) $config['experiment'] : null,
            ($config['variant'] ?? '') !== '' ? (string) $config['variant'] : null,
        );
    }

    public function getDataProviderKeys(): array
    {
        return [ExperimentsDataProvider::PROVIDER_KEY];
    }

    public function canMatch(): bool
    {
        return $this->experiment !== null;
    }

    public function match(VisitorInfo $visitorInfo): bool
    {
        $assigned = ((array) $visitorInfo->get(ExperimentsDataProvider::PROVIDER_KEY, []))[$this->experiment] ?? null;
        if ($assigned === null || ($this->variant !== null && $assigned !== $this->variant)) {
            return false;
        }
        $this->setMatchedVariable('experiment_variant', $this->experiment.':'.$assigned);

        return true;
    }
}

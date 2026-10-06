<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Targeting\Condition;

use ElevateDxp\Experiments\Targeting\DataProvider\VisitorProfileDataProvider;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Condition\AbstractVariableCondition;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\DataProviderDependentInterface;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Model\VisitorInfo;

/**
 * Matches campaign parameters. touch=last uses the current request, falling back to the last
 * stored campaign; touch=first uses the visitor's first recorded campaign.
 */
final class Utm extends AbstractVariableCondition implements DataProviderDependentInterface
{
    public function __construct(
        private readonly string $parameter,
        private readonly string $mode,
        private readonly ?string $value,
        private readonly string $touch,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        $param = (string) ($config['parameter'] ?? 'utm_campaign');
        if (!\in_array($param, VisitorProfileDataProvider::UTM_KEYS, true)) {
            $param = 'utm_campaign';
        }

        return new self($param, StringMatcher::mode($config), isset($config['value']) ? (string) $config['value'] : null, ($config['touch'] ?? 'last') === 'first' ? 'first' : 'last');
    }

    /** @return list<string> */
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
        $profile = (array) $visitorInfo->get(VisitorProfileDataProvider::PROVIDER_KEY, []);
        $utm = (array) ($this->touch === 'first' ? ($profile['utm_first'] ?? []) : ($profile['utm'] ?? []));
        $actual = isset($utm[$this->parameter]) ? (string) $utm[$this->parameter] : null;
        if (!StringMatcher::matches($actual, $this->mode, $this->value)) {
            return false;
        }
        $this->setMatchedVariable($this->parameter, $actual);

        return true;
    }
}

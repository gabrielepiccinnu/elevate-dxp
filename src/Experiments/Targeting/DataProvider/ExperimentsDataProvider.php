<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Targeting\DataProvider;

use ElevateDxp\Experiments\Experiment\ExperimentRuntime;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\DataProvider\DataProviderInterface;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Model\VisitorInfo;

/**
 * Exposes the visitor's experiment assignments to conditions ("edxp_experiments").
 */
final class ExperimentsDataProvider implements DataProviderInterface
{
    public const PROVIDER_KEY = 'edxp_experiments';

    public function __construct(private readonly ExperimentRuntime $runtime)
    {
    }

    public function load(VisitorInfo $visitorInfo): void
    {
        $visitorInfo->set(self::PROVIDER_KEY, $this->runtime->assignAll($visitorInfo));
    }
}

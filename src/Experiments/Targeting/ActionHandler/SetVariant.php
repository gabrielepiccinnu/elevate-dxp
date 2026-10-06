<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Targeting\ActionHandler;

use ElevateDxp\Experiments\Experiment\ExperimentRuntime;
use OpenDxp\Bundle\PersonalizationBundle\Model\Tool\Targeting\Rule;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\ActionHandler\ActionHandlerInterface;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Model\VisitorInfo;

/**
 * Rule action "Set experiment variant": pins the visitor to a variant (e.g. campaign landing
 * traffic always sees variant B). The pin is sticky and flagged as forced in reports.
 */
final class SetVariant implements ActionHandlerInterface
{
    public function __construct(private readonly ExperimentRuntime $runtime)
    {
    }

    /**
     * @param array<string, mixed> $action rule action config with "experiment" and "variant" keys
     */
    public function apply(VisitorInfo $visitorInfo, array $action, ?Rule $rule = null): void
    {
        $experiment = trim((string) ($action['experiment'] ?? ''));
        $variant = trim((string) ($action['variant'] ?? ''));
        if ($experiment !== '' && $variant !== '') {
            $this->runtime->force($experiment, $variant, $visitorInfo);
        }
    }
}

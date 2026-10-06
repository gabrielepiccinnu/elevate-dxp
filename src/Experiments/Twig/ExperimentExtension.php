<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Twig;

use ElevateDxp\Experiments\Experiment\ExperimentRuntime;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\VisitorInfoStorageInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {% if edxp_in_variant('hero', 'B') %}...{% endif %}
 * {% set v = edxp_variant('hero') %}            {# null when not in the experiment #}
 * {{ edxp_variant_payload('hero').headline }}    {# per-variant JSON payload #}
 * <button data-edxp-track="signup">            {# conversion tracked by edxp-runtime.js #}.
 */
final class ExperimentExtension extends AbstractExtension
{
    public function __construct(
        private readonly ExperimentRuntime $runtime,
        private readonly VisitorInfoStorageInterface $visitorInfoStorage,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('edxp_variant', $this->runtime->variant(...)),
            new TwigFunction('edxp_in_variant', fn (string $experiment, string $variant): bool => $this->runtime->variant($experiment) === $variant),
            new TwigFunction('edxp_variant_payload', $this->runtime->payload(...)),
            new TwigFunction('edxp_experiments', function (): array {
                $this->runtime->assignAll();

                return $this->runtime->all();
            }),
            new TwigFunction('edxp_target_groups', function (): array {
                if (!$this->visitorInfoStorage->hasVisitorInfo()) {
                    return [];
                }

                return array_map(static fn ($g) => $g->getName(), $this->visitorInfoStorage->getVisitorInfo()->getAssignedTargetGroups());
            }),
        ];
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Experiment;

use ElevateDxp\Experiments\Model\Experiment;
use ElevateDxp\Experiments\Repository\ExperimentRepository;
use ElevateDxp\Experiments\Tracking\EventRecorder;
use ElevateDxp\Experiments\Visitor\VisitorIdResolver;
use OpenDxp\Bundle\PersonalizationBundle\Model\Tool\Targeting\TargetGroup;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Model\VisitorInfo;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\VisitorInfoStorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Per-request experiment state: which running experiments apply to the current visitor and
 * which variant they see.
 *
 * Works standalone (Twig helpers) and together with OpenDXP targeting: when a VisitorInfo
 * exists, experiments can be scoped to a target group and each variant's hidden target group
 * is assigned, so the stock per-target-group document editables render the variant content.
 */
final class ExperimentRuntime implements ResetInterface
{
    /** Count used for hidden variant groups so they win over other assigned groups. */
    public const VARIANT_GROUP_WEIGHT = 1000;

    /** @var array<string,string> experiment key => variant key */
    private array $assignments = [];

    /** @var array<string,Experiment> */
    private array $assignedExperiments = [];

    public function __construct(
        private readonly ExperimentRepository $experiments,
        private readonly ExperimentAssigner $assigner,
        private readonly VisitorIdResolver $visitorIdResolver,
        private readonly EventRecorder $recorder,
        private readonly RequestStack $requestStack,
        private readonly VisitorInfoStorageInterface $visitorInfoStorage,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Evaluates every running experiment not yet assigned in this request.
     *
     * @return array<string,string>
     */
    public function assignAll(?VisitorInfo $visitorInfo = null): array
    {
        $request = $this->requestStack->getMainRequest();
        if (!$this->enabled || $request === null || !$this->visitorIdResolver->isTrackable($request)) {
            return $this->assignments;
        }
        $visitorInfo ??= $this->visitorInfoStorage->hasVisitorInfo() ? $this->visitorInfoStorage->getVisitorInfo() : null;
        $now = new \DateTimeImmutable();

        foreach ($this->experiments->findRunning() as $experiment) {
            if (isset($this->assignments[$experiment->key])) {
                continue;
            }
            if (!$experiment->isActiveAt($now) || !$experiment->matchesPath($request->getPathInfo()) || !$this->inScope($experiment, $visitorInfo)) {
                continue;
            }
            $visitorId = $this->visitorIdResolver->getVisitorId();
            $result = $this->assigner->resolve($experiment, $visitorId);
            if ($result['variant'] === null) {
                continue;
            }
            $this->assignments[$experiment->key] = $result['variant'];
            $this->assignedExperiments[$experiment->key] = $experiment;
            if ($result['new']) {
                $this->recorder->record($visitorId, 'exposure', 'exposure', null, $request->getUri(), $experiment->key, $result['variant']);
            }
        }

        if ($visitorInfo !== null) {
            $this->applyVariantGroups($visitorInfo);
        }

        return $this->assignments;
    }

    public function variant(string $experimentKey): ?string
    {
        $this->assignAll();

        return $this->assignments[$experimentKey] ?? null;
    }

    public function payload(string $experimentKey): array
    {
        $variant = $this->variant($experimentKey);
        if ($variant === null) {
            return [];
        }

        return $this->assignedExperiments[$experimentKey]->getVariant($variant)?->payload ?? [];
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->assignments;
    }

    /** Forces a variant for the current visitor (rule action). */
    public function force(string $experimentKey, string $variantKey, ?VisitorInfo $visitorInfo = null): bool
    {
        $experiment = null;
        foreach ($this->experiments->findRunning() as $candidate) {
            if ($candidate->key === $experimentKey) {
                $experiment = $candidate;
                break;
            }
        }
        if ($experiment === null || !$this->visitorIdResolver->isTrackable()) {
            return false;
        }
        if (!$this->assigner->force($experiment, $this->visitorIdResolver->getVisitorId(), $variantKey)) {
            return false;
        }
        $previous = $this->assignments[$experimentKey] ?? null;
        $this->assignments[$experimentKey] = $variantKey;
        $this->assignedExperiments[$experimentKey] = $experiment;
        if ($visitorInfo !== null) {
            if ($previous !== null && $previous !== $variantKey) {
                $old = $experiment->getVariant($previous)?->targetGroupId;
                if ($old && ($group = TargetGroup::getById($old))) {
                    $visitorInfo->clearAssignedTargetGroup($group);
                }
            }
            $this->applyVariantGroups($visitorInfo);
        }

        return true;
    }

    public function reset(): void
    {
        $this->assignments = [];
        $this->assignedExperiments = [];
    }

    private function inScope(Experiment $experiment, ?VisitorInfo $visitorInfo): bool
    {
        if ($experiment->targetGroupId === null) {
            return true;
        }
        if ($visitorInfo === null) {
            return false;
        }
        foreach ($visitorInfo->getAssignedTargetGroups() as $group) {
            if ($group->getId() === $experiment->targetGroupId) {
                return true;
            }
        }

        return false;
    }

    private function applyVariantGroups(VisitorInfo $visitorInfo): void
    {
        foreach ($this->assignments as $key => $variantKey) {
            $groupId = $this->assignedExperiments[$key]->getVariant($variantKey)?->targetGroupId;
            if ($groupId === null) {
                continue;
            }
            $group = TargetGroup::getById($groupId);
            if ($group !== null && $group->getActive()) {
                $visitorInfo->assignTargetGroup($group, self::VARIANT_GROUP_WEIGHT, true);
            }
        }
        $visitorInfo->set('edxp_experiments', $this->assignments);
    }
}

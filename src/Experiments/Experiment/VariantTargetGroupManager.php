<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Experiment;

use ElevateDxp\Experiments\Model\Experiment;
use ElevateDxp\Experiments\Repository\ExperimentRepository;
use OpenDxp\Bundle\PersonalizationBundle\Model\Tool\Targeting\TargetGroup;

/**
 * Creates one target group per variant ("exp:<experiment>:<variant>"). Editors then fill the
 * variant content with the stock per-target-group editing of pages and snippets.
 */
final class VariantTargetGroupManager
{
    public function __construct(private readonly ExperimentRepository $experiments)
    {
    }

    /**
     * @return list<array{variant:string, target_group_id:int, name:string, created:bool}>
     */
    public function ensure(Experiment $experiment): array
    {
        $report = [];
        $variants = [];
        foreach ($experiment->variants as $variant) {
            $group = $variant->targetGroupId !== null ? TargetGroup::getById($variant->targetGroupId) : null;
            $created = false;
            if ($group === null) {
                $name = self::groupName($experiment->key, $variant->key);
                $group = TargetGroup::getByName($name) ?? new TargetGroup();
                if ($group->getId() === null) {
                    $group->setName($name);
                    $group->setDescription(\sprintf('Elevate DXP experiment "%s", variant "%s". Managed automatically.', $experiment->name, $variant->key));
                    $group->setThreshold(1);
                    $group->setActive(true);
                    $group->save();
                    $created = true;
                }
            }
            $variants[] = ['target_group_id' => $group->getId()] + $variant->toArray();
            $report[] = ['variant' => $variant->key, 'target_group_id' => (int) $group->getId(), 'name' => $group->getName(), 'created' => $created];
        }
        $this->experiments->updateVariants($experiment->id, $variants);

        return $report;
    }

    public static function groupName(string $experimentKey, string $variantKey): string
    {
        return 'exp:'.$experimentKey.':'.$variantKey;
    }
}

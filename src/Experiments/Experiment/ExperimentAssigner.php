<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Experiment;

use ElevateDxp\Experiments\Model\Experiment;

/**
 * Deterministic, sticky variant assignment.
 *
 * - traffic gate and variant pick use independent sha256 buckets of (visitor, experiment),
 *   so the same visitor always lands in the same bucket without randomness;
 * - the first assignment is persisted, so later weight changes never move existing visitors.
 */
final class ExperimentAssigner
{
    public function __construct(private readonly AssignmentStoreInterface $store)
    {
    }

    /**
     * @return array{variant: ?string, new: bool}
     */
    public function resolve(Experiment $experiment, string $visitorId): array
    {
        if ($experiment->variants === [] || $experiment->totalWeight() <= 0) {
            return ['variant' => null, 'new' => false];
        }

        $existing = $this->store->getVariant($visitorId, $experiment->id);
        if ($existing !== null && $experiment->getVariant($existing) !== null) {
            return ['variant' => $existing, 'new' => false];
        }

        if ($experiment->traffic < 100 && self::bucket($visitorId.'|gate|'.$experiment->key, 100) >= $experiment->traffic) {
            return ['variant' => null, 'new' => false];
        }

        $variant = self::pickWeighted($experiment, $visitorId);
        $new = $this->store->store($visitorId, $experiment->id, $variant);

        return ['variant' => $variant, 'new' => $new];
    }

    /** Overrides the assignment (rule action "set variant"). */
    public function force(Experiment $experiment, string $visitorId, string $variantKey): bool
    {
        if ($experiment->getVariant($variantKey) === null) {
            return false;
        }
        $this->store->store($visitorId, $experiment->id, $variantKey, true);

        return true;
    }

    public static function pickWeighted(Experiment $experiment, string $visitorId): string
    {
        $point = self::bucket($visitorId.'|var|'.$experiment->key, $experiment->totalWeight());
        $cursor = 0;
        foreach ($experiment->variants as $variant) {
            $cursor += $variant->weight;
            if ($point < $cursor) {
                return $variant->key;
            }
        }

        return $experiment->variants[array_key_last($experiment->variants)]->key;
    }

    public static function bucket(string $seed, int $mod): int
    {
        if ($mod <= 0) {
            return 0;
        }

        return (int) (hexdec(substr(hash('sha256', $seed), 0, 8)) % $mod);
    }
}

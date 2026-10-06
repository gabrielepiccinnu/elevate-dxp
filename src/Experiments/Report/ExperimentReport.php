<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Report;

use Doctrine\DBAL\Connection;
use ElevateDxp\Experiments\Model\Experiment;

/**
 * Per-variant results. Exposure = unique assigned visitor; conversion = unique visitor who
 * fired the experiment's goal event after being assigned (attribution by join, so the client
 * never has to send experiment ids).
 */
final class ExperimentReport
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function build(Experiment $experiment): array
    {
        $counts = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT a.variant_key,
                    COUNT(DISTINCT a.visitor_id) AS visitors,
                    COUNT(DISTINCT e.visitor_id) AS conversions,
                    SUM(a.forced) AS forced
             FROM edxp_assignment a
             LEFT JOIN edxp_event e
                    ON e.visitor_id = a.visitor_id AND e.event_name = :goal AND e.created_at >= a.created_at
             WHERE a.experiment_id = :id
             GROUP BY a.variant_key',
            ['goal' => $experiment->goalEvent ?? '', 'id' => $experiment->id],
        );
        foreach ($rows as $r) {
            $counts[$r['variant_key']] = ['visitors' => (int) $r['visitors'], 'conversions' => (int) $r['conversions'], 'forced' => (int) $r['forced']];
        }

        return self::compute($experiment, $counts);
    }

    /**
     * Pure computation, unit-testable.
     *
     * @param array<string, array{visitors:int, conversions:int, forced?:int}> $counts
     *
     * @return list<array<string,mixed>>
     */
    public static function compute(Experiment $experiment, array $counts): array
    {
        $controlKey = $experiment->controlKey();
        $control = $counts[$controlKey] ?? ['visitors' => 0, 'conversions' => 0];
        $controlRate = $control['visitors'] > 0 ? $control['conversions'] / $control['visitors'] : null;

        $out = [];
        foreach ($experiment->variants as $variant) {
            $c = $counts[$variant->key] ?? ['visitors' => 0, 'conversions' => 0, 'forced' => 0];
            $rate = $c['visitors'] > 0 ? $c['conversions'] / $c['visitors'] : null;
            $isControl = $variant->key === $controlKey;
            $p = $isControl ? null : Stats::twoProportionPValue($control['conversions'], $control['visitors'], $c['conversions'], $c['visitors']);
            $out[] = [
                'variant' => $variant->key,
                'is_control' => $isControl,
                'weight' => $variant->weight,
                'visitors' => $c['visitors'],
                'forced' => (int) ($c['forced'] ?? 0),
                'conversions' => $c['conversions'],
                'conversion_rate' => $rate !== null ? round($rate * 100, 2) : null,
                'uplift' => !$isControl && $rate !== null && $controlRate ? round(($rate - $controlRate) / $controlRate * 100, 2) : null,
                'p_value' => $p !== null ? round($p, 4) : null,
                'confidence' => $p !== null ? round((1 - $p) * 100, 1) : null,
                'significant' => $p !== null && $p < 0.05,
            ];
        }

        return $out;
    }
}

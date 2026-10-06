<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\Cdp;

/**
 * Rule-based segments over first-party profiles. Pure function, unit-testable.
 */
final class SegmentEvaluator
{
    public const SEGMENTS = [
        'converters' => 'Converted at least once',
        'engaged' => '5+ page views',
        'returning' => '2+ sessions',
        'campaign' => 'Arrived from a tracked campaign',
        'experiment_exposed' => 'Exposed to an experiment',
    ];

    /**
     * @param array{conversions?:int, pageviews?:int, sessions?:int, utm_last?:mixed, experiments?:int} $p
     */
    public static function matches(string $segment, array $p): bool
    {
        return match ($segment) {
            'converters' => (int) ($p['conversions'] ?? 0) >= 1,
            'engaged' => (int) ($p['pageviews'] ?? 0) >= 5,
            'returning' => (int) ($p['sessions'] ?? 0) >= 2,
            'campaign' => !empty($p['utm_last']),
            'experiment_exposed' => (int) ($p['experiments'] ?? 0) >= 1,
            default => false,
        };
    }

    /** @return list<string> */
    public static function segmentsOf(array $profile): array
    {
        return array_values(array_filter(array_keys(self::SEGMENTS), static fn (string $s): bool => self::matches($s, $profile)));
    }
}

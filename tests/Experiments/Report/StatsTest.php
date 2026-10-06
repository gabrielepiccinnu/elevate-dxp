<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Experiments\Report;

use ElevateDxp\Experiments\Model\Experiment;
use ElevateDxp\Experiments\Model\Variant;
use ElevateDxp\Experiments\Report\ExperimentReport;
use ElevateDxp\Experiments\Report\Stats;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    public function testNormalCdf(): void
    {
        self::assertEqualsWithDelta(0.5, Stats::normalCdf(0), 1e-6);
        self::assertEqualsWithDelta(0.975, Stats::normalCdf(1.959964), 1e-4);
    }

    public function testPValue(): void
    {
        // 10% vs 12% on 10k each: z ≈ 4.5, clearly significant
        self::assertLessThan(0.001, Stats::twoProportionPValue(1000, 10000, 1200, 10000));
        // identical rates: p = 1
        self::assertEqualsWithDelta(1.0, Stats::twoProportionPValue(50, 1000, 50, 1000), 1e-6);
        self::assertNull(Stats::twoProportionPValue(0, 0, 1, 10));
    }

    public function testSampleSize(): void
    {
        $n = Stats::sampleSizePerVariant(0.03, 0.10);
        self::assertGreaterThan(50000, $n);
        self::assertLessThan(56000, $n);
        self::assertNull(Stats::sampleSizePerVariant(0, 0.1));
    }

    public function testReportComputation(): void
    {
        $exp = new Experiment(1, 'hero', 'Hero', 'running', 100, 'signup', [new Variant('A', 50), new Variant('B', 50)]);
        $rows = ExperimentReport::compute($exp, [
            'A' => ['visitors' => 10000, 'conversions' => 1000],
            'B' => ['visitors' => 10000, 'conversions' => 1200],
        ]);
        self::assertTrue($rows[0]['is_control']);
        self::assertNull($rows[0]['p_value']);
        self::assertSame(10.0, $rows[0]['conversion_rate']);
        self::assertSame(20.0, $rows[1]['uplift']);
        self::assertTrue($rows[1]['significant']);
    }
}

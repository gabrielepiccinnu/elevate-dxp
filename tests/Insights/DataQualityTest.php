<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Insights;

use ElevateDxp\Insights\DataQuality\QualityService;
use PHPUnit\Framework\TestCase;

/** Smoke tests for the pure scoring/segmentation logic (no DB). */
final class DataQualityTest extends TestCase
{
    public function testScoreRowsComputesFillRatesAndCompleteness(): void
    {
        $fields = ['sku', 'name', 'price'];
        $rows = [
            ['id' => 1, 'key' => 'a', 'values' => ['sku' => 'S1', 'name' => 'Alpha', 'price' => 9.9]],
            ['id' => 2, 'key' => 'b', 'values' => ['sku' => 'S2', 'name' => '', 'price' => null]],
            ['id' => 3, 'key' => 'c', 'values' => ['sku' => '', 'name' => 'Gamma', 'price' => 1]],
        ];

        $report = (new QualityService())->scoreRows($rows, $fields);

        self::assertSame(3, $report['total']);
        // sku filled in 2/3, name 2/3, price 2/3
        $byField = [];
        foreach ($report['fields'] as $f) {
            $byField[$f['field']] = $f;
        }
        self::assertSame(2, $byField['sku']['filled']);
        self::assertEqualsWithDelta(66.7, $byField['price']['rate'], 0.1);
        // average completeness: (1 + 1/3 + 2/3) / 3 = 0.6667 -> 66.7
        self::assertEqualsWithDelta(66.7, $report['averageCompleteness'], 0.2);
        // worst list contains the two incomplete records
        self::assertCount(2, $report['worst']);
    }
}

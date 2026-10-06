<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Statistics;

use ElevateDxp\Statistics\Admin\StatisticsReportsResource;
use ElevateDxp\Statistics\Report\NativeReportSeeder;
use PHPUnit\Framework\TestCase;

final class NativeReportSeederTest extends TestCase
{
    public function testBarDefinition(): void
    {
        $d = NativeReportSeeder::buildDefinition('assets_by_type', [
            'label' => 'Assets by type', 'sql' => 'SELECT type, COUNT(*) AS n FROM assets GROUP BY type;',
            'chart' => 'bar', 'x' => 'type', 'y' => 'n',
        ], ['type', 'n', 'n']);

        self::assertSame('Assets by type', $d['niceName']);
        self::assertSame(NativeReportSeeder::GROUP, $d['group']);
        self::assertSame('SELECT type, COUNT(*) AS n FROM assets GROUP BY type', $d['sql']);
        self::assertSame('sql', $d['dataSourceConfig'][0]['type']);
        self::assertSame($d['sql'], $d['dataSourceConfig'][0]['sql']);
        self::assertSame(['type', 'n'], array_column($d['columnConfiguration'], 'name'));
        self::assertTrue($d['columnConfiguration'][0]['display']);
        self::assertSame('bar', $d['chartType']);
        self::assertSame('type', $d['xAxis']);
        self::assertSame(['n'], $d['yAxis']);
        self::assertNull($d['pieColumn']);
    }

    public function testPieAndNoneMapping(): void
    {
        $pie = NativeReportSeeder::buildDefinition('p', ['sql' => 'SELECT a, b FROM t', 'chart' => 'pie', 'x' => 'a', 'y' => 'b'], []);
        self::assertSame('pie', $pie['chartType']);
        self::assertSame('b', $pie['pieColumn']);
        self::assertSame('a', $pie['pieLabelColumn']);
        self::assertNull($pie['xAxis']);
        self::assertSame('p', $pie['niceName']);

        $none = NativeReportSeeder::buildDefinition('n', ['sql' => 'SELECT a FROM t', 'chart' => 'none'], []);
        self::assertSame('', $none['chartType']);
        self::assertNull($none['yAxis']);
    }

    public function testUnsupportedReasons(): void
    {
        self::assertNull(NativeReportSeeder::unsupportedReason('SELECT 1'));
        self::assertNotNull(NativeReportSeeder::unsupportedReason('WITH x AS (SELECT 1) SELECT * FROM x'));
        self::assertNotNull(NativeReportSeeder::unsupportedReason('DELETE FROM t'));
    }

    public function testUnsafeSqlIsNeverMapped(): void
    {
        $this->expectException(\RuntimeException::class);
        NativeReportSeeder::buildDefinition('x', ['sql' => 'DROP TABLE t'], []);
    }

    public function testSummary(): void
    {
        $s = StatisticsReportsResource::summary(['created' => ['a'], 'skipped' => [], 'unsupported' => ['c' => 'CTE']]);
        self::assertStringContainsString('created: a', $s);
        self::assertStringContainsString('skipped (exist): -', $s);
        self::assertStringContainsString('c (CTE)', $s);
    }
}

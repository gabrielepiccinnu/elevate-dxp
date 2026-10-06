<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Statistics;

use Doctrine\DBAL\Connection;
use ElevateDxp\Statistics\Admin\StatisticsReportResource;
use ElevateDxp\Statistics\DependencyInjection\Configuration;
use ElevateDxp\Statistics\Report\ReportRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class StatisticsReportResourceTest extends TestCase
{
    private function resource(array $report, array $rows): StatisticsReportResource
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);
        $conn->method('fetchAssociative')->willReturn($rows[0] ?? false);

        return new StatisticsReportResource('top', new ReportRunner(['top' => $report], $conn));
    }

    public function testSchemaHasDerivedColumnsAndChart(): void
    {
        $r = $this->resource(['label' => 'Top', 'sql' => 'SELECT k, v FROM t', 'chart' => 'bar', 'x' => 'k', 'y' => 'v'],
            [['k' => 'a', 'v' => 3], ['k' => 'b', 'v' => 1]]);
        self::assertSame('statistics_report_top', $r->getKey());
        self::assertSame('Statistics: Top', $r->getLabel());
        $schema = $r->getSchema();
        self::assertSame('report', $schema['panel']);
        self::assertSame(['k', 'v'], array_column($schema['fields'], 'name'));
        self::assertSame('number', $schema['fields'][1]['type']);
        self::assertSame(['x' => 'k', 'y' => ['v']], $schema['chart']);
    }

    public function testListPaginatesAndSorts(): void
    {
        $r = $this->resource(['sql' => 'SELECT k, v FROM t', 'chart' => 'pie', 'x' => 'k', 'y' => 'v'],
            [['k' => 'a', 'v' => 3], ['k' => 'b', 'v' => 1], ['k' => 'c', 'v' => 2]]);
        self::assertArrayNotHasKey('chart', $r->getSchema());
        $res = $r->list(['sort' => 'v', 'dir' => 'ASC', 'start' => 0, 'limit' => 2]);
        self::assertSame(3, $res['total']);
        self::assertSame(['b', 'c'], array_column($res['data'], 'k'));
    }

    public function testUnsafeReportBecomesUserFacingError(): void
    {
        $r = $this->resource(['sql' => 'DELETE FROM t', 'x' => 'k', 'y' => 'v'], []);
        self::assertSame(['k', 'v'], array_column($r->getSchema()['fields'], 'name'), 'falls back to chart columns');
        $this->expectException(\InvalidArgumentException::class);
        $r->list([]);
    }

    public function testConfigurationRejectsUnsafeReportNames(): void
    {
        $ok = (new Processor())->processConfiguration(new Configuration(), [['reports' => ['a-b_1' => ['sql' => 'SELECT 1']]]]);
        self::assertSame('bar', $ok['reports']['a-b_1']['chart']);
        self::assertSame(1000, $ok['max_rows']);

        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['reports' => ['bad name' => ['sql' => 'SELECT 1']]]]);
    }
}

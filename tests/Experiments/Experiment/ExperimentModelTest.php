<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Experiments\Experiment;

use ElevateDxp\Experiments\Model\Experiment;
use ElevateDxp\Experiments\Model\Variant;
use PHPUnit\Framework\TestCase;

final class ExperimentModelTest extends TestCase
{
    public function testFromRowParsesVariantsAndDates(): void
    {
        $e = Experiment::fromRow([
            'id' => '3', 'exp_key' => 'hero', 'name' => 'Hero', 'status' => 'running', 'traffic' => '150',
            'goal_event' => 'signup', 'target_group_id' => '7', 'url_pattern' => '/shop',
            'variants' => '[{"key":"control","weight":1},{"key":"B","weight":3,"payload":{"h":"x"},"target_group_id":9}]',
            'start_at' => '2026-01-01 00:00:00', 'end_at' => null,
        ]);
        self::assertSame(100, $e->traffic);
        self::assertSame(7, $e->targetGroupId);
        self::assertSame(4, $e->totalWeight());
        self::assertSame('control', $e->controlKey());
        self::assertSame(9, $e->getVariant('B')->targetGroupId);
        self::assertSame(['h' => 'x'], $e->getVariant('B')->payload);
        self::assertTrue($e->isActiveAt(new \DateTimeImmutable('2026-06-01')));
        self::assertFalse($e->isActiveAt(new \DateTimeImmutable('2025-06-01')));
    }

    public function testUrlScope(): void
    {
        $prefix = new Experiment(1, 'k', 'n', 'running', 100, null, [], null, '/shop');
        self::assertTrue($prefix->matchesPath('/shop/shoes'));
        self::assertFalse($prefix->matchesPath('/blog'));

        $regex = new Experiment(1, 'k', 'n', 'running', 100, null, [], null, '#^/(it|en)/landing$#');
        self::assertTrue($regex->matchesPath('/it/landing'));
        self::assertFalse($regex->matchesPath('/it/landing/x'));
    }

    public function testVariantValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Variant::fromArray(['key' => 'bad key!']);
    }
}

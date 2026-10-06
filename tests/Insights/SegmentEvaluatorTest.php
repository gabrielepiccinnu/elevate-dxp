<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Insights;

use ElevateDxp\Insights\Admin\CopilotResource;
use ElevateDxp\Insights\Cdp\SegmentEvaluator;
use PHPUnit\Framework\TestCase;

final class SegmentEvaluatorTest extends TestCase
{
    public function testSegments(): void
    {
        $profile = ['conversions' => 1, 'pageviews' => 7, 'sessions' => 1, 'utm_last' => ['utm_source' => 'x'], 'experiments' => 0];
        self::assertSame(['converters', 'engaged', 'campaign'], SegmentEvaluator::segmentsOf($profile));
        self::assertSame([], SegmentEvaluator::segmentsOf([]));
        self::assertTrue(SegmentEvaluator::matches('returning', ['sessions' => 2]));
        self::assertFalse(SegmentEvaluator::matches('unknown', ['sessions' => 2]));
    }

    public function testCopilotPresetsHaveInstructions(): void
    {
        foreach (CopilotResource::PRESETS as $key => $preset) {
            self::assertNotSame('', $preset['label'], $key);
            if ($key !== 'free') {
                self::assertNotSame('', $preset['system'], $key);
            }
        }
    }
}

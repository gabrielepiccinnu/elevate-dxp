<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Experiments\Targeting;

use ElevateDxp\Experiments\Targeting\Condition\Cookie;
use ElevateDxp\Experiments\Targeting\Condition\ExperimentVariant;
use ElevateDxp\Experiments\Targeting\Condition\QueryParam;
use ElevateDxp\Experiments\Targeting\Condition\ReturningVisitor;
use ElevateDxp\Experiments\Targeting\Condition\StringMatcher;
use ElevateDxp\Experiments\Targeting\Condition\TimeWindow;
use ElevateDxp\Experiments\Targeting\Condition\Utm;
use ElevateDxp\Experiments\Targeting\DataProvider\VisitorProfileDataProvider;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Model\VisitorInfo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConditionsTest extends TestCase
{
    public function testStringMatcher(): void
    {
        self::assertTrue(StringMatcher::matches('Summer-Sale', 'contains', 'sale'));
        self::assertTrue(StringMatcher::matches('abc', 'equals', 'ABC'));
        self::assertTrue(StringMatcher::matches('spring24', 'regex', '^spring\d+$'));
        self::assertFalse(StringMatcher::matches(null, 'exists', null));
        self::assertTrue(StringMatcher::matches('', 'exists', null));
        self::assertFalse(StringMatcher::matches('x', 'contains', ''));
    }

    public function testQueryParamAndCookie(): void
    {
        $vi = new VisitorInfo(Request::create('/?vip=1', 'GET', [], ['consent' => 'yes']));
        self::assertTrue(QueryParam::fromConfig(['name' => 'vip', 'value' => '1'])->match($vi));
        self::assertFalse(QueryParam::fromConfig(['name' => 'vip', 'value' => '2'])->match($vi));
        self::assertFalse(QueryParam::fromConfig(['name' => ''])->canMatch());
        self::assertTrue(Cookie::fromConfig(['name' => 'consent', 'mode' => 'exists'])->match($vi));
        self::assertFalse(Cookie::fromConfig(['name' => 'missing', 'mode' => 'exists'])->match($vi));
    }

    public function testExperimentVariant(): void
    {
        $vi = new VisitorInfo(Request::create('/'));
        $vi->set('edxp_experiments', ['hero' => 'B']);
        self::assertTrue(ExperimentVariant::fromConfig(['experiment' => 'hero', 'variant' => 'B'])->match($vi));
        self::assertTrue(ExperimentVariant::fromConfig(['experiment' => 'hero'])->match($vi));
        self::assertFalse(ExperimentVariant::fromConfig(['experiment' => 'hero', 'variant' => 'A'])->match($vi));
        self::assertSame(['edxp_experiments'], ExperimentVariant::fromConfig(['experiment' => 'x'])->getDataProviderKeys());
    }

    public function testUtmAndReturningVisitor(): void
    {
        $vi = new VisitorInfo(Request::create('/'));
        $vi->set(VisitorProfileDataProvider::PROVIDER_KEY, [
            'sessions' => 3,
            'utm' => ['utm_campaign' => 'black-friday'],
            'utm_first' => ['utm_source' => 'newsletter'],
        ]);
        self::assertTrue(Utm::fromConfig(['parameter' => 'utm_campaign', 'mode' => 'contains', 'value' => 'friday'])->match($vi));
        self::assertTrue(Utm::fromConfig(['parameter' => 'utm_source', 'value' => 'newsletter', 'touch' => 'first'])->match($vi));
        self::assertFalse(Utm::fromConfig(['parameter' => 'utm_source', 'value' => 'newsletter'])->match($vi));
        self::assertTrue(ReturningVisitor::fromConfig(['minSessions' => 2])->match($vi));
        self::assertFalse(ReturningVisitor::fromConfig(['minSessions' => 2, 'inverse' => true])->match($vi));
        self::assertSame(['utm_source' => 'x'], VisitorProfileDataProvider::currentUtm(['utm_source' => 'x', 'foo' => 'bar']));
    }

    public function testTimeWindow(): void
    {
        $vi = new VisitorInfo(Request::create('/'));
        $cond = TimeWindow::fromConfig(['days' => '1,2,3,4,5', 'from' => '09:00', 'to' => '18:00', 'timezone' => 'Europe/Rome']);
        // Monday 2026-10-05 10:00 Rome = 08:00 UTC
        self::assertTrue($cond->withNow(new \DateTimeImmutable('2026-10-05 08:00:00 UTC'))->match($vi));
        self::assertFalse($cond->withNow(new \DateTimeImmutable('2026-10-05 17:00:00 UTC'))->match($vi));
        self::assertFalse($cond->withNow(new \DateTimeImmutable('2026-10-04 08:00:00 UTC'))->match($vi), 'Sunday');

        $night = TimeWindow::fromConfig(['from' => '22:00', 'to' => '06:00', 'timezone' => 'UTC']);
        self::assertTrue($night->withNow(new \DateTimeImmutable('2026-10-05 23:30:00 UTC'))->match($vi));
        self::assertTrue($night->withNow(new \DateTimeImmutable('2026-10-05 05:00:00 UTC'))->match($vi));
        self::assertFalse($night->withNow(new \DateTimeImmutable('2026-10-05 12:00:00 UTC'))->match($vi));
        self::assertFalse(TimeWindow::fromConfig([])->canMatch());
    }
}

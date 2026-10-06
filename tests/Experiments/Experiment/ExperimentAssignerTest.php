<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Experiments\Experiment;

use ElevateDxp\Experiments\Experiment\AssignmentStoreInterface;
use ElevateDxp\Experiments\Experiment\ExperimentAssigner;
use ElevateDxp\Experiments\Model\Experiment;
use ElevateDxp\Experiments\Model\Variant;
use ElevateDxp\Experiments\Visitor\CookieSigner;
use PHPUnit\Framework\TestCase;

final class ExperimentAssignerTest extends TestCase
{
    private InMemoryStore $store;

    private ExperimentAssigner $assigner;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->assigner = new ExperimentAssigner($this->store);
    }

    private function experiment(int $weightA = 50, int $weightB = 50, int $traffic = 100): Experiment
    {
        return new Experiment(1, 'exp', 'Exp', 'running', $traffic, 'goal', [new Variant('A', $weightA), new Variant('B', $weightB)]);
    }

    public function testBucketIsDeterministicAndInRange(): void
    {
        self::assertSame(ExperimentAssigner::bucket('seed-x', 100), ExperimentAssigner::bucket('seed-x', 100));
        for ($i = 0; $i < 200; ++$i) {
            $b = ExperimentAssigner::bucket('seed-'.$i, 100);
            self::assertGreaterThanOrEqual(0, $b);
            self::assertLessThan(100, $b);
        }
    }

    public function testDistributionApproximatesWeights(): void
    {
        $exp = $this->experiment(80, 20);
        $n = 20000;
        $counts = ['A' => 0, 'B' => 0];
        for ($i = 0; $i < $n; ++$i) {
            ++$counts[ExperimentAssigner::pickWeighted($exp, CookieSigner::generateId())];
        }
        $pctA = $counts['A'] / $n * 100;
        self::assertGreaterThan(77, $pctA);
        self::assertLessThan(83, $pctA);
    }

    public function testResolveIsStickyAndReportsNewExposureOnce(): void
    {
        $exp = $this->experiment();
        $first = $this->assigner->resolve($exp, 'abc123abc123');
        self::assertTrue($first['new']);
        $second = $this->assigner->resolve($exp, 'abc123abc123');
        self::assertFalse($second['new']);
        self::assertSame($first['variant'], $second['variant']);
    }

    public function testStoredAssignmentSurvivesWeightChanges(): void
    {
        $this->store->store('visitor00001', 1, 'B');
        $exp = $this->experiment(100, 0);
        self::assertSame('B', $this->assigner->resolve($exp, 'visitor00001')['variant']);
    }

    public function testRemovedVariantIsReassigned(): void
    {
        $this->store->store('visitor00002', 1, 'Z');
        self::assertContains($this->assigner->resolve($this->experiment(), 'visitor00002')['variant'], ['A', 'B']);
    }

    public function testTrafficGateExcludesShareOfVisitors(): void
    {
        $exp = $this->experiment(50, 50, 20);
        $enrolled = 0;
        for ($i = 0; $i < 5000; ++$i) {
            if ($this->assigner->resolve($exp, CookieSigner::generateId())['variant'] !== null) {
                ++$enrolled;
            }
        }
        self::assertGreaterThan(800, $enrolled);
        self::assertLessThan(1200, $enrolled);
    }

    public function testForceOverridesAndRejectsUnknownVariant(): void
    {
        $exp = $this->experiment();
        self::assertTrue($this->assigner->force($exp, 'visitor00003', 'B'));
        self::assertSame('B', $this->assigner->resolve($exp, 'visitor00003')['variant']);
        self::assertFalse($this->assigner->force($exp, 'visitor00003', 'nope'));
    }

    public function testNoVariantsMeansNoAssignment(): void
    {
        $exp = new Experiment(2, 'empty', 'Empty', 'running', 100, null, []);
        self::assertNull($this->assigner->resolve($exp, 'visitor00004')['variant']);
    }
}

final class InMemoryStore implements AssignmentStoreInterface
{
    /** @var array<string,string> */
    private array $data = [];

    public function getVariant(string $visitorId, int $experimentId): ?string
    {
        return $this->data[$visitorId.'|'.$experimentId] ?? null;
    }

    public function store(string $visitorId, int $experimentId, string $variantKey, bool $force = false): bool
    {
        $k = $visitorId.'|'.$experimentId;
        if (isset($this->data[$k]) && !$force) {
            return false;
        }
        $new = !isset($this->data[$k]);
        $this->data[$k] = $variantKey;

        return $new;
    }
}

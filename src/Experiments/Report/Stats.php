<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Report;

/**
 * Two-proportion z-test helpers (frequentist, two-sided). Adequate for conversion-rate A/B
 * tests with reasonably large samples; reports also show sample sizes so readers can judge.
 */
final class Stats
{
    /** Two-sided p-value for H0: p1 == p2. Null when not computable. */
    public static function twoProportionPValue(int $conv1, int $n1, int $conv2, int $n2): ?float
    {
        if ($n1 <= 0 || $n2 <= 0) {
            return null;
        }
        $pooled = ($conv1 + $conv2) / ($n1 + $n2);
        $se = sqrt($pooled * (1 - $pooled) * (1 / $n1 + 1 / $n2));
        if ($se == 0.0) {
            return null;
        }
        $z = (($conv2 / $n2) - ($conv1 / $n1)) / $se;

        return 2 * (1 - self::normalCdf(abs($z)));
    }

    public static function normalCdf(float $x): float
    {
        return 0.5 * (1 + self::erf($x / M_SQRT2));
    }

    /** Abramowitz & Stegun 7.1.26 (max error 1.5e-7). */
    public static function erf(float $x): float
    {
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);
        $t = 1 / (1 + 0.3275911 * $x);
        $y = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /**
     * Visitors needed per variant to detect a relative lift (alpha 0.05 two-sided, power 0.8).
     */
    public static function sampleSizePerVariant(float $baselineRate, float $relativeLift): ?int
    {
        if ($baselineRate <= 0 || $baselineRate >= 1 || $relativeLift <= 0) {
            return null;
        }
        $p1 = $baselineRate;
        $p2 = min(0.9999, $p1 * (1 + $relativeLift));
        $zA = 1.959964;
        $zB = 0.841621;
        $pBar = ($p1 + $p2) / 2;
        $n = (($zA * sqrt(2 * $pBar * (1 - $pBar)) + $zB * sqrt($p1 * (1 - $p1) + $p2 * (1 - $p2))) ** 2) / (($p2 - $p1) ** 2);

        return (int) ceil($n);
    }
}

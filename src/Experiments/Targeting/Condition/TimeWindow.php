<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Targeting\Condition;

use OpenDxp\Bundle\PersonalizationBundle\Targeting\Condition\ConditionInterface;
use OpenDxp\Bundle\PersonalizationBundle\Targeting\Model\VisitorInfo;

/**
 * Matches by weekday and time of day (e.g. "weekdays 09:00-18:00 Europe/Rome").
 * A window whose end is before its start spans midnight (22:00-06:00).
 */
final class TimeWindow implements ConditionInterface
{
    /**
     * @param list<int> $days ISO-8601 weekday numbers 1 (Mon) .. 7 (Sun); empty = every day
     */
    public function __construct(
        private readonly array $days,
        private readonly ?string $from,
        private readonly ?string $to,
        private readonly \DateTimeZone $timezone,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        $days = $config['days'] ?? [];
        if (\is_string($days)) {
            $days = explode(',', $days);
        }
        $days = array_values(array_filter(array_map('intval', (array) $days), static fn (int $d): bool => $d >= 1 && $d <= 7));
        try {
            $tz = new \DateTimeZone((string) ($config['timezone'] ?? '') ?: date_default_timezone_get());
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }
        $time = static fn ($v): ?string => \is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;

        return new self($days, $time($config['from'] ?? null), $time($config['to'] ?? null), $tz);
    }

    public function withNow(\DateTimeImmutable $now): self
    {
        return new self($this->days, $this->from, $this->to, $this->timezone, $now);
    }

    public function canMatch(): bool
    {
        return $this->days !== [] || ($this->from !== null && $this->to !== null);
    }

    public function match(VisitorInfo $visitorInfo): bool
    {
        $now = ($this->now ?? new \DateTimeImmutable())->setTimezone($this->timezone);
        if ($this->days !== [] && !\in_array((int) $now->format('N'), $this->days, true)) {
            return false;
        }
        if ($this->from === null || $this->to === null) {
            return true;
        }
        $t = $now->format('H:i');

        return $this->from <= $this->to
            ? ($t >= $this->from && $t < $this->to)
            : ($t >= $this->from || $t < $this->to);
    }
}

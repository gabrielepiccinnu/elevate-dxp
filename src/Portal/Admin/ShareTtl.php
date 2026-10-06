<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Admin;

/** Validates share lifetimes against the configured bounds. */
final class ShareTtl
{
    public static function days(mixed $value, int $default, int $max): int
    {
        if ($value === null || $value === '') {
            return max(1, min($max, $default));
        }
        if (!is_numeric($value) || (int) $value != $value) {
            throw new \InvalidArgumentException('Share lifetime must be a whole number of days.');
        }
        $days = (int) $value;
        if ($days < 1 || $days > $max) {
            throw new \InvalidArgumentException(\sprintf('Share lifetime must be between 1 and %d days.', $max));
        }

        return $days;
    }
}

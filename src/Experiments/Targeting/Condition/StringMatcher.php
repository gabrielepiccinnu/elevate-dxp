<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Targeting\Condition;

/**
 * Shared value comparison for the string based conditions.
 */
final class StringMatcher
{
    public const MODES = ['equals', 'contains', 'starts_with', 'regex', 'exists'];

    public static function matches(?string $actual, string $mode, ?string $expected): bool
    {
        if ($actual === null) {
            return false;
        }
        $expected ??= '';

        return match ($mode) {
            'exists' => true,
            'contains' => $expected !== '' && stripos($actual, $expected) !== false,
            'starts_with' => $expected !== '' && stripos($actual, $expected) === 0,
            'regex' => $expected !== '' && @preg_match('#'.str_replace('#', '\#', $expected).'#i', $actual) === 1,
            default => strcasecmp($actual, $expected) === 0,
        };
    }

    /** @param array<string, mixed> $config */
    public static function mode(array $config): string
    {
        $mode = (string) ($config['mode'] ?? 'equals');

        return \in_array($mode, self::MODES, true) ? $mode : 'equals';
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Statistics\Report;

/**
 * Read-only SQL guard (ported from the legacy ReportRunner).
 *
 * Only a single SELECT/WITH statement is allowed; statement stacking and any data-modifying
 * or file-writing keyword is rejected before execution. False positives (e.g. a string literal
 * containing "update") are accepted on purpose: deny-by-default.
 */
final class ReadOnlySqlGuard
{
    public const FORBIDDEN = ['insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'create',
        'grant', 'revoke', 'replace', 'merge', 'call', 'into', 'load', 'lock', 'rename', 'set',
        // Elevate DXP additions: file access and dynamic SQL.
        'outfile', 'dumpfile', 'handler', 'prepare', 'execute', 'deallocate'];

    /**
     * @return string the normalized query (trimmed, trailing semicolons removed)
     *
     * @throws \RuntimeException on unsafe SQL
     */
    public static function assertReadOnly(string $sql): string
    {
        $clean = rtrim(trim($sql), "; \t\n\r");
        if ($clean === '') {
            throw new \RuntimeException('Report SQL is empty.');
        }
        if (str_contains($clean, ';')) {
            throw new \RuntimeException('Multiple SQL statements are not allowed.');
        }
        $lower = strtolower($clean);
        if (!str_starts_with($lower, 'select') && !str_starts_with($lower, 'with')) {
            throw new \RuntimeException('Only SELECT/WITH queries are allowed.');
        }
        foreach (self::FORBIDDEN as $kw) {
            if (preg_match('/\b'.preg_quote($kw, '/').'\b/', $lower)) {
                throw new \RuntimeException("Forbidden keyword in report SQL: '$kw'.");
            }
        }

        return $clean;
    }

    public static function isReadOnly(string $sql): bool
    {
        try {
            self::assertReadOnly($sql);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}

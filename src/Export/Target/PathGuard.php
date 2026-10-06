<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Target;

/** Confines a relative path under an allow-listed base directory (anti path-traversal). */
final class PathGuard
{
    public static function resolveUnder(string $baseDir, string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('Path traversal detected in target path.');
        }
        $base = rtrim($baseDir, '/');

        // Normalize without requiring the file to exist yet.
        $normalizedBase = self::normalize($base);
        $normalizedFull = self::normalize($base.'/'.$relative);
        if ($normalizedFull === $normalizedBase || !str_starts_with($normalizedFull, $normalizedBase.'/')) {
            throw new \InvalidArgumentException('Resolved path escapes the allowed base directory.');
        }

        return $normalizedFull;
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Target;

use ElevateDxp\Export\Contract\ExportTargetInterface;

/** Writes to the local filesystem, confined to the configured allow-listed base directory. */
final class LocalFilesystemTarget implements ExportTargetInterface
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $basePath,
    ) {
    }

    public function type(): string
    {
        return 'local';
    }

    public function write(string $path, string $content): string
    {
        $baseDir = str_starts_with($this->basePath, '/')
            ? $this->basePath
            : rtrim($this->projectDir, '/').'/'.trim($this->basePath, '/');
        $full = PathGuard::resolveUnder($baseDir, $path);

        $dir = \dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create directory: '.$dir);
        }
        // Write atomically so readers never see a half-written export.
        $tmp = $full.'.tmp-'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false || !rename($tmp, $full)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write file: '.$full);
        }

        return $full;
    }
}

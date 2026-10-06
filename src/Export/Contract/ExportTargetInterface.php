<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Contract;

interface ExportTargetInterface
{
    public function type(): string;

    /** Writes content to the logical path and returns the final location string. */
    public function write(string $path, string $content): string;
}

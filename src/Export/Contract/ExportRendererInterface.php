<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Contract;

interface ExportRendererInterface
{
    public function format(): string;

    /** @param array<int,array<string,mixed>> $rows */
    public function render(array $rows): string;
}

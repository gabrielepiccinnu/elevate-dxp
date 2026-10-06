<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Renderer;

use ElevateDxp\Export\Contract\ExportRendererInterface;

final class JsonRenderer implements ExportRendererInterface
{
    public function format(): string
    {
        return 'json';
    }

    public function render(array $rows): string
    {
        return json_encode(array_values($rows), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
    }
}

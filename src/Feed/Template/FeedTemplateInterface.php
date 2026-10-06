<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\Template;

interface FeedTemplateInterface
{
    public function name(): string;

    /** File extension and public URL format, e.g. "csv" or "xml". */
    public function extension(): string;

    public function contentType(): string;

    /** @return list<string> field names that must be present and non-empty in every row */
    public function requiredFields(): array;

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>       $meta channel/currency settings
     */
    public function render(array $rows, array $meta): string;
}

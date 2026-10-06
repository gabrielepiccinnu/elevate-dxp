<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\Template;

use ElevateDxp\Export\Renderer\CsvRenderer;

/**
 * Generic CSV feed: header from the mapped field names, rendered through the Export module's
 * CsvRenderer so it shares its formula-injection mitigation.
 */
final class GenericCsvTemplate implements FeedTemplateInterface
{
    private readonly CsvRenderer $csv;

    public function __construct(?CsvRenderer $csv = null)
    {
        $this->csv = $csv ?? new CsvRenderer();
    }

    public function name(): string
    {
        return 'generic_csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function requiredFields(): array
    {
        return ['id', 'title'];
    }

    public function render(array $rows, array $meta): string
    {
        return $this->csv->render($rows);
    }
}

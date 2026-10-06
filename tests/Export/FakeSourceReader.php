<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Export;

use ElevateDxp\Export\Contract\SourceReaderInterface;

final class FakeSourceReader implements SourceReaderInterface
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    /** @param list<array<string,mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function read(array $source, int $chunkSize, ?int $limit = null): array
    {
        $this->calls[] = ['source' => $source, 'chunkSize' => $chunkSize, 'limit' => $limit];

        return $limit === null ? $this->rows : \array_slice($this->rows, 0, $limit);
    }
}

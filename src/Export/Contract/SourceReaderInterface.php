<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Contract;

/**
 * Reads a configured source (data object class or assets) into explicitly mapped rows.
 * Shared with the feed bundle.
 */
interface SourceReaderInterface
{
    /**
     * @param array{type:string,class?:?string,fields?:array<string,string>} $source
     * @param int|null                                                       $limit  stop after this many rows (null = all)
     *
     * @return list<array<string,mixed>>
     */
    public function read(array $source, int $chunkSize, ?int $limit = null): array;
}

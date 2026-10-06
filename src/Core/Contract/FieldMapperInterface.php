<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Contract;

interface FieldMapperInterface
{
    /**
     * @param array<string,string> $fields output key => getter/property name
     *
     * @return array<string,mixed>
     */
    public function map(object $source, array $fields): array;
}

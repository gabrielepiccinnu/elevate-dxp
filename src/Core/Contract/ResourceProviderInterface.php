<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Contract;

interface ResourceProviderInterface
{
    public function type(): string;

    /**
     * @param array<string, mixed>  $endpoint endpoint configuration
     * @param array<string, string> $fields   output key => getter/property name
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(array $endpoint, array $fields, int $page, int $limit): array;

    /**
     * @param array<string, mixed>  $endpoint endpoint configuration
     * @param array<string, string> $fields   output key => getter/property name
     *
     * @return array<string, mixed>|null
     */
    public function get(array $endpoint, array $fields, int $id): ?array;
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Contract;

interface ResourceProviderInterface
{
    public function type(): string;

    public function list(array $endpoint, array $fields, int $page, int $limit): array;

    public function get(array $endpoint, array $fields, int $id): ?array;
}

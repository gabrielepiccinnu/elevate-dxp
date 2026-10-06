<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Experiment;

interface AssignmentStoreInterface
{
    public function getVariant(string $visitorId, int $experimentId): ?string;

    /** @return bool true when a new assignment was created (i.e. a new exposure) */
    public function store(string $visitorId, int $experimentId, string $variantKey, bool $force = false): bool;
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Security;

use OpenDxp\Model\Asset;

/**
 * Deny-by-default policy for portal asset access: only assets inside allow-listed folders, never folders.
 *
 * Prefixes are matched on folder boundaries ("/products" allows "/products/a.jpg" but not
 * "/products-internal/a.jpg"); an empty allow-list denies everything.
 */
final class PortalPolicy
{
    /** @param list<string> $allowedAssetPaths */
    public function __construct(private readonly array $allowedAssetPaths)
    {
    }

    public function canDownload(Asset $asset): bool
    {
        if ($asset->getType() === 'folder') {
            return false;
        }

        return $this->isPathAllowed((string) $asset->getFullPath());
    }

    public function isPathAllowed(string $fullPath): bool
    {
        if ($fullPath === '' || str_contains($fullPath, '/../') || str_contains($fullPath, "\0")) {
            return false;
        }
        foreach ($this->allowedAssetPaths as $prefix) {
            $prefix = trim($prefix);
            if ($prefix === '') {
                continue;
            }
            $prefix = rtrim($prefix, '/');
            if ($prefix === '' || str_starts_with($fullPath, $prefix.'/')) {
                return true; // "/" allows everything
            }
        }

        return false;
    }

    /** @return list<string> */
    public function allowedPaths(): array
    {
        return array_values($this->allowedAssetPaths);
    }
}

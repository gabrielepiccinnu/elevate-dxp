<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Target;

use ElevateDxp\Export\Contract\ExportTargetInterface;
use OpenDxp\Model\Asset;

/** Writes the export as an OpenDXP asset (created or updated at the configured asset path). */
final class AssetTarget implements ExportTargetInterface
{
    public function type(): string
    {
        return 'asset';
    }

    public function write(string $path, string $content): string
    {
        [$parentPath, $filename] = self::splitPath($path);

        $existing = Asset::getByPath(rtrim($parentPath, '/').'/'.$filename);
        if ($existing instanceof Asset && $existing->getType() !== 'folder') {
            $existing->setData($content);
            $existing->save();
            $asset = $existing;
        } elseif ($existing instanceof Asset) {
            throw new \RuntimeException('Asset target path points to a folder: '.$existing->getFullPath());
        } else {
            $parent = Asset\Service::createFolderByPath($parentPath);
            if ($parent === null) {
                throw new \RuntimeException('Cannot create asset folder: '.$parentPath);
            }
            // Asset::create() picks the concrete asset type from the file name / content.
            $asset = Asset::create($parent->getId(), ['filename' => $filename, 'data' => $content]);
        }

        return 'asset://'.$asset->getFullPath().' (id '.$asset->getId().')';
    }

    /**
     * Validates and splits an asset path into [parentPath, filename].
     *
     * @return array{0:string,1:string}
     */
    public static function splitPath(string $path): array
    {
        $path = '/'.ltrim(str_replace('\\', '/', trim($path)), '/');
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Path traversal detected in asset path.');
        }
        $filename = basename($path);
        if ($filename === '' || str_ends_with($path, '/')) {
            throw new \InvalidArgumentException('Asset target path must end with a file name.');
        }
        $parentPath = \dirname($path);
        if ($parentPath === '' || $parentPath === '.' || $parentPath === '\\') {
            $parentPath = '/';
        }

        return [$parentPath, $filename];
    }
}

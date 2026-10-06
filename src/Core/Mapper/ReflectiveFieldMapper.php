<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Mapper;

use ElevateDxp\Core\Contract\FieldMapperInterface;

/**
 * Maps a source object to an output array using an explicit field map. Resolves each source via a
 * getter (getFoo()) or a direct method, then scalarizes the value. No implicit/recursive serialization.
 */
final class ReflectiveFieldMapper implements FieldMapperInterface
{
    public function map(object $source, array $fields): array
    {
        $out = [];
        foreach ($fields as $outputKey => $sourceAccessor) {
            $out[$outputKey] = $this->scalarize($this->resolve($source, (string) $sourceAccessor));
        }

        return $out;
    }

    private function resolve(object $source, string $accessor): mixed
    {
        $getter = 'get'.ucfirst($accessor);
        if (method_exists($source, $getter)) {
            return $source->{$getter}();
        }
        if (method_exists($source, $accessor)) {
            return $source->{$accessor}();
        }

        return null;
    }

    private function scalarize(mixed $value): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }
        if (\is_object($value)) {
            if (method_exists($value, 'getFullPath')) {
                return $value->getFullPath();
            }
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return null; // do not auto-serialize complex relations
        }
        if (\is_array($value)) {
            return array_map(fn ($v) => $this->scalarize($v), $value);
        }

        return null;
    }
}

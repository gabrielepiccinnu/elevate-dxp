<?php

declare(strict_types=1);

namespace ElevateDxp\DamMetadata\Metadata;

use OpenDxp\Model\Asset;

/**
 * Holds typed asset-metadata schemas, matches them to assets, validates and normalizes values,
 * and maps them to native predefined metadata definitions. Pure logic apart from assetMatches().
 */
final class SchemaService
{
    /** @param array<string,array{label?:string,path_prefix?:string,asset_types?:array<int,string>,fields?:array<int,array<string,mixed>>}> $schemas */
    public function __construct(private readonly array $schemas)
    {
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->schemas;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->schemas));
    }

    /** @return array<string,mixed>|null */
    public function get(string $name): ?array
    {
        return $this->schemas[$name] ?? null;
    }

    public function label(string $name): string
    {
        $label = (string) ($this->schemas[$name]['label'] ?? '');

        return $label !== '' ? $label : $name;
    }

    /** @return array<string,mixed>|null the field config */
    public function field(string $schema, string $field): ?array
    {
        foreach ($this->schemas[$schema]['fields'] ?? [] as $f) {
            if (($f['name'] ?? null) === $field) {
                return $f;
            }
        }

        return null;
    }

    public function assetMatches(string $schema, Asset $asset): bool
    {
        return $this->pathMatches($schema, (string) $asset->getFullPath(), (string) $asset->getType());
    }

    /** Same rules as assetMatches(), on plain values (folders never match). */
    public function pathMatches(string $schema, string $fullPath, string $type): bool
    {
        $cfg = $this->schemas[$schema] ?? null;
        if ($cfg === null || $type === 'folder') {
            return false;
        }
        $prefix = (string) ($cfg['path_prefix'] ?? '/');
        if ($prefix !== '' && !str_starts_with($fullPath, $prefix)) {
            return false;
        }
        $types = $cfg['asset_types'] ?? [];

        return $types === [] || \in_array($type, $types, true);
    }

    /**
     * @param array<string,mixed> $field
     *
     * @return string|null error message (null = valid)
     */
    public function validateValue(array $field, mixed $value): ?string
    {
        $type = (string) ($field['type'] ?? 'input');
        $name = (string) ($field['name'] ?? '?');
        if (\is_array($value) || \is_object($value)) {
            return "field '$name' must be a scalar value";
        }

        return match ($type) {
            'number' => is_numeric($value) ? null : "field '$name' must be numeric",
            'checkbox' => \is_bool($value) || \in_array((string) $value, ['0', '1', 'true', 'false'], true) ? null : "field '$name' must be a boolean",
            'date' => (is_numeric($value) || strtotime((string) $value) !== false) && (string) $value !== '' ? null : "field '$name' must be a date",
            'select' => \in_array((string) $value, array_map('strval', $field['options'] ?? []), true)
                ? null : "field '$name' must be one of: ".implode(', ', $field['options'] ?? []),
            default => null,
        };
    }

    /**
     * Converts a validated value to what the native metadata type stores.
     *
     * @param array<string,mixed> $field
     */
    public function normalizeValue(array $field, mixed $value): mixed
    {
        return match ((string) ($field['type'] ?? 'input')) {
            'checkbox' => \is_bool($value) ? $value : \in_array((string) $value, ['1', 'true'], true),
            'date' => is_numeric($value) ? (int) $value : (int) strtotime((string) $value),
            default => $value === null ? '' : (string) $value,
        };
    }

    /** Schema field type -> native asset metadata type. */
    public function nativeType(string $fieldType): string
    {
        return match ($fieldType) {
            'textarea' => 'textarea',
            'select' => 'select',
            'checkbox' => 'checkbox',
            'date' => 'date',
            default => 'input', // input, number
        };
    }

    /**
     * Native predefined metadata definitions for a schema: one per field and target asset type
     * (or one without target subtype when the schema applies to any type).
     *
     * @return list<array{name:string,type:string,group:string,targetSubtype:?string,config:?string,description:string}>
     */
    public function predefinedDefinitions(string $schema): array
    {
        $cfg = $this->schemas[$schema] ?? null;
        if ($cfg === null) {
            return [];
        }
        $subtypes = array_values(array_unique(array_map('strval', $cfg['asset_types'] ?? [])));
        if ($subtypes === []) {
            $subtypes = [null];
        }

        $out = [];
        foreach ($cfg['fields'] ?? [] as $f) {
            $type = (string) ($f['type'] ?? 'input');
            $description = (string) ($f['description'] ?? '');
            if ($description === '') {
                $description = (string) ($f['label'] ?? '');
            }
            foreach ($subtypes as $subtype) {
                $out[] = [
                    'name' => (string) $f['name'],
                    'type' => $this->nativeType($type),
                    'group' => $this->label($schema),
                    'targetSubtype' => $subtype,
                    // native select metadata expects a comma-separated option list
                    'config' => $type === 'select' ? implode(',', array_map('strval', $f['options'] ?? [])) : null,
                    'description' => $description,
                ];
            }
        }

        return $out;
    }

    /**
     * Filters out definitions that already exist natively. An existing definition with the same
     * name and no target subtype (or the same subtype) counts as existing.
     *
     * @param list<array{name:string,targetSubtype:?string}>  $definitions
     * @param list<array{name:?string,targetSubtype:?string}> $existing
     *
     * @return list<array<string,mixed>>
     */
    public function missingDefinitions(array $definitions, array $existing): array
    {
        return array_values(array_filter($definitions, static function (array $def) use ($existing): bool {
            foreach ($existing as $e) {
                $sub = (string) ($e['targetSubtype'] ?? '');
                if (($e['name'] ?? null) === $def['name'] && ($sub === '' || $sub === (string) ($def['targetSubtype'] ?? ''))) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Plans the metadata entries to write on one asset.
     *
     * With $field: sets that field to $value (validated) — overwriting only when $overwrite or unset.
     * Without $field: initializes every schema field that the asset does not have yet with its
     * "default" (fields without a default are skipped).
     *
     * @param list<string> $existingNames metadata names already present on the asset
     *
     * @return list<array{name:string,type:string,data:mixed}>
     *
     * @throws \InvalidArgumentException on unknown schema/field or invalid value
     */
    public function plan(string $schema, array $existingNames, ?string $field, mixed $value, bool $overwrite): array
    {
        if ($this->get($schema) === null) {
            throw new \InvalidArgumentException("Unknown schema '$schema'.");
        }

        if ($field !== null && $field !== '') {
            $cfg = $this->field($schema, $field) ?? throw new \InvalidArgumentException("Unknown field '$field' in schema '$schema'.");
            if (($err = $this->validateValue($cfg, $value)) !== null) {
                throw new \InvalidArgumentException(ucfirst($err).'.');
            }
            if (!$overwrite && \in_array($field, $existingNames, true)) {
                return [];
            }

            return [['name' => $field, 'type' => $this->nativeType((string) ($cfg['type'] ?? 'input')), 'data' => $this->normalizeValue($cfg, $value)]];
        }

        $out = [];
        foreach ($this->schemas[$schema]['fields'] ?? [] as $cfg) {
            $default = $cfg['default'] ?? null;
            if ($default === null || (!$overwrite && \in_array($cfg['name'], $existingNames, true))) {
                continue;
            }
            if (($err = $this->validateValue($cfg, $default)) !== null) {
                throw new \InvalidArgumentException('Invalid default: '.$err.'.');
            }
            $out[] = ['name' => (string) $cfg['name'], 'type' => $this->nativeType((string) ($cfg['type'] ?? 'input')), 'data' => $this->normalizeValue($cfg, $default)];
        }

        return $out;
    }
}

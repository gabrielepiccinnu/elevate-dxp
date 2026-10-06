<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Admin;

/**
 * Schema field builders for the ExtJS form/grid renderer.
 *
 * Common options: required, readOnly, grid (show as column, default true for scalar types),
 * width, flex, help, virtual (not persisted), default.
 */
final class Field
{
    public const STRUCTURED = ['json', 'keyvalue', 'tags', 'multiselect'];

    public static function isStructured(string $type): bool
    {
        return \in_array($type, self::STRUCTURED, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function id(string $name = 'id', string $label = 'ID'): array
    {
        return self::make('number', $name, $label, ['readOnly' => true, 'width' => 70]);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function text(string $name, string $label, array $o = []): array
    {
        return self::make('text', $name, $label, $o);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function textarea(string $name, string $label, array $o = []): array
    {
        return self::make('textarea', $name, $label, $o + ['grid' => false]);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function code(string $name, string $label, array $o = []): array
    {
        return self::make('code', $name, $label, $o + ['grid' => false]);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function password(string $name, string $label, array $o = []): array
    {
        return self::make('password', $name, $label, $o + ['grid' => false]);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function number(string $name, string $label, array $o = []): array
    {
        return self::make('number', $name, $label, $o);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function bool(string $name, string $label, array $o = []): array
    {
        return self::make('bool', $name, $label, $o + ['width' => 80]);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function date(string $name, string $label, array $o = []): array
    {
        return self::make('date', $name, $label, $o);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function datetime(string $name, string $label, array $o = []): array
    {
        return self::make('datetime', $name, $label, $o);
    }

    /**
     * @param list<array{0:string|int,1?:string}>|array<string|int, string> $options value=>label or [[value,label]]
     * @param array<string, mixed>                                          $o
     *
     * @return array<string, mixed>
     */
    public static function select(string $name, string $label, array $options, array $o = []): array
    {
        return self::make('select', $name, $label, $o + ['options' => self::normalizeOptions($options)]);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    /**
     * @param list<array{0:string|int,1?:string}>|array<string|int, string> $options value=>label or [[value,label]]
     * @param array<string, mixed>                                          $o
     *
     * @return array<string, mixed>
     */
    public static function multiselect(string $name, string $label, array $options, array $o = []): array
    {
        return self::make('multiselect', $name, $label, $o + ['options' => self::normalizeOptions($options), 'grid' => false]);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function json(string $name, string $label, array $o = []): array
    {
        return self::make('json', $name, $label, $o + ['grid' => false]);
    }

    /**
     * Editable key/value grid; stored as JSON object.
     *
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function keyvalue(string $name, string $label, array $o = []): array
    {
        return self::make('keyvalue', $name, $label, $o + ['grid' => false]);
    }

    /**
     * List of strings; stored as JSON array.
     *
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function tags(string $name, string $label, array $o = []): array
    {
        return self::make('tags', $name, $label, $o + ['grid' => false]);
    }

    /**
     * Element reference (drop target) holding the element full path or id.
     *
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function element(string $name, string $label, string $elementType = 'object', array $o = []): array
    {
        return self::make('element', $name, $label, $o + ['elementType' => $elementType]);
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    private static function make(string $type, string $name, string $label, array $o): array
    {
        return ['name' => $name, 'label' => $label, 'type' => $type] + $o + ['grid' => true];
    }

    /**
     * @param list<array{0:string|int,1?:string}>|array<string|int, string> $options
     *
     * @return list<array{0:string|int,1:string}>
     */
    private static function normalizeOptions(array $options): array
    {
        $out = [];
        foreach ($options as $k => $v) {
            $out[] = \is_array($v) ? [$v[0], $v[1] ?? (string) $v[0]] : [$k, $v];
        }

        return $out;
    }
}

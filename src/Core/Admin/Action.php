<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Admin;

/**
 * Toolbar action builders. scope=record needs a selected row; scope=global does not.
 * "params" are fields prompted in a dialog before running.
 */
final class Action
{
    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function record(string $name, string $label, array $o = []): array
    {
        return ['name' => $name, 'label' => $label, 'scope' => 'record'] + $o + ['iconCls' => 'opendxp_icon_play'];
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function global(string $name, string $label, array $o = []): array
    {
        return ['name' => $name, 'label' => $label, 'scope' => 'global'] + $o + ['iconCls' => 'opendxp_icon_play'];
    }

    /** @return array<string, mixed> */
    public static function message(string $message, bool $reload = false): array
    {
        return ['success' => true, 'message' => $message, 'reload' => $reload];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null          $columns
     *
     * @return array<string, mixed>
     */
    public static function table(array $rows, ?string $title = null, ?array $columns = null): array
    {
        $columns ??= $rows !== [] ? array_keys($rows[0]) : [];

        return ['success' => true, 'title' => $title, 'rows' => $rows, 'columns' => array_values($columns)];
    }

    /** @return array<string, mixed> */
    public static function text(string $text, ?string $title = null, string $mode = 'text'): array
    {
        return ['success' => true, 'title' => $title, 'text' => $text, 'mode' => $mode];
    }

    /** @return array<string, mixed> */
    public static function html(string $html, ?string $title = null): array
    {
        return ['success' => true, 'title' => $title, 'html' => $html];
    }

    /** @return array<string, mixed> */
    public static function url(string $url, ?string $message = null): array
    {
        return ['success' => true, 'url' => $url, 'message' => $message];
    }
}

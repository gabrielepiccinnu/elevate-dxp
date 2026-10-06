<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Admin;

/**
 * Toolbar action builders. scope=record needs a selected row; scope=global does not.
 * "params" are fields prompted in a dialog before running.
 */
final class Action
{
    public static function record(string $name, string $label, array $o = []): array
    {
        return ['name' => $name, 'label' => $label, 'scope' => 'record'] + $o + ['iconCls' => 'opendxp_icon_play'];
    }

    public static function global(string $name, string $label, array $o = []): array
    {
        return ['name' => $name, 'label' => $label, 'scope' => 'global'] + $o + ['iconCls' => 'opendxp_icon_play'];
    }

    public static function message(string $message, bool $reload = false): array
    {
        return ['success' => true, 'message' => $message, 'reload' => $reload];
    }

    /** @param list<array<string,mixed>> $rows */
    public static function table(array $rows, ?string $title = null, ?array $columns = null): array
    {
        $columns ??= $rows !== [] ? array_keys($rows[0]) : [];

        return ['success' => true, 'title' => $title, 'rows' => $rows, 'columns' => array_values($columns)];
    }

    public static function text(string $text, ?string $title = null, string $mode = 'text'): array
    {
        return ['success' => true, 'title' => $title, 'text' => $text, 'mode' => $mode];
    }

    public static function html(string $html, ?string $title = null): array
    {
        return ['success' => true, 'title' => $title, 'html' => $html];
    }

    public static function url(string $url, ?string $message = null): array
    {
        return ['success' => true, 'url' => $url, 'message' => $message];
    }
}

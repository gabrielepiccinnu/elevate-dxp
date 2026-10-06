<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Renderer;

use ElevateDxp\Export\Contract\ExportRendererInterface;

/**
 * CSV with OWASP formula-injection mitigation: cells starting with = + - @ (or tab/CR) are
 * prefixed with a single quote. Reused by the feed bundle's generic CSV template.
 */
final class CsvRenderer implements ExportRendererInterface
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function format(): string
    {
        return 'csv';
    }

    public function render(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        $rows = array_values($rows);
        $headers = array_map('strval', array_keys($rows[0]));
        fputcsv($handle, array_map(self::sanitize(...), $headers), ',', '"', '\\');
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $line[] = self::sanitize($row[$h] ?? null);
            }
            fputcsv($handle, $line, ',', '"', '\\');
        }
        rewind($handle);
        $out = stream_get_contents($handle);
        fclose($handle);

        return $out === false ? '' : $out;
    }

    /** Neutralises spreadsheet formula execution for a single cell. */
    public static function sanitize(mixed $value): string
    {
        if (\is_array($value)) {
            $value = implode('|', array_map(static fn ($v): string => \is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v), $value));
        } elseif (\is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (\is_object($value)) {
            $value = method_exists($value, '__toString') ? (string) $value : (string) json_encode($value);
        }
        $str = (string) ($value ?? '');
        if ($str !== '' && \in_array($str[0], self::DANGEROUS_PREFIXES, true)) {
            return "'".$str;
        }

        return $str;
    }
}

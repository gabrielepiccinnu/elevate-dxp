<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Renderer;

use ElevateDxp\Export\Contract\ExportRendererInterface;

/** Simple, safely-escaped XML: <items><item><field>value</field></item></items>. */
final class XmlRenderer implements ExportRendererInterface
{
    public function format(): string
    {
        return 'xml';
    }

    public function render(array $rows): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('items');
        $doc->appendChild($root);

        foreach ($rows as $row) {
            $item = $doc->createElement('item');
            foreach ($row as $key => $value) {
                $text = \is_array($value) ? implode('|', array_map(self::stringify(...), $value)) : self::stringify($value);
                // createElement + text node escapes the value; no manual concatenation.
                $el = $doc->createElement(self::safeTag((string) $key));
                $el->appendChild($doc->createTextNode($text));
                $item->appendChild($el);
            }
            $root->appendChild($item);
        }

        return (string) $doc->saveXML();
    }

    public static function safeTag(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?? 'field';
        if ($name === '' || !preg_match('/^[A-Za-z_]/', $name) || str_starts_with(strtolower($name), 'xml')) {
            $name = 'f_'.$name;
        }

        return $name;
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return \is_scalar($value) ? (string) $value : (string) json_encode($value);
    }
}

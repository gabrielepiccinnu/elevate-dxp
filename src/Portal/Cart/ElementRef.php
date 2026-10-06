<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Cart;

/**
 * Parses element references typed by users: "asset:12", "object:7", "o:7", "a:12" or a bare id (asset).
 */
final class ElementRef
{
    public static function normaliseType(string $type): string
    {
        return \in_array(strtolower($type), ['asset', 'a'], true) ? 'asset' : 'object';
    }

    /** @return array{type:string,id:int} */
    public static function parse(string $ref): array
    {
        $ref = trim($ref);
        if (preg_match('/^(?:(asset|object|a|o|dataobject)\s*[:#]\s*)?(\d{1,10})$/i', $ref, $m) !== 1 || (int) $m[2] < 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid element reference "%s" (use asset:ID or object:ID).', $ref));
        }
        $type = $m[1] === '' ? 'asset' : self::normaliseType($m[1]);

        return ['type' => $type, 'id' => (int) $m[2]];
    }

    /**
     * @param list<string>|string $refs list or comma/whitespace separated string
     *
     * @return list<array{type:string,id:int}> deduplicated
     */
    public static function parseList(array|string $refs): array
    {
        if (\is_string($refs)) {
            $refs = preg_split('/[\s,;]+/', $refs, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $out = [];
        foreach ($refs as $ref) {
            $item = self::parse((string) $ref);
            $out[$item['type'].':'.$item['id']] = $item;
        }

        return array_values($out);
    }

    /** @param array{type:string,id:int} $item */
    public static function format(array $item): string
    {
        return $item['type'].':'.$item['id'];
    }
}

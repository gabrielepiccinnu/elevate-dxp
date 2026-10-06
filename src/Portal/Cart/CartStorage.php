<?php

declare(strict_types=1);

namespace ElevateDxp\Portal\Cart;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Session-backed cart of portal elements (assets/objects). Keys are "type:id" strings.
 * No DB; persists for the login session. Saving a cart as a named collection is handled separately.
 */
final class CartStorage
{
    private const KEY = 'edxp_portal_cart';
    public const MAX_ITEMS = 1000;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /** @return list<array{type:string,id:int}> */
    public function all(): array
    {
        $out = [];
        foreach ($this->raw() as $k) {
            [$type, $id] = explode(':', (string) $k, 2) + [1 => '0'];
            $out[] = ['type' => $type, 'id' => (int) $id];
        }

        return $out;
    }

    public function add(string $type, int $id): void
    {
        $type = ElementRef::normaliseType($type);
        $items = $this->raw();
        if (!isset($items[$type.':'.$id]) && \count($items) >= self::MAX_ITEMS) {
            throw new \InvalidArgumentException(\sprintf('The cart is limited to %d items.', self::MAX_ITEMS));
        }
        $items[$type.':'.$id] = $type.':'.$id;
        $this->store($items);
    }

    public function remove(string $type, int $id): void
    {
        $items = $this->raw();
        unset($items[ElementRef::normaliseType($type).':'.$id]);
        $this->store($items);
    }

    public function clear(): void
    {
        $this->store([]);
    }

    public function count(): int
    {
        return \count($this->raw());
    }

    /** @return array<string,string> */
    private function raw(): array
    {
        try {
            return (array) $this->requestStack->getSession()->get(self::KEY, []);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,string> $items */
    private function store(array $items): void
    {
        try {
            $this->requestStack->getSession()->set(self::KEY, $items);
        } catch (\Throwable) {
            // no session → ignore
        }
    }
}

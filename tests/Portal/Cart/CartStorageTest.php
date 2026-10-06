<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Portal\Cart;

use ElevateDxp\Portal\Cart\CartStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CartStorageTest extends TestCase
{
    private function cart(): CartStorage
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        return new CartStorage($stack);
    }

    public function testAddRemoveCount(): void
    {
        $cart = $this->cart();
        self::assertSame(0, $cart->count());

        $cart->add('asset', 3);
        $cart->add('asset', 4);
        $cart->add('asset', 3); // dedup
        self::assertSame(2, $cart->count());

        $all = $cart->all();
        self::assertSame(['type' => 'asset', 'id' => 3], $all[0]);

        $cart->remove('asset', 3);
        self::assertSame(1, $cart->count());

        $cart->clear();
        self::assertSame(0, $cart->count());
    }

    public function testTypeNormalisedToAssetOrObject(): void
    {
        $cart = $this->cart();
        $cart->add('weird', 9); // non-asset → object
        self::assertSame(['type' => 'object', 'id' => 9], $cart->all()[0]);
    }

    public function testWithoutSessionIsEmptyAndSilent(): void
    {
        $cart = new CartStorage(new RequestStack());
        $cart->add('asset', 1);
        self::assertSame(0, $cart->count());
        self::assertSame([], $cart->all());
    }
}

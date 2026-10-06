<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Export\Target;

use ElevateDxp\Export\Target\AssetTarget;
use ElevateDxp\Export\Target\HttpTarget;
use ElevateDxp\Export\Target\LocalFilesystemTarget;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class TargetsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/edxp_export_test_'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testLocalTargetWritesUnderBase(): void
    {
        $target = new LocalFilesystemTarget($this->dir, 'var/exports');
        $location = $target->write('sub/out.csv', "a,b\n");
        self::assertSame($this->dir.'/var/exports/sub/out.csv', $location);
        self::assertSame("a,b\n", file_get_contents($location));
        self::assertSame([], glob($this->dir.'/var/exports/sub/*.tmp-*'));
    }

    public function testLocalTargetRejectsTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LocalFilesystemTarget($this->dir, 'var/exports'))->write('../../escape.csv', 'x');
    }

    public function testAssetPathSplitting(): void
    {
        self::assertSame(['/exports/feeds', 'products.csv'], AssetTarget::splitPath('exports/feeds/products.csv'));
        self::assertSame(['/', 'x.json'], AssetTarget::splitPath('/x.json'));
    }

    public function testAssetPathRejectsTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssetTarget::splitPath('/exports/../system/x.csv');
    }

    public function testHttpTargetPostsBody(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201)]));
        $stack->push(Middleware::history($history));
        $target = new HttpTarget(new Client(['handler' => $stack]));

        $location = $target->write('https://hooks.example.com/in?token=secret', 'payload');

        self::assertSame('https://hooks.example.com (status 201)', $location);
        self::assertStringNotContainsString('secret', $location);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('payload', (string) $history[0]['request']->getBody());
    }

    public function testHttpTargetFailsOnErrorStatus(): void
    {
        $target = new HttpTarget(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(500)]))]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('status 500');
        $target->write('https://hooks.example.com/in', 'payload');
    }

    public function testHttpTargetRequiresAbsoluteUrl(): void
    {
        $target = new HttpTarget(new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
        $this->expectException(\InvalidArgumentException::class);
        $target->write('file:///etc/passwd', 'x');
    }
}

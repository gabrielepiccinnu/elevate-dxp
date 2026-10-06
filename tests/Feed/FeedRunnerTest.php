<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Feed;

use ElevateDxp\Export\Target\LocalFilesystemTarget;
use ElevateDxp\Feed\Admin\FeedResource;
use ElevateDxp\Feed\Controller\PublicFeedController;
use ElevateDxp\Feed\Runner\FeedRunner;
use ElevateDxp\Feed\Template\GenericCsvTemplate;
use ElevateDxp\Feed\Template\GoogleMerchantTemplate;
use ElevateDxp\Feed\Validator\FeedValidator;
use ElevateDxp\Tests\Export\FakeSourceReader;
use ElevateDxp\Tests\Export\InMemoryAuditLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;

final class FeedRunnerTest extends TestCase
{
    private const TOKEN = 'a-very-long-feed-token-123';

    private string $dir;
    private InMemoryAuditLogger $audit;
    private FakeSourceReader $reader;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/edxp_feed_test_'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->audit = new InMemoryAuditLogger();
        $this->reader = new FakeSourceReader([
            ['id' => 'SKU1', 'title' => 'Aurora', 'description' => 'Chair', 'image_link' => '/x.png', 'availability' => '', 'price' => '12.5'],
            ['id' => 'SKU2', 'title' => '', 'description' => 'Table', 'image_link' => null, 'availability' => 'out of stock', 'price' => '99'],
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function runner(bool $enabled = true): FeedRunner
    {
        $feeds = [
            'google' => [
                'source' => ['type' => 'data_object', 'class' => 'Product'],
                'template' => 'google_merchant',
                'currency' => 'EUR',
                'link_pattern' => 'https://shop.example.com/p/{id}',
                'mappings' => ['id' => 'sku', 'title' => 'name'],
                'static' => ['availability' => 'in stock', 'brand' => 'Demo'],
                'channel' => ['title' => 'Shop', 'link' => 'https://shop.example.com', 'description' => 'd'],
                'target' => ['type' => 'local', 'path' => null],
                'token' => self::TOKEN,
                'cache_ttl' => 0,
            ],
            'csv' => [
                'source' => ['type' => 'asset', 'class' => null],
                'template' => 'generic_csv',
                'mappings' => ['id' => 'id', 'title' => 'filename'],
                'static' => [],
                'target' => ['type' => 'local', 'path' => 'feeds/catalog.csv'],
                'token' => null,
                'cache_ttl' => 60,
            ],
            'weak' => [
                'source' => ['type' => 'asset'],
                'template' => 'generic_csv',
                'mappings' => [],
                'token' => 'short',
            ],
        ];

        return new FeedRunner(
            $feeds,
            $this->reader,
            new FeedValidator(),
            new ServiceLocator(['generic_csv' => static fn () => new GenericCsvTemplate(), 'google_merchant' => static fn () => new GoogleMerchantTemplate()]),
            new ServiceLocator(['local' => fn () => new LocalFilesystemTarget($this->dir, 'out')]),
            $this->audit,
            100,
            $enabled,
            $this->dir,
        );
    }

    public function testBuildAppliesMappingsStaticAndLinkPattern(): void
    {
        $built = $this->runner()->build('google', 5);

        self::assertSame(['id' => 'sku', 'title' => 'name'], $this->reader->calls[0]['source']['fields']);
        self::assertSame(5, $this->reader->calls[0]['limit']);
        self::assertSame('in stock', $built['rows'][0]['availability']);      // static fills empty
        self::assertSame('out of stock', $built['rows'][1]['availability']);  // static never overrides
        self::assertSame('https://shop.example.com/p/SKU2', $built['rows'][1]['link']);
        self::assertCount(1, $built['issues']);
        self::assertSame(1, $built['issues'][0]['index']);
        self::assertSame(['title', 'image_link'], $built['issues'][0]['missing']);
    }

    public function testExportWritesDefaultPathAndAudits(): void
    {
        $result = $this->runner()->export('google', 'tester');
        self::assertSame($this->dir.'/out/google.xml', $result['location']);
        self::assertSame(2, $result['rows']);
        self::assertSame(1, $result['issues']);
        self::assertStringContainsString('<g:price>12.50 EUR</g:price>', (string) file_get_contents($result['location']));
        self::assertSame(['feed.google:export'], $this->audit->summary());
        self::assertSame('tester', $this->audit->events[0]->actor);

        $csv = $this->runner()->export('csv');
        self::assertSame($this->dir.'/out/feeds/catalog.csv', $csv['location']);
    }

    public function testConcurrentExportOfTheSameFeedIsRefused(): void
    {
        $runner = $this->runner();
        $lockFile = $runner->lockFile('google');
        self::assertSame($this->dir.'/edxp_feed_google.lock', $lockFile);

        // another process (here: another handle) holds the lock
        $other = fopen($lockFile, 'c');
        self::assertNotFalse($other);
        self::assertTrue(flock($other, \LOCK_EX | \LOCK_NB));
        try {
            $runner->export('google');
            self::fail('Expected "already being exported"');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('already being exported', $e->getMessage());
        }
        self::assertSame([], $this->reader->calls, 'nothing is read while another export runs');

        // a different feed is not blocked
        self::assertSame(2, $runner->export('csv')['rows']);

        flock($other, \LOCK_UN);
        fclose($other);
        self::assertSame(2, $runner->export('google')['rows'], 'the lock is free again');
    }

    public function testLockIsReleasedWhenTheExportFails(): void
    {
        $feeds = (new \ReflectionProperty(FeedRunner::class, 'feeds'))->getValue($this->runner());
        $runner = new FeedRunner($feeds, $this->reader, new FeedValidator(),
            new ServiceLocator(['google_merchant' => static fn () => new GoogleMerchantTemplate()]),
            new ServiceLocator([]), $this->audit, 100, true, $this->dir);
        foreach ([1, 2] as $attempt) {
            try {
                $runner->export('google');
                self::fail('Expected missing target');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('No export target', $e->getMessage(), 'attempt '.$attempt.' is not blocked by a stale lock');
            }
        }
    }

    public function testDisabledAndUnknown(): void
    {
        try {
            $this->runner()->build('nope');
            self::fail('Expected exception');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\RuntimeException::class);
        $this->runner(false)->export('google');
    }

    public function testDescribeHidesTokens(): void
    {
        $rows = $this->runner()->describe();
        self::assertTrue($rows[0]['public']);
        self::assertSame('/elevate-dxp/feed/google.xml?token=…', $rows[0]['publicPath']);
        self::assertFalse($rows[1]['public']);
        self::assertFalse($rows[2]['public'], 'tokens shorter than 16 chars are not accepted');
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($rows));
    }

    public function testPublicUrlServesFeedWithValidToken(): void
    {
        $controller = new PublicFeedController($this->runner(), $this->audit, new ArrayAdapter());
        $response = $controller('google', 'xml', Request::create('/elevate-dxp/feed/google.xml', 'GET', ['token' => self::TOKEN]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/rss+xml', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('<g:id>SKU1</g:id>', (string) $response->getContent());
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('ok', $this->audit->events[0]->status);

        $viaHeader = $controller('google', 'xml', Request::create('/elevate-dxp/feed/google.xml', 'GET', [], [], [], ['HTTP_X_ELEVATE_DXP_FEED_TOKEN' => self::TOKEN]));
        self::assertSame(200, $viaHeader->getStatusCode());
    }

    /** @return iterable<string, array{string,string,array<string,string>,string}> */
    public static function deniedRequests(): iterable
    {
        yield 'wrong token' => ['google', 'xml', ['token' => 'a-very-long-feed-token-124'], 'invalid_token'];
        yield 'missing token' => ['google', 'xml', [], 'invalid_token'];
        yield 'no token configured' => ['csv', 'csv', ['token' => ''], 'no_token_configured'];
        yield 'weak token configured' => ['weak', 'csv', ['token' => 'short'], 'no_token_configured'];
        yield 'unknown feed' => ['nope', 'xml', ['token' => self::TOKEN], 'unknown_feed'];
        yield 'format mismatch' => ['google', 'csv', ['token' => self::TOKEN], 'format_mismatch'];
    }

    /** @param array<string,string> $query */
    #[\PHPUnit\Framework\Attributes\DataProvider('deniedRequests')]
    public function testPublicUrlDeniesByDefault(string $feed, string $format, array $query, string $reason): void
    {
        $controller = new PublicFeedController($this->runner(), $this->audit);
        $response = $controller($feed, $format, Request::create('/elevate-dxp/feed/'.$feed.'.'.$format, 'GET', $query));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not found.', $response->getContent());
        self::assertSame('denied', $this->audit->events[0]->status);
        self::assertSame($reason, $this->audit->events[0]->context['reason']);
        self::assertSame([], $this->reader->calls, 'no data may be read for denied requests');
    }

    public function testPublicUrlDisabledBundle(): void
    {
        $controller = new PublicFeedController($this->runner(false), $this->audit);
        self::assertSame(404, $controller('google', 'xml', Request::create('/x', 'GET', ['token' => self::TOKEN]))->getStatusCode());
    }

    public function testPublicUrlUsesCacheWhenTtlSet(): void
    {
        $feeds = (new \ReflectionProperty(FeedRunner::class, 'feeds'))->getValue($this->runner());
        $feeds['google']['cache_ttl'] = 60;
        $runner = new FeedRunner(
            $feeds,
            $this->reader,
            new FeedValidator(),
            new ServiceLocator(['google_merchant' => static fn () => new GoogleMerchantTemplate()]),
            new ServiceLocator([]),
            $this->audit,
        );
        $controller = new PublicFeedController($runner, $this->audit, new ArrayAdapter());
        $req = Request::create('/x', 'GET', ['token' => self::TOKEN]);
        $controller('google', 'xml', $req);
        $controller('google', 'xml', $req);
        self::assertCount(1, $this->reader->calls);
    }

    public function testAdminResourceActions(): void
    {
        $resource = new FeedResource($this->runner());
        self::assertSame(['validate', 'preview', 'export'], array_column($resource->getSchema()['actions'], 'name'));
        self::assertSame(3, $resource->list([])['total']);

        $validate = $resource->runAction('validate', 'google', []);
        self::assertSame([['row' => 1, 'id' => 'SKU2', 'missing' => 'title, image_link']], $validate['rows']);

        $preview = $resource->runAction('preview', 'google', ['limit' => 1]);
        self::assertSame(1, $this->reader->calls[1]['limit']);
        self::assertSame('', $preview['rows'][0]['_missing']);

        $rendered = $resource->runAction('preview', 'google', ['as' => 'rendered']);
        self::assertSame('xml', $rendered['mode']);
        self::assertStringContainsString('<rss', $rendered['text']);

        $export = $resource->runAction('export', 'google', []);
        self::assertStringContainsString('2 rows written', $export['message']);
        self::assertStringContainsString('1 row(s) with validation issues', $export['message']);
    }

    public function testAdminResourceWrapsFailures(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FeedResource($this->runner()))->runAction('validate', 'nope', []);
    }
}

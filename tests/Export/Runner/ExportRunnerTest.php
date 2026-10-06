<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Export\Runner;

use ElevateDxp\Export\Admin\ExportResource;
use ElevateDxp\Export\Renderer\CsvRenderer;
use ElevateDxp\Export\Renderer\JsonRenderer;
use ElevateDxp\Export\Runner\ExportRunner;
use ElevateDxp\Export\Target\LocalFilesystemTarget;
use ElevateDxp\Tests\Export\FakeSourceReader;
use ElevateDxp\Tests\Export\InMemoryAuditLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ExportRunnerTest extends TestCase
{
    private string $dir;
    private InMemoryAuditLogger $audit;
    private FakeSourceReader $reader;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/edxp_runner_test_'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->audit = new InMemoryAuditLogger();
        $this->reader = new FakeSourceReader([
            ['id' => 1, 'name' => 'Aurora', 'tags' => ['a', 'b']],
            ['id' => 2, 'name' => '=HYPERLINK("x")', 'tags' => []],
            ['id' => 3, 'name' => 'Nimbus', 'tags' => ['c']],
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function runner(bool $enabled = true): ExportRunner
    {
        $jobs = [
            'products' => [
                'description' => 'All products',
                'format' => 'csv',
                'source' => ['type' => 'data_object', 'class' => 'Product', 'fields' => ['id' => 'id', 'name' => 'name']],
                'target' => ['type' => 'local', 'path' => 'products.csv'],
            ],
            'broken' => [
                'format' => 'json',
                'source' => ['type' => 'asset', 'fields' => []],
                'target' => ['type' => 'local', 'path' => '../escape.json'],
            ],
            'nosuchtarget' => [
                'format' => 'json',
                'source' => ['type' => 'asset', 'fields' => []],
                'target' => ['type' => 'sftp', 'path' => 'x'],
            ],
        ];

        return new ExportRunner(
            $jobs,
            50,
            $this->reader,
            new ServiceLocator(['csv' => static fn () => new CsvRenderer(), 'json' => static fn () => new JsonRenderer()]),
            new ServiceLocator(['local' => fn () => new LocalFilesystemTarget($this->dir, 'out')]),
            $this->audit,
            $enabled,
            $this->dir,
        );
    }

    public function testRunWritesFileAndAudits(): void
    {
        $result = $this->runner()->run('products', 'tester');

        self::assertSame(3, $result['rows']);
        self::assertSame($this->dir.'/out/products.csv', $result['location']);
        $csv = (string) file_get_contents($result['location']);
        self::assertStringStartsWith("id,name,tags\n", $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertSame(['export.products:start', 'export.products:success'], $this->audit->summary());
        self::assertSame('tester', $this->audit->events[0]->actor);
        self::assertNull($this->reader->calls[0]['limit']);
    }

    public function testFailureIsAuditedAndRethrown(): void
    {
        try {
            $this->runner()->run('broken');
            self::fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('traversal', $e->getMessage());
        }
        self::assertSame(['export.broken:start', 'export.broken:failure'], $this->audit->summary());
    }

    public function testUnknownJobAndTarget(): void
    {
        $runner = $this->runner();
        try {
            $runner->run('nope');
            self::fail('Expected exception');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No target');
        $runner->run('nosuchtarget');
    }

    public function testConcurrentRunIsRejectedByLock(): void
    {
        $handle = fopen($this->dir.'/edxp_export_products.lock', 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, \LOCK_EX | \LOCK_NB));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('already running');
            $this->runner()->run('products');
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    public function testDisabledBundleRefusesToRun(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->runner(false)->run('products');
    }

    public function testPreviewReadsLimitedRowsWithoutWriting(): void
    {
        $preview = $this->runner()->preview('products', 2);
        self::assertCount(2, $preview['rows']);
        self::assertSame('csv', $preview['format']);
        self::assertSame(2, $this->reader->calls[0]['limit']);
        self::assertFileDoesNotExist($this->dir.'/out/products.csv');
        self::assertSame([], $this->audit->summary());
    }

    public function testListJobs(): void
    {
        $jobs = $this->runner()->listJobs();
        self::assertSame('products', $jobs[0]['name']);
        self::assertSame('local:products.csv', $jobs[0]['target']);
        self::assertSame('Product', $jobs[0]['class']);
    }

    public function testAdminResourceActions(): void
    {
        $resource = new ExportResource($this->runner());
        self::assertSame(['run', 'preview'], array_column($resource->getSchema()['actions'], 'name'));
        self::assertFalse($resource->getSchema()['canEdit']);
        self::assertSame(3, $resource->list(['q' => ''])['total']);
        self::assertSame('products', $resource->get('products')['name']);
        self::assertNull($resource->get('missing'));

        $table = $resource->runAction('preview', 'products', ['limit' => 2]);
        self::assertCount(2, $table['rows']);
        self::assertSame('a, b', $table['rows'][0]['tags']);

        $text = $resource->runAction('preview', 'products', ['limit' => 999, 'as' => 'rendered']);
        self::assertStringStartsWith('id,name,tags', $text['text']);
        self::assertSame(ExportResource::MAX_PREVIEW, $this->reader->calls[1]['limit']);

        $msg = $resource->runAction('run', 'products', []);
        self::assertStringContainsString('Exported 3 rows', $msg['message']);

        $this->expectException(\DomainException::class);
        $resource->runAction('run', 'nosuchtarget', []);
    }

    public function testAdminResourceRejectsUnknownJob(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ExportResource($this->runner()))->runAction('run', 'nope', []);
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Translation;

use ElevateDxp\Translation\Admin\TranslationProvidersResource;
use ElevateDxp\Translation\Provider\ProviderRegistry;
use ElevateDxp\Translation\Provider\PseudoProvider;
use ElevateDxp\Translation\Xliff\XliffService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class TranslationProvidersResourceTest extends TestCase
{
    private function resource(): TranslationProvidersResource
    {
        $registry = new ProviderRegistry(new ServiceLocator(['pseudo' => static fn () => new PseudoProvider()]), 'pseudo');

        return new TranslationProvidersResource($registry, new XliffService(), ['en', 'de'], ['url' => 'http://lt', 'api_key' => 'k']);
    }

    public function testSchemaAndList(): void
    {
        $r = $this->resource();
        $schema = $r->getSchema();
        self::assertSame(['test', 'translate', 'xliff_build', 'xliff_fill'], array_column($schema['actions'], 'name'));
        self::assertFalse($schema['canEdit']);
        $list = $r->list([]);
        self::assertSame(1, $list['total']);
        self::assertTrue($list['data'][0]['default']);
        self::assertFalse($list['data'][0]['api_key_set'], 'api key is only reported for libretranslate and never exposed');
        self::assertNull($r->get('missing'));
    }

    public function testTranslateAction(): void
    {
        $res = $this->resource()->runAction('translate', null, ['provider' => 'pseudo', 'from' => 'en', 'to' => 'de', 'text' => 'Hi']);
        self::assertSame('[DE] Hi', $res['rows'][0]['translated']);
        self::assertSame('pseudo', $res['rows'][0]['provider']);
    }

    public function testBuildAndFillXliff(): void
    {
        $r = $this->resource();
        $built = $r->runAction('xliff_build', null, ['from' => 'en', 'to' => 'de', 'units' => ['k' => 'Hello']]);
        self::assertStringContainsString('<source>Hello</source>', $built['text']);

        $filled = $r->runAction('xliff_fill', null, ['provider' => 'pseudo', 'from' => 'en', 'to' => 'it', 'xliff' => $built['text']]);
        self::assertStringContainsString('[DE] Hello', $filled['text'], 'file target-language wins over the dialog value');
        self::assertStringContainsString('[DE] Hello world', $r->runAction('test', 'pseudo', [])['message']);
    }

    /** @return iterable<string,array{string,array}> */
    public static function invalidInput(): iterable
    {
        yield 'unknown provider' => ['translate', ['provider' => 'nope', 'from' => 'en', 'to' => 'de', 'text' => 'x']];
        yield 'empty text' => ['translate', ['provider' => 'pseudo', 'from' => 'en', 'to' => 'de', 'text' => ' ']];
        yield 'bad language' => ['translate', ['provider' => 'pseudo', 'from' => 'en"><', 'to' => 'de', 'text' => 'x']];
        yield 'too long' => ['translate', ['provider' => 'pseudo', 'from' => 'en', 'to' => 'de', 'text' => str_repeat('a', TranslationProvidersResource::MAX_TEXT + 1)]];
        yield 'no units' => ['xliff_build', ['from' => 'en', 'to' => 'de', 'units' => []]];
        yield 'invalid xliff' => ['xliff_fill', ['provider' => 'pseudo', 'from' => 'en', 'to' => 'de', 'xliff' => '<!DOCTYPE x><x/>']];
        yield 'unknown action' => ['nope', []];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
    public function testInvalidInputIsRejected(string $action, array $params): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resource()->runAction($action, null, $params);
    }
}

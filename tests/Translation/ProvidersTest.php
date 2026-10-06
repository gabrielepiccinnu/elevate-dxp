<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Translation;

use ElevateDxp\Translation\Provider\LibreTranslateProvider;
use ElevateDxp\Translation\Provider\ProviderRegistry;
use ElevateDxp\Translation\Provider\PseudoProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ProvidersTest extends TestCase
{
    private function libre(array|\Throwable $response, ?array &$captured = null): LibreTranslateProvider
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturnCallback(function (string $method, string $url, array $options) use ($response, &$captured): ResponseInterface {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];
            if ($response instanceof \Throwable) {
                throw $response;
            }
            $r = $this->createStub(ResponseInterface::class);
            $r->method('toArray')->willReturn($response);

            return $r;
        });

        return new LibreTranslateProvider($client, ['url' => 'http://lt:5000/', 'api_key' => 'secret', 'timeout' => 3]);
    }

    public function testLibreTranslateRequestAndResult(): void
    {
        $p = $this->libre(['translatedText' => 'Ciao'], $captured);
        self::assertSame('libretranslate', $p->name());
        self::assertSame('Ciao', $p->translate('Hello', 'en', 'it'));
        self::assertNull($p->lastError());
        self::assertSame('POST', $captured['method']);
        self::assertSame('http://lt:5000/translate', $captured['url']);
        self::assertSame(['q' => 'Hello', 'source' => 'en', 'target' => 'it', 'format' => 'text', 'api_key' => 'secret'], $captured['options']['json']);
        self::assertSame(3, $captured['options']['timeout']);
    }

    public function testLibreTranslateFallsBackToSource(): void
    {
        $p = $this->libre(new \RuntimeException('connection refused'));
        self::assertSame('Hello', $p->translate('Hello', 'en', 'it'));
        self::assertSame('connection refused', $p->lastError());

        $p = $this->libre(['error' => 'bad language']);
        self::assertSame('Hello', $p->translate('Hello', 'en', 'xx'));
        self::assertSame('bad language', $p->lastError());

        self::assertSame('  ', $this->libre(['translatedText' => 'x'])->translate('  ', 'en', 'it'), 'blank text is not sent');
    }

    public function testRegistry(): void
    {
        $pseudo = new PseudoProvider();
        $locator = new ServiceLocator(['pseudo' => static fn () => $pseudo]);

        $registry = new ProviderRegistry($locator, 'libretranslate');
        self::assertSame($pseudo, $registry->get(), 'unknown default falls back to pseudo');
        self::assertSame($pseudo, $registry->get('nope'));
        self::assertSame(['pseudo'], $registry->names());
        self::assertSame('pseudo', $registry->defaultName());
        self::assertSame($pseudo, $registry->getOrFail('pseudo'));

        $this->expectException(\InvalidArgumentException::class);
        $registry->getOrFail('nope');
    }

    public function testRegistryWithoutProviders(): void
    {
        self::assertInstanceOf(PseudoProvider::class, (new ProviderRegistry(new ServiceLocator([]), 'pseudo'))->get());
    }
}

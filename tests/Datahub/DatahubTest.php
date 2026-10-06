<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Datahub;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Contract\ResourceProviderInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use ElevateDxp\Datahub\Admin\EndpointResource;
use ElevateDxp\Datahub\Admin\GraphqlConfigurationResource;
use ElevateDxp\Datahub\Api\EndpointExecutor;
use ElevateDxp\Datahub\Controller\ApiController;
use ElevateDxp\Datahub\Provider\DataObjectProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;

final class DatahubTest extends TestCase
{
    private const KEY = 'secret-api-key';

    /** @var list<AuditEvent> */
    private array $events = [];
    private FakeProvider $provider;

    protected function setUp(): void
    {
        $this->events = [];
        $this->provider = new FakeProvider();
    }

    private function executor(): EndpointExecutor
    {
        return new EndpointExecutor(
            [
                'products' => ['type' => 'fake', 'class' => 'Product', 'fields' => ['id' => 'id', 'name' => 'name']],
                'orphans' => ['type' => 'missing', 'fields' => []],
            ],
            new ServiceLocator(['fake' => fn () => $this->provider]),
            50,
        );
    }

    private function controller(string $apiKey = self::KEY, bool $enabled = true): ApiController
    {
        $audit = new class($this->events) implements AuditLoggerInterface {
            /** @param list<AuditEvent> $events shared with the test case by reference */
            public function __construct(public array &$events)
            {
            }

            public function log(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };

        return new ApiController($enabled, 'X-Elevate-Dxp-Api-Key', $apiKey, $this->executor(), $audit);
    }

    /** @param array<string, string> $query */
    private function request(array $query = [], ?string $key = self::KEY): Request
    {
        $server = $key === null ? [] : ['HTTP_X_ELEVATE_DXP_API_KEY' => $key];

        return Request::create('/elevate-dxp/api/products', 'GET', $query, [], [], $server);
    }

    /** @return array<string, mixed> */
    private static function body(\Symfony\Component\HttpFoundation\Response $r): array
    {
        return json_decode((string) $r->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testListWithValidKeyCapsLimit(): void
    {
        $response = $this->controller()->list('products', $this->request(['limit' => '5000', 'page' => '2']));

        self::assertSame(200, $response->getStatusCode());
        $body = self::body($response);
        self::assertSame(['page' => 2, 'limit' => 50, 'total' => 3], $body['meta']);
        self::assertSame([2, 50], $this->provider->lastList);
        self::assertSame('Aurora', $body['data'][0]['name']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('datahub.products.list', $this->events[0]->action);
        self::assertSame('ok', $this->events[0]->status);
        self::assertStringStartsWith('apikey:', $this->events[0]->actor);
        self::assertStringNotContainsString(self::KEY, $this->events[0]->actor);
    }

    public function testNonNumericPagingFallsBackToDefaults(): void
    {
        $body = self::body($this->controller()->list('products', $this->request(['limit' => 'abc', 'page' => '-3'])));
        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 3], $body['meta']);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function unauthorized(): iterable
    {
        yield 'wrong key' => [self::KEY, 'nope'];
        yield 'missing key' => [self::KEY, null];
        yield 'empty configured key denies all' => ['', ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unauthorized')]
    public function testUnauthorized(string $configured, ?string $given): void
    {
        $response = $this->controller($configured)->list('products', $this->request([], $given));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'unauthorized'], self::body($response));
        self::assertNull($this->provider->lastList);
        self::assertSame('unauthorized', $this->events[0]->status);
    }

    public function testAuthIsCheckedBeforeEndpointLookup(): void
    {
        self::assertSame(401, $this->controller()->list('unknown', $this->request([], 'bad'))->getStatusCode());
        $response = $this->controller()->list('unknown', $this->request());
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'unknown_endpoint'], self::body($response));
    }

    public function testDisabled(): void
    {
        $response = $this->controller(self::KEY, false)->list('products', $this->request());
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'disabled'], self::body($response));
    }

    public function testDetailFoundAndNotFound(): void
    {
        $ok = $this->controller()->detail('products', 1, $this->request());
        self::assertSame(['data' => ['id' => 1, 'name' => 'Aurora']], self::body($ok));

        $missing = $this->controller()->detail('products', 99, $this->request());
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(['error' => 'not_found'], self::body($missing));
        self::assertSame('not_found', $this->events[1]->status);
    }

    public function testMissingProviderAndProviderErrorsAreJson(): void
    {
        $response = $this->controller()->list('orphans', $this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'no_provider'], self::body($response));

        $this->provider->fail = true;
        $error = $this->controller()->list('products', $this->request());
        self::assertSame(500, $error->getStatusCode());
        self::assertSame(['error' => 'internal_error'], self::body($error));
        self::assertStringNotContainsString('SQLSTATE', (string) $error->getContent());
        self::assertSame('error', $this->events[1]->status);
    }

    public function testDataObjectProviderRejectsInvalidClassNames(): void
    {
        self::assertNull(DataObjectProvider::classFqcn(['class' => '../Foo']));
        self::assertNull(DataObjectProvider::classFqcn(['class' => 'Foo\\Bar']));
        self::assertNull(DataObjectProvider::classFqcn(['class' => '']));
        self::assertNull(DataObjectProvider::classFqcn(['class' => 'DefinitelyNotAClass'.bin2hex(random_bytes(3))]));
    }

    public function testEndpointResourceTryRequest(): void
    {
        $resource = new EndpointResource($this->executor(), 'X-Elevate-Dxp-Api-Key', true);
        self::assertSame(['try'], array_column($resource->getSchema()['actions'], 'name'));
        $rows = $resource->list([])['data'];
        self::assertSame('/elevate-dxp/api/products', $rows[0]['path']);
        self::assertSame('id, name', $rows[0]['fieldList']);

        $list = $resource->runAction('try', 'products', ['page' => 1, 'limit' => 999]);
        self::assertStringContainsString('GET /elevate-dxp/api/products?page=1&limit=50', $list['text']);
        self::assertStringContainsString('HTTP 200', $list['text']);
        self::assertStringContainsString('"Aurora"', $list['text']);
        self::assertStringNotContainsString('WARNING', $list['text']);

        $detail = $resource->runAction('try', 'products', ['id' => '99']);
        self::assertStringContainsString('GET /elevate-dxp/api/products/99', $detail['text']);
        self::assertStringContainsString('HTTP 404', $detail['text']);

        $noKey = (new EndpointResource($this->executor()))->runAction('try', 'products', []);
        self::assertStringContainsString('WARNING', $noKey['text']);
    }

    public function testEndpointResourceValidatesInput(): void
    {
        $resource = new EndpointResource($this->executor());
        try {
            $resource->runAction('try', 'nope', []);
            self::fail('Expected exception');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $resource->runAction('try', 'products', ['id' => 'abc']);
    }

    public function testGraphqlResourceMapsConfigurationsWithoutSecrets(): void
    {
        $config = new class(null) {
            public function __construct(private readonly ?string $group)
            {
            }

            public function getName(): string
            {
                return 'blog';
            }

            public function getType(): string
            {
                return 'graphql';
            }

            public function getGroup(): ?string
            {
                return $this->group;
            }

            public function isActive(): bool
            {
                return true;
            }

            /** @return array<string, mixed> */
            public function getConfiguration(): array
            {
                return ['general' => ['description' => 'Blog API'], 'security' => ['apikey' => ['top-secret']]];
            }

            /** @return array<string, mixed> */
            public function getSecurityConfig(): array
            {
                return $this->getConfiguration()['security'];
            }
        };
        $resource = new GraphqlConfigurationResource(static fn (): array => [$config]);
        $result = $resource->list([]);

        self::assertSame(1, $result['total']);
        self::assertSame([
            'name' => 'blog',
            'type' => 'graphql',
            'group' => '',
            'active' => true,
            'apiKeyConfigured' => true,
            'endpoint' => '/opendxp-graphql-webservices/blog',
            'description' => 'Blog API',
        ], $result['data'][0]);
        self::assertStringNotContainsString('top-secret', (string) json_encode($result));
        self::assertFalse($resource->getSchema()['canEdit']);
        self::assertNull($resource->get('missing'));
    }

    public function testGraphqlResourceDefaultLoaderUsesDataHubWhenInstalled(): void
    {
        if (!class_exists(GraphqlConfigurationResource::DATAHUB_CONFIGURATION)) {
            self::assertSame(['data' => [], 'total' => 0], (new GraphqlConfigurationResource())->list([]));

            return;
        }
        // The default loader calls Configuration::getList() statically.
        self::assertTrue((new \ReflectionMethod(GraphqlConfigurationResource::DATAHUB_CONFIGURATION, 'getList'))->isStatic());
    }
}

final class FakeProvider implements ResourceProviderInterface
{
    /** @var array{0:int,1:int}|null */
    public ?array $lastList = null;
    public bool $fail = false;

    private const ROWS = [1 => 'Aurora', 2 => 'Nimbus', 3 => 'Cirrus'];

    public function type(): string
    {
        return 'fake';
    }

    public function list(array $endpoint, array $fields, int $page, int $limit): array
    {
        if ($this->fail) {
            throw new \RuntimeException('SQLSTATE[42S02]: table missing');
        }
        $this->lastList = [$page, $limit];
        $items = [];
        foreach (self::ROWS as $id => $name) {
            $items[] = ['id' => $id, 'name' => $name];
        }

        return ['items' => $items, 'total' => \count($items)];
    }

    public function get(array $endpoint, array $fields, int $id): ?array
    {
        return isset(self::ROWS[$id]) ? ['id' => $id, 'name' => self::ROWS[$id]] : null;
    }
}

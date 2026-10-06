<?php

declare(strict_types=1);

namespace ElevateDxp\Datahub\Api;

use ElevateDxp\Core\Contract\ResourceProviderInterface;
use Psr\Container\ContainerInterface;

/**
 * Executes a declared endpoint (list or detail) and returns the HTTP status and JSON body.
 * Authentication is NOT done here: the public controller authenticates first, the admin
 * "try request" action runs behind the admin firewall and resource permission.
 */
final class EndpointExecutor
{
    /**
     * @param array<string,array<string,mixed>> $endpoints keyed by endpoint name
     * @param ContainerInterface                $providers locator of ResourceProviderInterface keyed by type
     */
    public function __construct(
        private readonly array $endpoints,
        private readonly ContainerInterface $providers,
        private readonly int $maxLimit = 100,
    ) {
    }

    public function has(string $endpoint): bool
    {
        return isset($this->endpoints[$endpoint]);
    }

    public function maxLimit(): int
    {
        return $this->maxLimit;
    }

    /** @return list<array{name:string,type:string,class:string,path:string,fields:array<string,string>}> */
    public function describe(): array
    {
        $out = [];
        foreach ($this->endpoints as $name => $config) {
            $out[] = [
                'name' => (string) $name,
                'type' => (string) ($config['type'] ?? ''),
                'class' => (string) ($config['class'] ?? ''),
                'path' => self::publicPath((string) $name),
                'fields' => (array) ($config['fields'] ?? []),
            ];
        }

        return $out;
    }

    public static function publicPath(string $endpoint): string
    {
        return '/elevate-dxp/api/'.$endpoint;
    }

    public function clampLimit(mixed $limit): int
    {
        return max(1, min($this->maxLimit, is_numeric($limit) ? (int) $limit : 20));
    }

    public static function clampPage(mixed $page): int
    {
        return max(1, min(1_000_000, is_numeric($page) ? (int) $page : 1));
    }

    /** @return array{status:int,body:array<string,mixed>,count?:int} */
    public function list(string $endpoint, mixed $page, mixed $limit): array
    {
        [$config, $provider, $error] = $this->resolve($endpoint);
        if ($error !== null) {
            return $error;
        }
        $limit = $this->clampLimit($limit);
        $page = self::clampPage($page);
        $result = $provider->list($config, (array) ($config['fields'] ?? []), $page, $limit);
        $items = array_values((array) ($result['items'] ?? []));

        return [
            'status' => 200,
            'body' => ['data' => $items, 'meta' => ['page' => $page, 'limit' => $limit, 'total' => (int) ($result['total'] ?? \count($items))]],
            'count' => \count($items),
        ];
    }

    /** @return array{status:int,body:array<string,mixed>} */
    public function detail(string $endpoint, int $id): array
    {
        [$config, $provider, $error] = $this->resolve($endpoint);
        if ($error !== null) {
            return $error;
        }
        $item = $provider->get($config, (array) ($config['fields'] ?? []), $id);

        return $item === null
            ? ['status' => 404, 'body' => ['error' => 'not_found']]
            : ['status' => 200, 'body' => ['data' => $item]];
    }

    /**
     * @return array{0:array<string,mixed>,1:ResourceProviderInterface,2:null}|array{0:null,1:null,2:array{status:int,body:array<string,mixed>}}
     */
    private function resolve(string $endpoint): array
    {
        if (!isset($this->endpoints[$endpoint])) {
            return [null, null, ['status' => 404, 'body' => ['error' => 'unknown_endpoint']]];
        }
        $config = $this->endpoints[$endpoint];
        $type = (string) ($config['type'] ?? '');
        if (!$this->providers->has($type)) {
            return [null, null, ['status' => 500, 'body' => ['error' => 'no_provider']]];
        }

        return [$config, $this->providers->get($type), null];
    }
}

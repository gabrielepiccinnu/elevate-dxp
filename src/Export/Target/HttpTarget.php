<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Target;

use ElevateDxp\Export\Contract\ExportTargetInterface;
use GuzzleHttp\ClientInterface;

/**
 * Delivers the export by HTTP POST to a configured URL (the job target's `path` is the URL).
 * Uses the Guzzle client registered by OpenDXP (symfony/http-client is not part of the stack).
 */
final class HttpTarget implements ExportTargetInterface
{
    public function __construct(private readonly ClientInterface $httpClient)
    {
    }

    public function type(): string
    {
        return 'http';
    }

    public function write(string $path, string $content): string
    {
        if (!preg_match('#^https?://[^/\s]+#i', $path)) {
            throw new \InvalidArgumentException('HTTP target path must be an absolute http(s) URL.');
        }
        $response = $this->httpClient->request('POST', $path, [
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'body' => $content,
            'timeout' => 15,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
        $status = $response->getStatusCode();
        $where = strtolower((string) parse_url($path, \PHP_URL_SCHEME)).'://'.parse_url($path, \PHP_URL_HOST);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('HTTP target returned status '.$status.' for '.$where);
        }

        // Never echo the full URL: it may carry credentials or tokens in its query string.
        return $where.' (status '.$status.')';
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\Provider;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Machine translation via a self-hostable LibreTranslate instance (OSS).
 * Best-effort: on any error the source text is returned, never lost (see lastError()).
 */
final class LibreTranslateProvider implements TranslationProviderInterface
{
    private ?string $lastError = null;

    /** @param array{url:string,api_key?:string,timeout?:int} $config */
    public function __construct(
        private readonly ?HttpClientInterface $httpClient,
        private readonly array $config,
    ) {
    }

    public function name(): string
    {
        return 'libretranslate';
    }

    /** Error of the last translate() call, null when it succeeded. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function translate(string $text, string $from, string $to): string
    {
        $this->lastError = null;
        if (trim($text) === '') {
            return $text;
        }
        $client = $this->httpClient ?? (class_exists(HttpClient::class) ? HttpClient::create() : null);
        if ($client === null) {
            $this->lastError = 'symfony/http-client is not installed.';

            return $text;
        }

        try {
            $response = $client->request('POST', rtrim($this->config['url'], '/').'/translate', [
                'json' => array_filter([
                    'q' => $text, 'source' => $from, 'target' => $to, 'format' => 'text',
                    'api_key' => ($this->config['api_key'] ?? '') ?: null,
                ]),
                'timeout' => (int) ($this->config['timeout'] ?? 10),
                'max_redirects' => 0,
            ]);
            $data = $response->toArray(false);
            if (!isset($data['translatedText']) || !\is_string($data['translatedText'])) {
                $this->lastError = \is_string($data['error'] ?? null) ? $data['error'] : 'Unexpected LibreTranslate response.';

                return $text;
            }

            return $data['translatedText'];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return $text; // best-effort: never lose the source
        }
    }
}

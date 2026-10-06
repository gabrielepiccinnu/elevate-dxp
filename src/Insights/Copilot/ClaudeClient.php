<?php

declare(strict_types=1);

namespace ElevateDxp\Insights\Copilot;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal Anthropic Messages API client. Disabled unless an API key is configured
 * (ANTHROPIC_API_KEY); the key is never exposed to the admin UI.
 */
final class ClaudeClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens = 1024,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function complete(string $prompt, ?string $system = null): string
    {
        if (!$this->isConfigured()) {
            throw new \DomainException('Copilot is not configured: set ANTHROPIC_API_KEY.');
        }
        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($system !== null && $system !== '') {
            $body['system'] = $system;
        }
        try {
            $data = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => ['x-api-key' => $this->apiKey, 'anthropic-version' => self::API_VERSION],
                'json' => $body,
                'timeout' => 90,
            ])->toArray(false);
        } catch (\Throwable $e) {
            throw new \DomainException('Copilot request failed: '.$e->getMessage(), 0, $e);
        }
        if (isset($data['error'])) {
            throw new \DomainException('Copilot API error: '.(string) ($data['error']['message'] ?? 'unknown'));
        }
        $text = '';
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return trim($text);
    }
}

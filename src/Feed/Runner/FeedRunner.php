<?php

declare(strict_types=1);

namespace ElevateDxp\Feed\Runner;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use ElevateDxp\Export\Contract\ExportTargetInterface;
use ElevateDxp\Export\Contract\SourceReaderInterface;
use ElevateDxp\Feed\Template\FeedTemplateInterface;
use ElevateDxp\Feed\Validator\FeedValidator;
use Psr\Container\ContainerInterface;

/** Builds feed rows from a source, validates and renders them, optionally writing via an export target. */
final class FeedRunner
{
    /**
     * @param array<string,array<string,mixed>> $feeds     keyed by feed name
     * @param ContainerInterface                $templates locator keyed by template name
     * @param ContainerInterface                $targets   export target locator keyed by type
     */
    public function __construct(
        private readonly array $feeds,
        private readonly SourceReaderInterface $reader,
        private readonly FeedValidator $validator,
        private readonly ContainerInterface $templates,
        private readonly ContainerInterface $targets,
        private readonly AuditLoggerInterface $audit,
        private readonly int $chunkSize = 100,
        private readonly bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->feeds));
    }

    public function has(string $feedName): bool
    {
        return isset($this->feeds[$feedName]);
    }

    /** @return array<string,mixed> */
    public function feed(string $name): array
    {
        return $this->feeds[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown feed "%s".', $name));
    }

    public function template(string $feedName): FeedTemplateInterface
    {
        $name = (string) ($this->feed($feedName)['template'] ?? '');
        if (!$this->templates->has($name)) {
            throw new \RuntimeException(\sprintf('Unknown feed template "%s".', $name));
        }

        return $this->templates->get($name);
    }

    /** Summary rows for the admin list. */
    public function describe(): array
    {
        $out = [];
        foreach ($this->feeds as $name => $feed) {
            $templateName = (string) ($feed['template'] ?? '');
            $ext = $this->templates->has($templateName) ? $this->templates->get($templateName)->extension() : '';
            $hasToken = self::usableToken($feed['token'] ?? null) !== null;
            $out[] = [
                'name' => (string) $name,
                'template' => $templateName,
                'source' => (string) ($feed['source']['type'] ?? ''),
                'class' => (string) ($feed['source']['class'] ?? ''),
                'currency' => (string) ($feed['currency'] ?? ''),
                'target' => (string) ($feed['target']['type'] ?? 'local').':'.(string) ($feed['target']['path'] ?? ($name.'.'.$ext)),
                'public' => $hasToken,
                'publicPath' => $hasToken ? '/elevate-dxp/feed/'.$name.'.'.$ext.'?token=…' : '',
                'mappings' => (array) ($feed['mappings'] ?? []),
                'static' => (array) ($feed['static'] ?? []),
                'linkPattern' => (string) ($feed['link_pattern'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array{rows:list<array<string,mixed>>,issues:list<array{index:int,missing:list<string>}>}
     */
    public function build(string $feedName, ?int $limit = null): array
    {
        $feed = $this->feed($feedName);
        $template = $this->template($feedName);
        // The feed mappings ARE the explicit field map for the source reader (feedField => sourceAccessor).
        $source = (array) ($feed['source'] ?? []);
        $source['fields'] = (array) ($feed['mappings'] ?? []);
        $rows = $this->reader->read($source, $this->chunkSize, $limit);

        $static = (array) ($feed['static'] ?? []);
        $linkPattern = $feed['link_pattern'] ?? null;
        foreach ($rows as &$row) {
            foreach ($static as $k => $v) {
                if (($row[$k] ?? null) === null || $row[$k] === '') {
                    $row[$k] = $v;
                }
            }
            if (\is_string($linkPattern) && $linkPattern !== '') {
                $row['link'] = self::applyPattern($linkPattern, $row);
            }
        }
        unset($row);

        return ['rows' => $rows, 'issues' => $this->validator->validate($template, $rows)];
    }

    /**
     * Builds and renders the full feed in memory.
     *
     * @return array{content:string,contentType:string,extension:string,rows:int,issues:int}
     */
    public function render(string $feedName): array
    {
        $feed = $this->feed($feedName);
        $template = $this->template($feedName);
        $built = $this->build($feedName);

        return [
            'content' => $template->render($built['rows'], [
                'currency' => $feed['currency'] ?? 'EUR',
                'channel' => $feed['channel'] ?? [],
            ]),
            'contentType' => $template->contentType(),
            'extension' => $template->extension(),
            'rows' => \count($built['rows']),
            'issues' => \count($built['issues']),
        ];
    }

    /** @return array{rows:int,issues:int,location:string} */
    public function export(string $feedName, string $actor = 'cli'): array
    {
        if (!$this->enabled) {
            throw new \RuntimeException('The feed bundle is disabled (elevate_dxp_feed.enabled: false).');
        }
        $feed = $this->feed($feedName);
        try {
            $rendered = $this->render($feedName);
            $targetType = (string) ($feed['target']['type'] ?? 'local');
            $path = (string) ($feed['target']['path'] ?? '');
            if ($path === '') {
                $path = $feedName.'.'.$rendered['extension'];
            }
            if (!$this->targets->has($targetType)) {
                throw new \RuntimeException(\sprintf('No export target "%s".', $targetType));
            }
            /** @var ExportTargetInterface $target */
            $target = $this->targets->get($targetType);
            $location = $target->write($path, $rendered['content']);
        } catch (\Throwable $e) {
            $this->audit->log(new AuditEvent('feed.'.$feedName, $actor, 'failure', ['error' => $e->getMessage()]));
            throw $e;
        }

        $this->audit->log(new AuditEvent('feed.'.$feedName, $actor, 'export', [
            'rows' => $rendered['rows'], 'issues' => $rendered['issues'], 'location' => $location,
        ]));

        return ['rows' => $rendered['rows'], 'issues' => $rendered['issues'], 'location' => $location];
    }

    /**
     * The configured public-URL token, or null when absent or too weak to be accepted.
     */
    public static function usableToken(mixed $token): ?string
    {
        return \is_string($token) && \strlen($token) >= 16 ? $token : null;
    }

    /** @param array<string,mixed> $row */
    public static function applyPattern(string $pattern, array $row): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($row): string {
            $v = $row[$m[1]] ?? '';

            return \is_scalar($v) ? (string) $v : '';
        }, $pattern);
    }
}

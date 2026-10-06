<?php

declare(strict_types=1);

namespace ElevateDxp\Export\Runner;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use ElevateDxp\Export\Contract\ExportRendererInterface;
use ElevateDxp\Export\Contract\ExportTargetInterface;
use ElevateDxp\Export\Contract\SourceReaderInterface;
use Psr\Container\ContainerInterface;

/** Orchestrates one export job: read source, render, write to target, with a lock and audit. */
final class ExportRunner
{
    /**
     * @param array<string,array<string,mixed>> $jobs      keyed by job name
     * @param ContainerInterface                $renderers locator keyed by format
     * @param ContainerInterface                $targets   locator keyed by target type
     */
    public function __construct(
        private readonly array $jobs,
        private readonly int $chunkSize,
        private readonly SourceReaderInterface $reader,
        private readonly ContainerInterface $renderers,
        private readonly ContainerInterface $targets,
        private readonly AuditLoggerInterface $audit,
        private readonly bool $enabled = true,
        private readonly ?string $lockDir = null,
    ) {
    }

    public function has(string $jobName): bool
    {
        return isset($this->jobs[$jobName]);
    }

    /** @return array<string,mixed> the raw job configuration */
    public function job(string $jobName): array
    {
        return $this->jobs[$jobName] ?? throw new \InvalidArgumentException(\sprintf('Unknown export job "%s".', $jobName));
    }

    /** @return list<array{name:string,description:string,format:string,source:string,class:string,target:string,fields:array<string,string>}> */
    public function listJobs(): array
    {
        $out = [];
        foreach ($this->jobs as $name => $job) {
            $out[] = [
                'name' => (string) $name,
                'description' => (string) ($job['description'] ?? ''),
                'format' => (string) ($job['format'] ?? ''),
                'source' => (string) ($job['source']['type'] ?? ''),
                'class' => (string) ($job['source']['class'] ?? ''),
                'target' => (string) ($job['target']['type'] ?? '').':'.(string) ($job['target']['path'] ?? ''),
                'fields' => (array) ($job['source']['fields'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * Reads and renders the first rows of a job without writing anything (no lock, no audit).
     *
     * @return array{rows:list<array<string,mixed>>,content:string,format:string}
     */
    public function preview(string $jobName, int $limit = 20): array
    {
        $job = $this->job($jobName);
        $renderer = $this->renderer((string) $job['format']);
        $rows = $this->reader->read((array) $job['source'], $this->chunkSize, max(1, $limit));

        return ['rows' => $rows, 'content' => $renderer->render($rows), 'format' => $renderer->format()];
    }

    /** @return array{job:string,rows:int,location:string} */
    public function run(string $jobName, string $actor = 'cli'): array
    {
        if (!$this->enabled) {
            throw new \RuntimeException('The export bundle is disabled (elevate_dxp.export.enabled: false).');
        }
        $job = $this->job($jobName);
        $renderer = $this->renderer((string) $job['format']);
        $target = $this->target((string) ($job['target']['type'] ?? ''));

        $lockFile = rtrim($this->lockDir ?? sys_get_temp_dir(), '/').'/edxp_export_'.preg_replace('/[^a-z0-9_]/i', '_', $jobName).'.lock';
        $lockHandle = @fopen($lockFile, 'c');
        if ($lockHandle === false) {
            throw new \RuntimeException(\sprintf('Cannot open lock file for job "%s".', $jobName));
        }
        if (!flock($lockHandle, \LOCK_EX | \LOCK_NB)) {
            fclose($lockHandle);
            throw new \RuntimeException(\sprintf('Job "%s" is already running.', $jobName));
        }

        try {
            $this->audit->log(new AuditEvent('export.'.$jobName, $actor, 'start'));

            $rows = $this->reader->read((array) $job['source'], $this->chunkSize);
            $location = $target->write((string) $job['target']['path'], $renderer->render($rows));

            $this->audit->log(new AuditEvent('export.'.$jobName, $actor, 'success', [
                'rows' => \count($rows), 'location' => $location,
            ]));

            return ['job' => $jobName, 'rows' => \count($rows), 'location' => $location];
        } catch (\Throwable $e) {
            $this->audit->log(new AuditEvent('export.'.$jobName, $actor, 'failure', ['error' => $e->getMessage()]));
            throw $e;
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function renderer(string $format): ExportRendererInterface
    {
        if (!$this->renderers->has($format)) {
            throw new \RuntimeException(\sprintf('No renderer for format "%s".', $format));
        }

        return $this->renderers->get($format);
    }

    private function target(string $type): ExportTargetInterface
    {
        if (!$this->targets->has($type)) {
            throw new \RuntimeException(\sprintf('No target for type "%s".', $type));
        }

        return $this->targets->get($type);
    }
}

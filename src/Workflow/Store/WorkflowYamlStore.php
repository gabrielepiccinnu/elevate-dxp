<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Store;

use ElevateDxp\Workflow\Model\WorkflowDefinition;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads/writes workflow definitions as YAML files under the configured storage directory.
 * File names are derived from a sanitised name, so a definition name can never escape the directory.
 */
class WorkflowYamlStore
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $storageDir,
    ) {
    }

    /** @return list<string> definition names */
    public function names(): array
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (glob($dir.'/*.yaml') ?: [] as $file) {
            $names[] = basename($file, '.yaml');
        }
        sort($names);

        return $names;
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    public function load(string $name): ?WorkflowDefinition
    {
        $file = $this->path($name);
        if (!is_file($file)) {
            return null;
        }
        $data = Yaml::parseFile($file);

        return \is_array($data) ? WorkflowDefinition::fromArray(['name' => $name] + $data) : null;
    }

    public function save(WorkflowDefinition $def): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create workflow storage dir: '.$dir);
        }
        if (file_put_contents($this->path($def->name), Yaml::dump($def->toArray(), 6, 2), \LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write workflow definition '.$def->name);
        }
    }

    public function delete(string $name): bool
    {
        $file = $this->path($name);

        return is_file($file) && unlink($file);
    }

    public function exportConfigYaml(WorkflowDefinition $def): string
    {
        return Yaml::dump($def->toOpenDxpConfig(), 8, 2);
    }

    public static function safeName(string $name): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '_', $name) ?: 'workflow';
    }

    private function dir(): string
    {
        return rtrim($this->projectDir, '/').'/'.trim($this->storageDir, '/');
    }

    private function path(string $name): string
    {
        return $this->dir().'/'.self::safeName($name).'.yaml';
    }
}

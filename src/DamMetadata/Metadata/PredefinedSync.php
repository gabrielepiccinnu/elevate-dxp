<?php

declare(strict_types=1);

namespace ElevateDxp\DamMetadata\Metadata;

use ElevateDxp\Core\Contract\AuditLoggerInterface;
use ElevateDxp\Core\Dto\AuditEvent;
use OpenDxp\Model\Metadata\Predefined;

/**
 * Creates the schema fields as native predefined asset metadata definitions
 * (OpenDxp\Model\Metadata\Predefined), so editors get them in the asset metadata tab.
 * Idempotent: existing definitions are never modified.
 */
final class PredefinedSync
{
    public function __construct(
        private readonly SchemaService $schemas,
        private readonly AuditLoggerInterface $audit,
    ) {
    }

    /** @return list<array{name:string,type:string,group:string,targetSubtype:string,language:string,description:string,config:string}> */
    public function nativeDefinitions(): array
    {
        $items = [];
        foreach ((new Predefined\Listing())->load() as $def) {
            $items[] = [
                'name' => (string) $def->getName(),
                'type' => (string) $def->getType(),
                'group' => (string) ($def->getGroup() ?? ''),
                'targetSubtype' => (string) ($def->getTargetSubtype() ?? ''),
                'language' => (string) ($def->getLanguage() ?? ''),
                'description' => (string) ($def->getDescription() ?? ''),
                'config' => (string) ($def->getConfig() ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @param string|null $schema null = every schema
     *
     * @return array{created:list<string>,existing:int}
     */
    public function sync(?string $schema = null, string $actor = 'cli'): array
    {
        $names = $schema === null || $schema === '' ? $this->schemas->names() : [$schema];
        $existing = $this->nativeDefinitions();
        $created = [];
        $total = 0;

        foreach ($names as $name) {
            if ($this->schemas->get($name) === null) {
                throw new \InvalidArgumentException("Unknown schema '$name'.");
            }
            $definitions = $this->schemas->predefinedDefinitions($name);
            $total += \count($definitions);
            foreach ($this->schemas->missingDefinitions($definitions, $existing) as $def) {
                $p = Predefined::create();
                $p->setName($def['name']);
                $p->setType($def['type']);
                $p->setGroup($def['group']);
                $p->setTargetSubtype($def['targetSubtype']);
                $p->setConfig($def['config']);
                $p->setDescription($def['description'] !== '' ? $def['description'] : null);
                $p->save();
                $existing[] = ['name' => $def['name'], 'targetSubtype' => $def['targetSubtype']];
                $created[] = $def['name'].($def['targetSubtype'] !== null ? ' ('.$def['targetSubtype'].')' : '');
            }
        }

        $this->audit->log(new AuditEvent('dam.metadata.sync_predefined', $actor, 'ok', ['schema' => $schema, 'created' => \count($created)]));

        return ['created' => $created, 'existing' => $total - \count($created)];
    }
}

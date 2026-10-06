<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Model;

/**
 * A Symfony/OpenDXP-compatible workflow definition (places + transitions) with the advanced
 * options the native OpenDXP engine supports: per-transition guard expression, notes
 * (comment enabled/required) and notification roles, and per-place metadata (title, color, lock).
 *
 * @phpstan-type Transition array{from:list<string>,to:string,guard?:string,commentEnabled?:bool,commentRequired?:bool,notifyRoles?:list<string>}
 * @phpstan-type PlaceMeta array{title?:string,color?:string,lockEditing?:bool}
 */
final class WorkflowDefinition
{
    public const DATA_OBJECT_NAMESPACE = 'OpenDxp\\Model\\DataObject\\';

    /**
     * @param list<string>                      $places
     * @param array<string,array<string,mixed>> $transitions
     * @param array<string,array<string,mixed>> $placeMeta
     */
    public function __construct(
        public readonly string $name,
        public readonly string $subject,
        public readonly string $type,            // 'state_machine' | 'workflow'
        public readonly string $initialPlace,
        public readonly array $places,
        public readonly array $transitions,
        public readonly string $label = '',
        public readonly array $placeMeta = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $transitions = [];
        foreach ((array) ($data['transitions'] ?? []) as $tName => $t) {
            $t = (array) $t;
            $entry = [
                'from' => array_values(array_map('strval', (array) ($t['from'] ?? []))),
                'to' => (string) (\is_array($t['to'] ?? null) ? ($t['to'][0] ?? '') : ($t['to'] ?? '')),
            ];
            if (isset($t['guard']) && (string) $t['guard'] !== '') {
                $entry['guard'] = (string) $t['guard'];
            }
            if (!empty($t['commentEnabled'])) {
                $entry['commentEnabled'] = true;
            }
            if (!empty($t['commentRequired'])) {
                $entry['commentEnabled'] = true; // a required comment implies it's enabled
                $entry['commentRequired'] = true;
            }
            $notifyRoles = array_values(array_filter(array_map('strval', (array) ($t['notifyRoles'] ?? []))));
            if ($notifyRoles !== []) {
                $entry['notifyRoles'] = $notifyRoles;
            }
            $transitions[(string) $tName] = $entry;
        }

        $placeMeta = [];
        foreach ((array) ($data['placeMeta'] ?? []) as $place => $meta) {
            $meta = (array) $meta;
            $m = [];
            if (isset($meta['title']) && (string) $meta['title'] !== '') {
                $m['title'] = (string) $meta['title'];
            }
            if (isset($meta['color']) && (string) $meta['color'] !== '') {
                $m['color'] = (string) $meta['color'];
            }
            if (!empty($meta['lockEditing'])) {
                $m['lockEditing'] = true;
            }
            if ($m !== []) {
                $placeMeta[(string) $place] = $m;
            }
        }

        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['subject'] ?? 'Product'),
            ($data['type'] ?? 'state_machine') === 'workflow' ? 'workflow' : 'state_machine',
            (string) ($data['initial_place'] ?? ($data['places'][0] ?? '')),
            array_values(array_map('strval', (array) ($data['places'] ?? []))),
            $transitions,
            (string) ($data['label'] ?? ''),
            $placeMeta,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'label' => $this->label ?: $this->name,
            'subject' => $this->subject,
            'type' => $this->type,
            'initial_place' => $this->initialPlace,
            'places' => $this->places,
            'transitions' => $this->transitions,
        ];
        if ($this->placeMeta !== []) {
            $data['placeMeta'] = $this->placeMeta;
        }

        return $data;
    }

    /** Fully qualified class the workflow supports. */
    public function subjectClass(): string
    {
        return str_contains($this->subject, '\\') ? ltrim($this->subject, '\\') : self::DATA_OBJECT_NAMESPACE.$this->subject;
    }

    /**
     * OpenDXP workflow config fragment: `opendxp: { workflows: { <name>: {...} } }`
     * (see OpenDxp\Bundle\CoreBundle\DependencyInjection\Configuration::addWorkflowNode()).
     *
     * @return array<string,mixed>
     */
    public function toOpenDxpConfig(): array
    {
        $transitions = [];
        foreach ($this->transitions as $name => $t) {
            // The WorkflowPass reads transitionConfig['options'] directly; the config tree leaves
            // 'options' absent when not provided, so it is always emitted. Scalar 'to' is normalised.
            $options = ['label' => $name];
            $notes = [];
            if (!empty($t['commentEnabled']) || !empty($t['commentRequired'])) {
                $notes['commentEnabled'] = true;
            }
            if (!empty($t['commentRequired'])) {
                $notes['commentRequired'] = true;
            }
            if ($notes !== []) {
                $options['notes'] = $notes;
            }
            if (!empty($t['notifyRoles'])) {
                $options['notificationSettings'] = [[
                    'notifyRoles' => array_values($t['notifyRoles']),
                    'channelType' => ['mail'],
                ]];
            }

            $entry = ['from' => $t['from'], 'to' => $t['to'], 'options' => $options];
            if (isset($t['guard']) && (string) $t['guard'] !== '') {
                $entry['guard'] = (string) $t['guard'];
            }
            $transitions[$name] = $entry;
        }

        $places = [];
        foreach ($this->places as $place) {
            $meta = $this->placeMeta[$place] ?? [];
            $cfg = [];
            if (isset($meta['title'])) {
                $cfg['title'] = $meta['title'];
            }
            if (isset($meta['color'])) {
                $cfg['color'] = $meta['color'];
            }
            if (!empty($meta['lockEditing'])) {
                // Lock editing while the element sits in this place (no save/publish/delete/rename).
                $cfg['permissions'] = [['modify' => false]];
            }
            $places[$place] = $cfg;
        }

        return [
            'opendxp' => ['workflows' => [
                $this->name => [
                    'label' => $this->label ?: $this->name,
                    'type' => $this->type,
                    'supports' => [$this->subjectClass()],
                    'places' => $places,
                    'initial_markings' => [$this->initialPlace],
                    'transitions' => $transitions,
                ],
            ]],
        ];
    }
}

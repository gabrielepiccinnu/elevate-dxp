<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Bpmn;

use ElevateDxp\Workflow\Model\WorkflowDefinition;

/**
 * Parses a CONSTRAINED BPMN 2.0 document into a WorkflowDefinition (places + transitions),
 * the inverse of DefinitionToBpmnMapper. Layout/DI is ignored.
 *
 * Rules:
 *   - bpmn:task  -> place (place name = task @name, fallback @id)
 *   - bpmn:sequenceFlow from a startEvent -> its target task is the initial place
 *   - bpmn:sequenceFlow task->task -> transition; flows sharing the same @name are merged
 *     into one transition with multiple `from` places (and-join)
 *
 * Unsupported BPMN elements (gateways, events other than start, sub-processes) are ignored,
 * keeping the result always mappable to opendxp.workflows.*. Extension attributes are read from
 * the Elevate DXP namespace and, for documents exported by the legacy designer, the OpenPimcore one.
 */
final class BpmnToDefinitionMapper
{
    public const MAX_BYTES = 2_000_000;

    public function fromXml(string $xml, string $fallbackName = ''): WorkflowDefinition
    {
        if (\strlen($xml) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('BPMN document too large');
        }
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new \InvalidArgumentException('Invalid BPMN XML: DOCTYPE declarations are not allowed');
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $xml !== '' && $doc->loadXML($xml, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($ok === false) {
            throw new \InvalidArgumentException('Invalid BPMN XML');
        }

        $process = $doc->getElementsByTagNameNS(BpmnNamespaces::BPMN, 'process')->item(0);
        if (!$process instanceof \DOMElement) {
            throw new \InvalidArgumentException('No bpmn:process found');
        }

        $name = $process->getAttribute('id') ?: $fallbackName;
        $label = $process->getAttribute('name');
        $type = $this->ext($process, 'type') ?: 'state_machine';
        $subject = $this->ext($process, 'subject') ?: 'Product';

        $taskNameById = [];
        $startEventIds = [];
        $places = [];
        $placeMeta = [];
        foreach ($this->children($process, 'task') as $task) {
            $id = $task->getAttribute('id');
            $placeName = $task->getAttribute('name') ?: $id;
            $taskNameById[$id] = $placeName;
            $places[] = $placeName;

            $meta = [];
            $title = $this->ext($task, 'title');
            $color = $this->ext($task, 'color');
            if ($title !== '') {
                $meta['title'] = $title;
            }
            if ($color !== '') {
                $meta['color'] = $color;
            }
            if ($this->ext($task, 'lockEditing') === 'true') {
                $meta['lockEditing'] = true;
            }
            if ($meta !== []) {
                $placeMeta[$placeName] = $meta;
            }
        }
        foreach ($this->children($process, 'startEvent') as $start) {
            $startEventIds[$start->getAttribute('id')] = true;
        }

        $initialPlace = '';
        /** @var array<string,array<string,mixed>> $transitions */
        $transitions = [];
        foreach ($this->children($process, 'sequenceFlow') as $flow) {
            $source = $flow->getAttribute('sourceRef');
            $target = $flow->getAttribute('targetRef');

            if (isset($startEventIds[$source]) && isset($taskNameById[$target])) {
                if ($initialPlace === '') {
                    $initialPlace = $taskNameById[$target];
                }
                continue;
            }

            if (!isset($taskNameById[$source], $taskNameById[$target])) {
                continue; // edge touching an unsupported element — skip
            }
            $tName = $flow->getAttribute('name') ?: $flow->getAttribute('id');
            $fromPlace = $taskNameById[$source];

            if (!isset($transitions[$tName])) {
                $transitions[$tName] = ['from' => [], 'to' => $taskNameById[$target]];
            }
            if (!\in_array($fromPlace, $transitions[$tName]['from'], true)) {
                $transitions[$tName]['from'][] = $fromPlace;
            }
            $transitions[$tName]['to'] = $taskNameById[$target];

            $guard = $this->ext($flow, 'guard');
            if ($guard !== '') {
                $transitions[$tName]['guard'] = $guard;
            }
            if ($this->ext($flow, 'commentEnabled') === 'true') {
                $transitions[$tName]['commentEnabled'] = true;
            }
            if ($this->ext($flow, 'commentRequired') === 'true') {
                $transitions[$tName]['commentRequired'] = true;
            }
            $notify = $this->ext($flow, 'notifyRoles');
            if ($notify !== '') {
                $transitions[$tName]['notifyRoles'] = array_values(array_filter(array_map('trim', explode(',', $notify))));
            }
        }

        if ($initialPlace === '' && $places !== []) {
            $initialPlace = $places[0];
        }

        return WorkflowDefinition::fromArray([
            'name' => $name ?: $fallbackName,
            'label' => $label,
            'subject' => $subject,
            'type' => $type,
            'initial_place' => $initialPlace,
            'places' => $places,
            'transitions' => $transitions,
            'placeMeta' => $placeMeta,
        ]);
    }

    private function ext(\DOMElement $el, string $attr): string
    {
        $value = $el->getAttributeNS(BpmnNamespaces::DXPP, $attr);

        return $value !== '' ? $value : $el->getAttributeNS(BpmnNamespaces::LEGACY, $attr);
    }

    /** @return list<\DOMElement> */
    private function children(\DOMElement $process, string $local): array
    {
        $out = [];
        foreach ($process->getElementsByTagNameNS(BpmnNamespaces::BPMN, $local) as $el) {
            if ($el instanceof \DOMElement) {
                $out[] = $el;
            }
        }

        return $out;
    }
}

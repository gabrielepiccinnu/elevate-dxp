<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Bpmn;

use ElevateDxp\Workflow\Model\WorkflowDefinition;

/**
 * Serializes a WorkflowDefinition into a CONSTRAINED BPMN 2.0 document:
 *   - one bpmn:task per place
 *   - one bpmn:startEvent flowing into the initial place
 *   - one bpmn:sequenceFlow per (transition, from-place) pair, named after the transition
 *
 * Transitions with multiple `from` places become multiple sequence flows that share the
 * same name — BpmnToDefinitionMapper regroups them, so the model round-trips losslessly
 * at the places/transitions level.
 *
 * A deterministic auto-layout (BPMN DI) is emitted so bpmn-js can render the diagram even
 * for definitions that were authored as plain YAML (no saved coordinates). The editor may
 * later override these coordinates; the canonical truth stays the places/transitions model.
 */
final class DefinitionToBpmnMapper
{
    private const NS_BPMN = BpmnNamespaces::BPMN;
    private const NS_BPMNDI = BpmnNamespaces::BPMNDI;
    private const NS_DC = BpmnNamespaces::DC;
    private const NS_DI = BpmnNamespaces::DI;
    private const NS_OP = BpmnNamespaces::DXPP;

    public function toXml(WorkflowDefinition $def): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $definitions = $doc->createElementNS(self::NS_BPMN, 'bpmn:definitions');
        // Pre-declare every namespace on the root, matching a conventional bpmn-js export.
        $xmlns = 'http://www.w3.org/2000/xmlns/';
        $definitions->setAttributeNS($xmlns, 'xmlns:bpmndi', self::NS_BPMNDI);
        $definitions->setAttributeNS($xmlns, 'xmlns:dc', self::NS_DC);
        $definitions->setAttributeNS($xmlns, 'xmlns:di', self::NS_DI);
        $definitions->setAttributeNS($xmlns, 'xmlns:elevatedxp', self::NS_OP);
        $definitions->setAttribute('id', 'Definitions_'.$this->id($def->name));
        $definitions->setAttribute('targetNamespace', 'http://elevate-dxp/workflow');
        $doc->appendChild($definitions);

        $process = $doc->createElementNS(self::NS_BPMN, 'bpmn:process');
        $processId = $this->id($def->name);
        $process->setAttribute('id', $processId);
        $process->setAttribute('name', $def->label !== '' ? $def->label : $def->name);
        $process->setAttribute('isExecutable', 'false');
        $process->setAttributeNS(self::NS_OP, 'elevatedxp:type', $def->type);
        $process->setAttributeNS(self::NS_OP, 'elevatedxp:subject', $def->subject);
        $definitions->appendChild($process);

        /** @var array<string,array{x:int,y:int,w:int,h:int}> $bounds id => bounds */
        $bounds = [];
        /** @var array<int,array{id:string,source:string,target:string}> $edges */
        $edges = [];

        // Start event -> initial place.
        if ($def->initialPlace !== '') {
            $start = $doc->createElementNS(self::NS_BPMN, 'bpmn:startEvent');
            $start->setAttribute('id', 'StartEvent_1');
            $start->setAttribute('name', 'start');
            $process->appendChild($start);
            $bounds['StartEvent_1'] = ['x' => 160, 'y' => 182, 'w' => 36, 'h' => 36];

            $flow = $doc->createElementNS(self::NS_BPMN, 'bpmn:sequenceFlow');
            $flow->setAttribute('id', 'flow_init');
            $flow->setAttribute('sourceRef', 'StartEvent_1');
            $flow->setAttribute('targetRef', $this->placeId($def->initialPlace));
            $process->appendChild($flow);
            $edges[] = ['id' => 'flow_init', 'source' => 'StartEvent_1', 'target' => $this->placeId($def->initialPlace)];
        }

        // One task per place, laid out left-to-right.
        $col = 0;
        foreach ($def->places as $place) {
            $id = $this->placeId($place);
            $task = $doc->createElementNS(self::NS_BPMN, 'bpmn:task');
            $task->setAttribute('id', $id);
            $task->setAttribute('name', $place);
            $meta = $def->placeMeta[$place] ?? [];
            if (isset($meta['title']) && $meta['title'] !== '') {
                $task->setAttributeNS(self::NS_OP, 'elevatedxp:title', (string) $meta['title']);
            }
            if (isset($meta['color']) && $meta['color'] !== '') {
                $task->setAttributeNS(self::NS_OP, 'elevatedxp:color', (string) $meta['color']);
            }
            if (!empty($meta['lockEditing'])) {
                $task->setAttributeNS(self::NS_OP, 'elevatedxp:lockEditing', 'true');
            }
            $process->appendChild($task);
            $bounds[$id] = ['x' => 280 + $col * 180, 'y' => 160, 'w' => 100, 'h' => 80];
            ++$col;
        }

        // One sequence flow per (transition, from-place) pair.
        $i = 0;
        foreach ($def->transitions as $name => $t) {
            foreach ($t['from'] as $from) {
                $flowId = 'flow_'.$this->id($name).'_'.$i++;
                $flow = $doc->createElementNS(self::NS_BPMN, 'bpmn:sequenceFlow');
                $flow->setAttribute('id', $flowId);
                $flow->setAttribute('name', $name);
                $flow->setAttribute('sourceRef', $this->placeId($from));
                $flow->setAttribute('targetRef', $this->placeId($t['to']));
                if (isset($t['guard']) && (string) $t['guard'] !== '') {
                    $flow->setAttributeNS(self::NS_OP, 'elevatedxp:guard', (string) $t['guard']);
                }
                if (!empty($t['commentEnabled'])) {
                    $flow->setAttributeNS(self::NS_OP, 'elevatedxp:commentEnabled', 'true');
                }
                if (!empty($t['commentRequired'])) {
                    $flow->setAttributeNS(self::NS_OP, 'elevatedxp:commentRequired', 'true');
                }
                if (!empty($t['notifyRoles'])) {
                    $flow->setAttributeNS(self::NS_OP, 'elevatedxp:notifyRoles', implode(',', $t['notifyRoles']));
                }
                $process->appendChild($flow);
                $edges[] = ['id' => $flowId, 'source' => $this->placeId($from), 'target' => $this->placeId($t['to'])];
            }
        }

        $this->appendDiagram($doc, $definitions, $processId, $bounds, $edges);

        return (string) $doc->saveXML();
    }

    /**
     * @param array<string,array{x:int,y:int,w:int,h:int}>            $bounds
     * @param array<int,array{id:string,source:string,target:string}> $edges
     */
    private function appendDiagram(\DOMDocument $doc, \DOMElement $definitions, string $processId, array $bounds, array $edges): void
    {
        $diagram = $doc->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNDiagram');
        $diagram->setAttribute('id', 'BPMNDiagram_1');
        $definitions->appendChild($diagram);

        $plane = $doc->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNPlane');
        $plane->setAttribute('id', 'BPMNPlane_1');
        $plane->setAttribute('bpmnElement', $processId);
        $diagram->appendChild($plane);

        foreach ($bounds as $elId => $b) {
            $shape = $doc->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNShape');
            $shape->setAttribute('id', $elId.'_di');
            $shape->setAttribute('bpmnElement', $elId);
            $bnd = $doc->createElementNS(self::NS_DC, 'dc:Bounds');
            $bnd->setAttribute('x', (string) $b['x']);
            $bnd->setAttribute('y', (string) $b['y']);
            $bnd->setAttribute('width', (string) $b['w']);
            $bnd->setAttribute('height', (string) $b['h']);
            $shape->appendChild($bnd);
            $plane->appendChild($shape);
        }

        foreach ($edges as $edge) {
            $s = $bounds[$edge['source']] ?? null;
            $t = $bounds[$edge['target']] ?? null;
            if ($s === null || $t === null) {
                continue;
            }
            $di = $doc->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNEdge');
            $di->setAttribute('id', $edge['id'].'_di');
            $di->setAttribute('bpmnElement', $edge['id']);
            // Straight line from source right-center to target left-center.
            $this->waypoint($doc, $di, $s['x'] + $s['w'], (int) ($s['y'] + $s['h'] / 2));
            $this->waypoint($doc, $di, $t['x'], (int) ($t['y'] + $t['h'] / 2));
            $plane->appendChild($di);
        }
    }

    private function waypoint(\DOMDocument $doc, \DOMElement $edge, int $x, int $y): void
    {
        $wp = $doc->createElementNS(self::NS_DI, 'di:waypoint');
        $wp->setAttribute('x', (string) $x);
        $wp->setAttribute('y', (string) $y);
        $edge->appendChild($wp);
    }

    private function placeId(string $place): string
    {
        return 'place_'.$this->id($place);
    }

    private function id(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $value) ?: 'x';
    }
}

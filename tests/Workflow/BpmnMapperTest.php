<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Workflow;

use ElevateDxp\Workflow\Bpmn\BpmnToDefinitionMapper;
use ElevateDxp\Workflow\Bpmn\DefinitionToBpmnMapper;
use ElevateDxp\Workflow\Model\WorkflowDefinition;
use PHPUnit\Framework\TestCase;

final class BpmnMapperTest extends TestCase
{
    private function def(): WorkflowDefinition
    {
        return WorkflowDefinition::fromArray([
            'name' => 'product_review',
            'label' => 'Product review',
            'subject' => 'Product',
            'type' => 'state_machine',
            'initial_place' => 'draft',
            'places' => ['draft', 'in_review', 'approved', 'rejected'],
            'transitions' => [
                'submit' => ['from' => ['draft'], 'to' => 'in_review'],
                'approve' => ['from' => ['in_review'], 'to' => 'approved'],
                'reject' => ['from' => ['in_review'], 'to' => 'rejected'],
                'rework' => ['from' => ['rejected'], 'to' => 'draft'],
            ],
        ]);
    }

    public function testDefinitionToBpmnProducesValidBpmn(): void
    {
        $xml = (new DefinitionToBpmnMapper())->toXml($this->def());
        self::assertStringContainsString('bpmn:definitions', $xml);
        self::assertStringContainsString('<bpmn:task id="place_draft" name="draft"', $xml);
        self::assertStringContainsString('name="submit"', $xml);
        self::assertStringContainsString('StartEvent_1', $xml);

        // Well-formed XML.
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
    }

    public function testDefinitionToBpmnEmitsDiagramInterchange(): void
    {
        // Without DI (coordinates) bpmn-js renders an empty canvas, so every node and edge
        // must carry a BPMNShape / BPMNEdge with bounds/waypoints.
        $xml = (new DefinitionToBpmnMapper())->toXml($this->def());
        self::assertStringContainsString('bpmndi:BPMNDiagram', $xml);
        self::assertStringContainsString('bpmndi:BPMNPlane', $xml);
        self::assertStringContainsString('bpmndi:BPMNShape', $xml);
        self::assertStringContainsString('bpmndi:BPMNEdge', $xml);
        self::assertStringContainsString('waypoint', $xml);

        // One shape per place (4) + start event (1) = 5 shapes.
        self::assertSame(5, substr_count($xml, '<bpmndi:BPMNShape'));
    }

    public function testRoundTripPreservesModel(): void
    {
        $original = $this->def();
        $xml = (new DefinitionToBpmnMapper())->toXml($original);
        $parsed = (new BpmnToDefinitionMapper())->fromXml($xml);

        self::assertSame($original->name, $parsed->name);
        self::assertSame($original->type, $parsed->type);
        self::assertSame($original->subject, $parsed->subject);
        self::assertSame($original->initialPlace, $parsed->initialPlace);
        self::assertSame($original->places, $parsed->places);
        self::assertEquals($original->transitions, $parsed->transitions);
    }

    public function testRoundTripMergesMultiFromTransition(): void
    {
        // An and-join: two source places feed the same transition name.
        $def = WorkflowDefinition::fromArray([
            'name' => 'merge_wf',
            'type' => 'state_machine',
            'initial_place' => 'a',
            'places' => ['a', 'b', 'c'],
            'transitions' => [
                'finish' => ['from' => ['a', 'b'], 'to' => 'c'],
            ],
        ]);

        $xml = (new DefinitionToBpmnMapper())->toXml($def);
        // Two sequence flows share the transition name "finish".
        self::assertSame(2, substr_count($xml, 'name="finish"'));

        $parsed = (new BpmnToDefinitionMapper())->fromXml($xml);
        self::assertArrayHasKey('finish', $parsed->transitions);
        self::assertSame(['a', 'b'], $parsed->transitions['finish']['from']);
        self::assertSame('c', $parsed->transitions['finish']['to']);
    }

    public function testParserIgnoresUnsupportedElements(): void
    {
        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL">
          <bpmn:process id="wf" name="WF">
            <bpmn:startEvent id="S1"/>
            <bpmn:task id="place_a" name="a"/>
            <bpmn:task id="place_b" name="b"/>
            <bpmn:exclusiveGateway id="GW1"/>
            <bpmn:sequenceFlow id="f0" sourceRef="S1" targetRef="place_a"/>
            <bpmn:sequenceFlow id="go" name="go" sourceRef="place_a" targetRef="place_b"/>
            <bpmn:sequenceFlow id="fx" sourceRef="place_b" targetRef="GW1"/>
          </bpmn:process>
        </bpmn:definitions>
        XML;

        $def = (new BpmnToDefinitionMapper())->fromXml($xml);
        self::assertSame(['a', 'b'], $def->places);
        self::assertSame('a', $def->initialPlace);
        self::assertArrayHasKey('go', $def->transitions);
        // The flow to the gateway is ignored (gateway is not a place).
        self::assertCount(1, $def->transitions);
    }

    public function testToOpenDxpConfigEmitsOptionsForEachTransition(): void
    {
        // Regression: OpenDXP's WorkflowPass reads transition['options']; it must be present.
        $cfg = $this->def()->toOpenDxpConfig();
        foreach ($cfg['opendxp']['workflows']['product_review']['transitions'] as $name => $t) {
            self::assertArrayHasKey('options', $t, "transition $name must carry options");
        }
    }

    private function advancedDef(): WorkflowDefinition
    {
        return WorkflowDefinition::fromArray([
            'name' => 'adv',
            'type' => 'state_machine',
            'initial_place' => 'draft',
            'places' => ['draft', 'approved'],
            'placeMeta' => [
                'approved' => ['title' => 'Approved', 'color' => '#52c41a', 'lockEditing' => true],
            ],
            'transitions' => [
                'approve' => [
                    'from' => ['draft'], 'to' => 'approved',
                    'guard' => 'is_fully_authenticated()',
                    'commentRequired' => true,
                    'notifyRoles' => ['Editor', 'Manager'],
                ],
            ],
        ]);
    }

    public function testRoundTripPreservesAdvancedOptions(): void
    {
        $def = $this->advancedDef();
        $xml = (new DefinitionToBpmnMapper())->toXml($def);
        $parsed = (new BpmnToDefinitionMapper())->fromXml($xml);

        self::assertSame('is_fully_authenticated()', $parsed->transitions['approve']['guard']);
        self::assertTrue($parsed->transitions['approve']['commentRequired']);
        self::assertSame(['Editor', 'Manager'], $parsed->transitions['approve']['notifyRoles']);
        self::assertSame(['title' => 'Approved', 'color' => '#52c41a', 'lockEditing' => true], $parsed->placeMeta['approved']);
    }

    public function testToOpenDxpConfigEmitsGuardNotesAndPlaceMeta(): void
    {
        $wf = $this->advancedDef()->toOpenDxpConfig()['opendxp']['workflows']['adv'];

        self::assertSame('is_fully_authenticated()', $wf['transitions']['approve']['guard']);
        self::assertTrue($wf['transitions']['approve']['options']['notes']['commentEnabled']);
        self::assertTrue($wf['transitions']['approve']['options']['notes']['commentRequired']);
        self::assertSame('Approved', $wf['places']['approved']['title']);
        self::assertSame('#52c41a', $wf['places']['approved']['color']);
        self::assertSame(['Editor', 'Manager'], $wf['transitions']['approve']['options']['notificationSettings'][0]['notifyRoles']);
        self::assertFalse($wf['places']['approved']['permissions'][0]['modify']);
    }
}

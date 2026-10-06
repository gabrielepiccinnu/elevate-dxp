<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Workflow;

use ElevateDxp\Workflow\Mermaid\MermaidRenderer;
use ElevateDxp\Workflow\Model\WorkflowDefinition;
use ElevateDxp\Workflow\Validator\WorkflowValidator;
use PHPUnit\Framework\TestCase;

final class WorkflowTest extends TestCase
{
    private function def(): WorkflowDefinition
    {
        return WorkflowDefinition::fromArray([
            'name' => 'product_review',
            'subject' => 'Product',
            'type' => 'state_machine',
            'initial_place' => 'draft',
            'places' => ['draft', 'in_review', 'approved'],
            'transitions' => [
                'submit' => ['from' => ['draft'], 'to' => 'in_review'],
                'approve' => ['from' => ['in_review'], 'to' => 'approved'],
            ],
        ]);
    }

    public function testToOpenDxpConfig(): void
    {
        $cfg = $this->def()->toOpenDxpConfig();
        self::assertArrayHasKey('opendxp', $cfg);
        $wf = $cfg['opendxp']['workflows']['product_review'];
        self::assertSame('state_machine', $wf['type']);
        self::assertContains('OpenDxp\\Model\\DataObject\\Product', $wf['supports']);
        self::assertSame(['draft'], $wf['initial_markings']);
        self::assertArrayHasKey('submit', $wf['transitions']);
        self::assertArrayHasKey('draft', $wf['places']);
    }

    public function testValidatorAcceptsValid(): void
    {
        self::assertSame([], (new WorkflowValidator())->validate($this->def()));
    }

    public function testValidatorDetectsBadInitialAndTransition(): void
    {
        $bad = WorkflowDefinition::fromArray([
            'name' => 'x', 'places' => ['a', 'b'], 'initial_place' => 'zzz',
            'transitions' => ['t' => ['from' => ['a'], 'to' => 'nope']],
        ]);
        $errors = (new WorkflowValidator())->validate($bad);
        self::assertNotEmpty($errors);
        self::assertTrue((bool) array_filter($errors, fn ($e) => str_contains($e, 'initial_place')));
        self::assertTrue((bool) array_filter($errors, fn ($e) => str_contains($e, "to 'nope'")));
    }

    public function testMermaid(): void
    {
        $m = (new MermaidRenderer())->render($this->def());
        self::assertStringContainsString('stateDiagram-v2', $m);
        self::assertStringContainsString('[*] --> draft', $m);
        self::assertStringContainsString('draft --> in_review: submit', $m);
    }
}

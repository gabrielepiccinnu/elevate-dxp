<?php

declare(strict_types=1);

namespace ElevateDxp\Tests\Workflow;

use ElevateDxp\Workflow\Admin\WorkflowResource;
use ElevateDxp\Workflow\Bpmn\BpmnToDefinitionMapper;
use ElevateDxp\Workflow\Bpmn\DefinitionToBpmnMapper;
use ElevateDxp\Workflow\Config\WorkflowConfigWriter;
use ElevateDxp\Workflow\Mermaid\MermaidRenderer;
use ElevateDxp\Workflow\Model\DemoWorkflow;
use ElevateDxp\Workflow\Model\WorkflowDefinition;
use ElevateDxp\Workflow\Store\WorkflowYamlStore;
use ElevateDxp\Workflow\Validator\WorkflowValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class WorkflowExtrasTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/edxp-wf-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function resource(?string $renderUrl = 'https://mermaid.ink/svg/'): WorkflowResource
    {
        return new WorkflowResource(
            new WorkflowYamlStore($this->dir, 'var/wf'),
            new WorkflowValidator(),
            new MermaidRenderer(),
            new DefinitionToBpmnMapper(),
            new BpmnToDefinitionMapper(),
            new WorkflowConfigWriter($this->dir, 'config/local'),
            $renderUrl,
        );
    }

    public function testStoreRoundTripAndSafeFileNames(): void
    {
        $store = new WorkflowYamlStore($this->dir, 'var/wf');
        $store->save(DemoWorkflow::create());
        self::assertSame(['product_review'], $store->names());
        self::assertEquals(DemoWorkflow::create()->toArray(), $store->load('product_review')?->toArray());
        self::assertSame('______etc_passwd', WorkflowYamlStore::safeName('../../etc/passwd'));
        self::assertNull($store->load('../../etc/passwd'));
        self::assertTrue($store->delete('product_review'));
        self::assertSame([], $store->names());
    }

    public function testApplyWritesOpenDxpWorkflowConfigAndTracksStatus(): void
    {
        $writer = new WorkflowConfigWriter($this->dir, 'config/local');
        $def = DemoWorkflow::create();
        self::assertSame(WorkflowConfigWriter::STATUS_NOT_APPLIED, $writer->status($def));

        $file = $writer->write($def);
        self::assertSame($this->dir.'/config/local/elevate_dxp_workflow_product_review.yaml', $file);
        $cfg = Yaml::parseFile($file);
        self::assertSame(['opendxp'], array_keys($cfg));
        $wf = $cfg['opendxp']['workflows']['product_review'];
        self::assertSame(['OpenDxp\\Model\\DataObject\\Product'], $wf['supports']);
        self::assertSame(['draft'], $wf['initial_markings']);
        self::assertTrue($wf['transitions']['reject']['options']['notes']['commentRequired']);
        self::assertSame(WorkflowConfigWriter::STATUS_APPLIED, $writer->status($def));

        $changed = WorkflowDefinition::fromArray(['label' => 'Changed'] + $def->toArray());
        self::assertSame(WorkflowConfigWriter::STATUS_OUTDATED, $writer->status($changed));
        self::assertTrue($writer->remove('product_review'));
        self::assertFalse($writer->remove('product_review'));
    }

    public function testFullyQualifiedSubjectIsKept(): void
    {
        $def = WorkflowDefinition::fromArray(['name' => 'x', 'subject' => '\\App\\Model\\Thing', 'places' => ['a']]);
        self::assertSame('App\\Model\\Thing', $def->subjectClass());
    }

    public function testValidatorRejectsBadNamesDuplicatesColoursAndMissingTransitions(): void
    {
        $errors = (new WorkflowValidator())->validate(WorkflowDefinition::fromArray([
            'name' => 'bad name; rm -rf', 'subject' => 'Pro duct', 'places' => ['a', 'a'],
            'placeMeta' => ['a' => ['color' => 'red;}'], 'ghost' => ['title' => 'x']],
        ]));
        $joined = implode("\n", $errors);
        self::assertStringContainsString('name', $joined);
        self::assertStringContainsString('subject', $joined);
        self::assertStringContainsString("place 'a' is declared more than once", $joined);
        self::assertStringContainsString('at least one transition', $joined);
        self::assertStringContainsString('hex colour', $joined);
        self::assertStringContainsString("placeMeta 'ghost'", $joined);
    }

    public function testImportReadsElevateDxpExtensionAttributes(): void
    {
        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:elevatedxp="http://elevate-dxp/bpmn">
          <bpmn:process id="article" elevatedxp:type="workflow" elevatedxp:subject="Article">
            <bpmn:task id="a" name="a"/>
            <bpmn:task id="b" name="b" elevatedxp:color="#123456"/>
            <bpmn:sequenceFlow id="go" name="go" sourceRef="a" targetRef="b" elevatedxp:guard="is_fully_authenticated()"/>
          </bpmn:process>
        </bpmn:definitions>
        XML;
        $def = (new BpmnToDefinitionMapper())->fromXml($xml);
        self::assertSame('workflow', $def->type);
        self::assertSame('Article', $def->subject);
        self::assertSame('#123456', $def->placeMeta['b']['color']);
        self::assertSame('is_fully_authenticated()', $def->transitions['go']['guard']);
    }

    public function testImportIgnoresExtensionAttributesInOtherNamespaces(): void
    {
        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:other="http://example.com/bpmn">
          <bpmn:process id="article" other:type="workflow" other:subject="Article">
            <bpmn:task id="a" name="a"/>
            <bpmn:task id="b" name="b" other:color="#123456"/>
            <bpmn:sequenceFlow id="go" name="go" sourceRef="a" targetRef="b" other:guard="is_fully_authenticated()"/>
          </bpmn:process>
        </bpmn:definitions>
        XML;
        $def = (new BpmnToDefinitionMapper())->fromXml($xml);
        self::assertSame('state_machine', $def->type);
        self::assertSame('Product', $def->subject);
        self::assertSame([], $def->placeMeta);
        self::assertArrayNotHasKey('guard', $def->transitions['go']);
    }

    public function testExportUsesElevateDxpNamespace(): void
    {
        $xml = (new DefinitionToBpmnMapper())->toXml(DemoWorkflow::create());
        self::assertStringContainsString('xmlns:elevatedxp="http://elevate-dxp/bpmn"', $xml);
        self::assertStringContainsString('elevatedxp:subject="Product"', $xml);
    }

    public function testImportRejectsDoctypeAndGarbage(): void
    {
        $mapper = new BpmnToDefinitionMapper();
        try {
            $mapper->fromXml('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>');
            self::fail('DOCTYPE must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('DOCTYPE', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $mapper->fromXml('not xml');
    }

    public function testMermaidUrls(): void
    {
        $m = new MermaidRenderer();
        $url = $m->renderUrl('https://mermaid.ink/svg/', "stateDiagram-v2\n    a --> b");
        self::assertStringStartsWith('https://mermaid.ink/svg/', $url);
        self::assertDoesNotMatchRegularExpression('#[+=]#', substr($url, 24));
        $live = $m->liveEditorUrl('graph');
        $state = json_decode(base64_decode(substr($live, \strlen('https://mermaid.live/edit#base64:'))), true);
        self::assertSame('graph', $state['code']);
    }

    public function testResourceSavesDefinitionFromYamlOrJsonAndRejectsInvalid(): void
    {
        $r = $this->resource();
        $saved = $r->save([
            'name' => 'review', 'label' => 'Review', 'subject' => 'Product', 'type' => 'state_machine',
            'definition' => "places: [draft, done]\ntransitions:\n  finish: { from: [draft], to: done }\n",
        ]);
        self::assertSame('review', $saved['name']);
        self::assertSame('valid', $saved['validation']);
        self::assertSame(2, $saved['place_count']);
        self::assertStringContainsString('finish:', $saved['definition']);

        $json = $r->definitionFromForm(['name' => 'j', 'definition' => '{"places":["a","b"],"transitions":{"t":{"from":["a"],"to":"b"}}}']);
        self::assertSame(['a', 'b'], $json->places);

        self::assertSame(1, $r->list([])['total']);

        $this->expectException(\InvalidArgumentException::class);
        $r->save(['name' => 'broken', 'definition' => "places: [a]\ntransitions:\n  t: { from: [a], to: nowhere }\n"]);
    }

    public function testResourceActions(): void
    {
        $r = $this->resource(null);
        $r->runAction('demo_seed', null, []);

        $preview = $r->runAction('preview', 'product_review', []);
        self::assertStringNotContainsString('<img', $preview['html'], 'renderer disabled → no external image');
        self::assertStringContainsString('draft --&gt; in_review: submit', $preview['html']);

        self::assertStringContainsString('<bpmn:definitions', $r->runAction('export_bpmn', 'product_review', [])['text']);
        self::assertStringContainsString('valid', $r->runAction('validate', 'product_review', [])['message']);

        $applied = $r->runAction('apply', 'product_review', []);
        self::assertStringContainsString('cache:clear', $applied['message']);
        self::assertFileExists($this->dir.'/config/local/elevate_dxp_workflow_product_review.yaml');
        self::assertSame('applied', $r->get('product_review')['applied']);

        $xml = $r->runAction('export_bpmn', 'product_review', [])['text'];
        $r->runAction('import_bpmn', null, ['name' => 'copy_of_review', 'xml' => $xml]);
        self::assertSame(DemoWorkflow::create()->transitions, (new WorkflowYamlStore($this->dir, 'var/wf'))->load('copy_of_review')?->transitions);

        $r->runAction('unapply', 'product_review', []);
        self::assertFileDoesNotExist($this->dir.'/config/local/elevate_dxp_workflow_product_review.yaml');
    }

    public function testPreviewWithRendererEmbedsEscapedImage(): void
    {
        $html = $this->resource()->previewHtml(DemoWorkflow::create());
        self::assertStringContainsString('<img alt="Workflow diagram"', $html);
        self::assertStringContainsString('src="https://mermaid.ink/svg/', $html);
        self::assertStringContainsString('mermaid.live/edit#base64:', $html);
    }
}

<?php

declare(strict_types=1);

namespace ElevateDxp\Workflow\Admin;

use ElevateDxp\Core\Admin\AbstractAdminResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;
use ElevateDxp\Workflow\Bpmn\BpmnToDefinitionMapper;
use ElevateDxp\Workflow\Bpmn\DefinitionToBpmnMapper;
use ElevateDxp\Workflow\Config\WorkflowConfigWriter;
use ElevateDxp\Workflow\Installer\WorkflowInstaller;
use ElevateDxp\Workflow\Mermaid\MermaidRenderer;
use ElevateDxp\Workflow\Model\DemoWorkflow;
use ElevateDxp\Workflow\Model\WorkflowDefinition;
use ElevateDxp\Workflow\Store\WorkflowYamlStore;
use ElevateDxp\Workflow\Validator\WorkflowValidator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Workflow designer (replaces the Studio bpmn-js designer): CRUD over the YAML store, the
 * places/transitions edited as YAML or JSON, plus validate, Mermaid preview, BPMN export/import,
 * export of the generated opendxp.workflows config and apply (requires cache:clear).
 */
final class WorkflowResource extends AbstractAdminResource
{
    public function __construct(
        private readonly WorkflowYamlStore $store,
        private readonly WorkflowValidator $validator,
        private readonly MermaidRenderer $mermaid,
        private readonly DefinitionToBpmnMapper $toBpmn,
        private readonly BpmnToDefinitionMapper $fromBpmn,
        private readonly WorkflowConfigWriter $writer,
        private readonly ?string $mermaidRenderUrl = 'https://mermaid.ink/svg/',
    ) {
    }

    public function getKey(): string
    {
        return 'workflows';
    }

    public function getLabel(): string
    {
        return 'Workflow designer';
    }

    public function getGroup(): string
    {
        return 'Content';
    }

    public function getIconCls(): string
    {
        return 'elevatedxp_icon_workflow';
    }

    public function getPermission(): string
    {
        return WorkflowInstaller::PERMISSION;
    }

    public function getSchema(): array
    {
        return [
            'panel' => 'crud',
            'idProperty' => 'name',
            'fields' => [
                Field::text('name', 'Name', ['required' => true, 'width' => 180, 'help' => 'Technical name: letters, digits, underscores. Saving under an existing name overwrites it.']),
                Field::text('label', 'Label'),
                Field::text('subject', 'Subject class', ['default' => 'Product', 'width' => 140, 'help' => 'DataObject class name (e.g. Product) or a fully qualified class.']),
                Field::select('type', 'Type', ['state_machine' => 'State machine', 'workflow' => 'Workflow (parallel places)'], ['default' => 'state_machine', 'width' => 120]),
                Field::text('initial_place', 'Initial place', ['width' => 120]),
                Field::code('definition', 'Places & transitions (YAML or JSON)', [
                    'required' => true,
                    'default' => self::definitionTemplate(),
                    'help' => 'places: [..], transitions: {name: {from: [..], to: .., guard?, commentEnabled?, commentRequired?, notifyRoles?: [..]}}, placeMeta: {place: {title?, color?, lockEditing?}}',
                ]),
                Field::number('place_count', 'Places', ['readOnly' => true, 'virtual' => true, 'width' => 80]),
                Field::number('transition_count', 'Transitions', ['readOnly' => true, 'virtual' => true, 'width' => 90]),
                Field::text('validation', 'Validation', ['readOnly' => true, 'virtual' => true]),
                Field::select('applied', 'Applied', [
                    WorkflowConfigWriter::STATUS_APPLIED => 'Applied',
                    WorkflowConfigWriter::STATUS_OUTDATED => 'Outdated',
                    WorkflowConfigWriter::STATUS_NOT_APPLIED => 'Not applied',
                ], ['readOnly' => true, 'virtual' => true, 'width' => 100]),
            ],
            'actions' => [
                Action::record('validate', 'Validate', ['iconCls' => 'opendxp_icon_accept']),
                Action::record('preview', 'Preview (Mermaid)', ['iconCls' => 'opendxp_icon_preview']),
                Action::record('export_bpmn', 'Export BPMN', ['iconCls' => 'opendxp_icon_export']),
                Action::record('export_config', 'Show OpenDXP config', ['iconCls' => 'opendxp_icon_text']),
                Action::record('apply', 'Apply', ['iconCls' => 'opendxp_icon_apply', 'confirm' => 'Write this workflow to the OpenDXP configuration? It becomes active after bin/console cache:clear.']),
                Action::record('unapply', 'Un-apply', ['iconCls' => 'opendxp_icon_cancel', 'confirm' => 'Remove the generated configuration file of this workflow?']),
                Action::global('import_bpmn', 'Import BPMN', ['iconCls' => 'opendxp_icon_import', 'params' => [
                    Field::text('name', 'Name (optional, defaults to the process id)'),
                    Field::code('xml', 'BPMN 2.0 XML', ['required' => true]),
                ]]),
                Action::global('demo_seed', 'Create demo workflow', ['iconCls' => 'opendxp_icon_add']),
            ],
            'canCreate' => true,
            'canEdit' => true,
            'canDelete' => true,
        ];
    }

    public function list(array $query): array
    {
        $rows = [];
        foreach ($this->store->names() as $name) {
            $def = $this->store->load($name);
            $rows[] = $def === null
                ? ['name' => $name, 'label' => $name, 'validation' => 'unreadable']
                : $this->row($def, false);
        }

        return $this->paginate($rows, $query, ['name', 'label', 'subject']);
    }

    public function get(string $id): ?array
    {
        $def = $this->store->load($id);

        return $def === null ? null : $this->row($def, true);
    }

    public function save(array $data): array
    {
        $def = $this->definitionFromForm($data);
        $this->assertValid($def);
        $this->store->save($def);

        return $this->get($def->name) ?? [];
    }

    public function delete(string $id): void
    {
        if (!$this->store->delete($id)) {
            throw new \InvalidArgumentException('Workflow not found: '.$id);
        }
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        switch ($action) {
            case 'import_bpmn':
                $def = $this->fromBpmn->fromXml((string) ($params['xml'] ?? ''), trim((string) ($params['name'] ?? '')));
                $name = trim((string) ($params['name'] ?? ''));
                if ($name !== '') {
                    $def = WorkflowDefinition::fromArray(['name' => $name] + $def->toArray());
                }
                $this->assertValid($def);
                $this->store->save($def);

                return Action::message(\sprintf('Workflow "%s" imported (%d places, %d transitions).', $def->name, \count($def->places), \count($def->transitions)), true);
            case 'demo_seed':
                $demo = DemoWorkflow::create();
                if ($this->store->exists($demo->name)) {
                    throw new \InvalidArgumentException(\sprintf('Workflow "%s" already exists.', $demo->name));
                }
                $this->store->save($demo);

                return Action::message(\sprintf('Demo workflow "%s" created.', $demo->name), true);
        }

        $def = ($id !== null ? $this->store->load($id) : null) ?? throw new \InvalidArgumentException('Please select a workflow.');

        switch ($action) {
            case 'validate':
                $errors = $this->validator->validate($def);
                if ($errors === []) {
                    return Action::message(\sprintf('Workflow "%s" is valid.', $def->name));
                }

                return Action::table(array_map(static fn (string $e): array => ['error' => $e], $errors), \sprintf('%d problem(s) in "%s"', \count($errors), $def->name));
            case 'preview':
                return Action::html($this->previewHtml($def), 'Workflow: '.($def->label ?: $def->name));
            case 'export_bpmn':
                return Action::text($this->toBpmn->toXml($def), $def->name.'.bpmn', 'xml');
            case 'export_config':
                return Action::text($this->writer->render($def), basename($this->writer->path($def->name)), 'yaml');
            case 'apply':
                $this->assertValid($def);
                $file = $this->writer->write($def);

                return Action::message(\sprintf('Written to %s. Run "bin/console cache:clear" to activate the workflow.', $file), true);
            case 'unapply':
                if (!$this->writer->remove($def->name)) {
                    throw new \InvalidArgumentException('This workflow has not been applied.');
                }

                return Action::message('Configuration removed. Run "bin/console cache:clear" to deactivate the workflow.', true);
        }

        return parent::runAction($action, $id, $params);
    }

    /** Mermaid preview: server-rendered SVG image (img-src is allowed by the admin CSP) + source + live editor link. */
    public function previewHtml(WorkflowDefinition $def): string
    {
        $code = $this->mermaid->render($def);
        $html = '';
        if ($this->mermaidRenderUrl !== null && $this->mermaidRenderUrl !== '') {
            $html .= '<p><img alt="Workflow diagram" style="max-width:100%;background:#fff" src="'
                .htmlspecialchars($this->mermaid->renderUrl($this->mermaidRenderUrl, $code), \ENT_QUOTES).'"></p>';
        }
        $html .= '<p><a target="_blank" rel="noopener noreferrer" href="'.htmlspecialchars($this->mermaid->liveEditorUrl($code), \ENT_QUOTES).'">Open in Mermaid Live Editor</a></p>';
        $errors = $this->validator->validate($def);
        if ($errors !== []) {
            $html .= '<p style="color:#c00"><b>Validation:</b> '.htmlspecialchars(implode('; ', $errors), \ENT_QUOTES).'</p>';
        }

        return $html.'<pre style="background:#f6f8fa;padding:8px;white-space:pre-wrap">'.htmlspecialchars($code, \ENT_QUOTES).'</pre>';
    }

    /** Builds a definition from the edit form: top-level fields + YAML/JSON "definition" block. */
    public function definitionFromForm(array $data): WorkflowDefinition
    {
        $raw = $data['definition'] ?? '';
        if (\is_string($raw)) {
            try {
                $parsed = trim($raw) === '' ? [] : Yaml::parse($raw);
            } catch (ParseException $e) {
                throw new \InvalidArgumentException('Definition: invalid YAML/JSON — '.$e->getMessage());
            }
        } else {
            $parsed = $raw;
        }
        if (!\is_array($parsed)) {
            throw new \InvalidArgumentException('Definition must be a YAML/JSON object with places and transitions.');
        }
        foreach (['name', 'label', 'subject', 'type', 'initial_place'] as $key) {
            if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                $parsed[$key] = trim((string) $data[$key]);
            }
        }

        return WorkflowDefinition::fromArray($parsed);
    }

    private function assertValid(WorkflowDefinition $def): void
    {
        $errors = $this->validator->validate($def);
        if ($errors !== []) {
            throw new \InvalidArgumentException('Invalid workflow: '.implode('; ', $errors));
        }
    }

    private function row(WorkflowDefinition $def, bool $withDefinition): array
    {
        $errors = $this->validator->validate($def);
        $row = [
            'name' => $def->name,
            'label' => $def->label ?: $def->name,
            'subject' => $def->subject,
            'type' => $def->type,
            'initial_place' => $def->initialPlace,
            'place_count' => \count($def->places),
            'transition_count' => \count($def->transitions),
            'validation' => $errors === [] ? 'valid' : \count($errors).' error(s): '.implode('; ', $errors),
            'applied' => $this->writer->status($def),
        ];
        if ($withDefinition) {
            $body = ['places' => $def->places, 'transitions' => $def->transitions];
            if ($def->placeMeta !== []) {
                $body['placeMeta'] = $def->placeMeta;
            }
            $row['definition'] = Yaml::dump($body, 6, 2);
        }

        return $row;
    }

    private static function definitionTemplate(): string
    {
        return "places: [draft, in_review, approved]\n"
            ."transitions:\n"
            ."  submit: { from: [draft], to: in_review }\n"
            ."  approve: { from: [in_review], to: approved, commentEnabled: true }\n";
    }
}

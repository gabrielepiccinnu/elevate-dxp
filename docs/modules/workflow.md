# Elevate DXP Workflow Bundle

`elevate-dxp/workflow-bundle` (`ElevateDxp\Workflow`), GPL-3.0-or-later.

A workflow designer for the native OpenDXP (Symfony Workflow) engine. Definitions are stored as YAML files (GitOps-friendly), validated, previewed as Mermaid diagrams, imported and exported as constrained BPMN 2.0, and **applied** as `opendxp.workflows` configuration.

It is a port of the legacy `OpenPimcore\WorkflowBundle`. The Studio bpmn-js designer is replaced by the `workflows` admin resource.

## Installation

```bash
bin/console opendxp:bundle:install ElevateDxpWorkflowBundle   # registers permission elevate_dxp_workflow
bin/console elevate-dxp:workflow:demo-seed                    # optional demo "product_review"
```

No tables are created.

## Configuration (`elevate_dxp_workflow`)

```yaml
elevate_dxp_workflow:
    storage_dir: var/elevate-dxp/workflows      # designer definitions (relative to the project dir)
    apply_dir: config/local                  # where "apply" writes elevate_dxp_workflow_<name>.yaml
    mermaid_render_url: 'https://mermaid.ink/svg/'   # null = no external rendering
```

`config/config.yaml` of the app already imports `local/`, so applied files are picked up automatically. The `config/local` directory must be writable by the PHP user. Do not commit `config/local`. Commit the designer YAML under `storage_dir` and apply it per environment instead, or point `apply_dir` to a committed directory that your config imports.

## Definition format

```yaml
name: product_review          # letters, digits, underscore
label: Product review
subject: Product              # DataObject class, or a fully qualified class
type: state_machine           # or "workflow" (parallel places)
initial_place: draft
places: [draft, in_review, approved, rejected]
transitions:
  submit:  { from: [draft], to: in_review }
  approve: { from: [in_review], to: approved, guard: "is_granted('ROLE_OPENDXP_ADMIN')", notifyRoles: [Editor] }
  reject:  { from: [in_review], to: rejected, commentRequired: true }
placeMeta:
  approved: { title: Approved, color: '#52c41a', lockEditing: true }
```

**Apply** writes this fragment, which matches `OpenDxp\Bundle\CoreBundle\DependencyInjection\Configuration::addWorkflowNode()`:

```yaml
opendxp:
    workflows:
        product_review:
            label: Product review
            type: state_machine
            supports: ['OpenDxp\Model\DataObject\Product']
            places: { approved: { title: Approved, color: '#52c41a', permissions: [{ modify: false }] }, ... }
            initial_markings: [draft]
            transitions:
                submit: { from: [draft], to: in_review, options: { label: submit } }
                reject: { from: [in_review], to: rejected, options: { label: reject, notes: { commentEnabled: true, commentRequired: true } } }
```

`options` is always emitted, because the OpenDXP `WorkflowPass` reads it directly. Workflows are compiled into the container, so **run `bin/console cache:clear` after apply or un-apply**. The admin message and the CLI both remind you. No marking store is written: OpenDXP defaults to `state_table`.

## Admin resource `workflows`

The resource is in the **Content** group and requires `elevate_dxp_workflow`.

It is a crud resource keyed by `name`. The form edits name, label, subject, type and initial place, and a code field **"Places & transitions"** that accepts YAML or JSON. Saving validates the definition first; an invalid definition is rejected with the list of errors. The grid shows the place and transition counts, the validation result and the apply status (applied, outdated or not applied).

| Action | Scope | Result |
|---|---|---|
| Validate | record | message, or a table of errors |
| Preview (Mermaid) | record | HTML: SVG rendered by `mermaid_render_url` (`img-src` is allowed by the admin CSP; scripts are not), a "Open in Mermaid Live Editor" link, and the Mermaid source |
| Export BPMN | record | BPMN 2.0 XML text, with DI layout for bpmn-js and other modelers |
| Show OpenDXP config | record | the YAML that apply would write |
| Apply / Un-apply | record | writes or removes `<apply_dir>/elevate_dxp_workflow_<name>.yaml` and reminds you to run `cache:clear` |
| Import BPMN | global | params: name (optional) and XML. The XML is mapped, validated and stored |
| Create demo workflow | global | seeds `product_review` |

**Privacy:** the preview image sends the Mermaid source of the workflow (place and transition names) to `mermaid_render_url`. Set it to `null`, or point it to a self-hosted mermaid.ink, to keep the diagram internal.

## BPMN mapping (constrained)

- A `bpmn:task` is a place.
- A `startEvent → task` flow marks the initial place.
- A `task → task` sequence flow is a transition. Flows that share a name are merged into one transition with several `from` places (an and-join).
- Gateways and other elements are ignored.
- Extension attributes use the namespace `http://elevate-dxp/bpmn` (prefix `elevatedxp`): `type`, `subject`, `title`, `color`, `lockEditing`, `guard`, `commentEnabled`, `commentRequired`, `notifyRoles`. Documents exported by the legacy designer, which use the `http://openpimcore/bpmn` namespace, are still accepted.
- Import is hardened: documents larger than 2 MB and any `DOCTYPE` are rejected (XXE guard), and documents are parsed with `LIBXML_NONET`.

## CLI

- `elevate-dxp:workflow:list`: definitions with validation and apply status.
- `elevate-dxp:workflow:demo-seed`
- `elevate-dxp:workflow:apply <name>`: same as the admin action. Run `cache:clear` afterwards.

## Porting notes

- The config root changed from `pimcore.workflows` to `opendxp.workflows`. Supports changed from `Pimcore\Model\DataObject\X` to `OpenDxp\Model\DataObject\X`. `toPimcoreConfig()` is renamed `toOpenDxpConfig()`.
- The applied file changed from `config/local/openpimcore_workflow_<name>.yaml` to `config/local/elevate_dxp_workflow_<name>.yaml`. Delete legacy files after migrating.
- The default storage directory changed from `var/openpimcore/workflows` to `var/elevate-dxp/workflows`. Copy the old YAML files across: the format is unchanged.
- The Studio REST API and the bpmn-js editor are replaced by the admin resource. BPMN export and import stay available for external modelers.
- The validator is stricter. It now checks:
  - the name pattern;
  - the subject class syntax;
  - duplicate places;
  - that at least one transition exists (OpenDXP requires one);
  - that every transition has a `from` place;
  - colours;
  - that `placeMeta` only references existing places.
- New features: un-apply, the apply status, and the `apply` CLI command.

## Tests

```bash
docker compose exec -T php vendor/bin/phpunit -c /var/www/packages/phpunit.xml.dist --filter WorkflowBundle
```

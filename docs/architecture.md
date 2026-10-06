# Architecture

Elevate DXP is one Symfony bundle, `ElevateDxp\ElevateDxpBundle`, made of 14 modules. This page explains how the modules are wired, how the admin UI is generated from PHP schemas, how asynchronous work is dispatched, and how experiments plug into OpenDXP targeting. The last section shows how to extend it.

## Repository layout

```
config/
  opendxp/config.yaml        # registers targeting data providers, conditions, action handler
  opendxp/routing.yaml       # routes of all modules (auto-loaded by OpenDXP)
  services/<key>.yaml        # one service file per module
public/
  js/                        # ExtJS admin layer + edxp-runtime.js (frontend)
  css/elevatedxp.css
src/
  ElevateDxpBundle.php
  DependencyInjection/       # ElevateDxpExtension, root Configuration, Modules registry
  Core/                      # module system, admin framework, installer, audit, messenger
  Experiments/ Insights/ Feed/ Export/ Datahub/ Webhook/ Automation/
  Statistics/ DamMetadata/ Translation/ Portal/ Workflow/ Sso/
templates/                   # Twig (namespace @ElevateDxp)
translations/
tests/
```

Files in `config/opendxp/` are loaded automatically by the OpenDXP kernel for every registered bundle. The application does not need to import them.

## Bundle

`ElevateDxpBundle` extends `AbstractOpenDxpBundle` and implements:

- `OpenDxpBundleAdminClassicInterface`: contributes the admin JS and CSS files (`getJsPaths()`, `getCssPaths()`).
- `DependentBundleInterface`: registers `OpenDxpAdminBundle` and `OpenDxpPersonalizationBundle` as dependent bundles.
- `getInstaller()`: returns the core `Installer` service.

## Module system

### `ModuleInterface`

Every module implements `ElevateDxp\Core\Module\ModuleInterface`:

```php
interface ModuleInterface
{
    public function key(): string;                                   // e.g. "experiments"
    public function configuration(): ConfigurationInterface;         // tree whose root node is key()
    public function prepend(ContainerBuilder $container): void;
    public function load(array $config, ContainerBuilder $container): void;
}
```

`ElevateDxp\DependencyInjection\Modules::all()` lists the modules in a fixed order: core, experiments, insights, feed, export, datahub, webhook, automation, statistics, dam_metadata, translation, portal, workflow, sso.

### One configuration tree

`ElevateDxpExtension` (alias `elevate_dxp`) does three things:

1. `prepend()` calls `prepend()` on every module. Core uses this to configure the Messenger transport (see [Async messaging](#async-messaging)).
2. The root `Configuration` appends each module's tree under `elevate_dxp`, with `addDefaultsIfNotSet()`. The whole tree is validated once.
3. `load()` hands each module its own processed subtree: `$module->load($config[$module->key()], $container)`.

A module's `load()` usually turns its subtree into container parameters named `elevate_dxp_<key>.*` and loads `config/services/<key>.yaml`:

```php
public function load(array $config, ContainerBuilder $container): void
{
    $container->setParameter('elevate_dxp_feed.enabled', $config['enabled']);
    // ...
    (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('feed.yaml');
}
```

All modules are always loaded; their services are registered whatever the configuration. Most modules have an `enabled` flag, but what it switches off differs per module (for example, the feed and datahub public endpoints answer 404, the webhook dispatcher stops sending). In `portal`, `workflow`, `dam_metadata` and `translation` the flag is currently not read at all. [configuration.md](configuration.md) documents the effect per module. To hide a module from editors, do not grant its permission.

## Installer

`bin/console opendxp:bundle:install ElevateDxpBundle` runs `ElevateDxp\Core\Installer\Installer`, a `SettingsStoreAwareInstaller`.

Each module contributes a subclass of `ElevateDxp\Core\Installer\ModuleInstaller`:

```php
abstract class ModuleInstaller
{
    public const TAG = 'elevate_dxp.module_installer';
    public function getPermissions(): array { return []; }   // permission keys
    public function getSchema(): array { return []; }        // idempotent DDL
}
```

Core registers `ModuleInstaller` for autoconfiguration with that tag. The `Installer` receives every tagged installer through `#[AutowireIterator]` and:

- runs every module's DDL (`CREATE TABLE IF NOT EXISTS ...`), so install can be repeated safely;
- inserts the permission keys into `users_permission_definitions` with the category `Elevate DXP` (`ON DUPLICATE KEY UPDATE`);
- marks the bundle as installed in the settings store.

Uninstall removes the permission definitions but keeps every table. Dropping experiment results, delivery logs or visitor data must be a manual decision.

### Tables

| Table | Module | Content |
|---|---|---|
| `edxp_experiment` | experiments | Experiments, variants (JSON), status, schedule |
| `edxp_assignment` | experiments | One row per (visitor, experiment): variant, `forced` flag |
| `edxp_event` | experiments | Exposures, conversions, clicks and custom events |
| `edxp_visitor_profile` | experiments | First-party visitor profile (also read by insights) |
| `edxp_webhook_delivery` | webhook | Delivery log |
| `edxp_portal_collection`, `edxp_portal_collection_item`, `edxp_portal_saved_view` | portal | Collections, items, saved searches |

The other modules store their definitions in configuration or YAML files and create no tables.

### Permissions

| Permission | Module |
|---|---|
| `elevate_dxp_admin` | core (registered; no shipped resource requires it) |
| `elevate_dxp_experiments` | experiments |
| `elevate_dxp_cdp`, `elevate_dxp_data_quality`, `elevate_dxp_copilot` | insights |
| `elevate_dxp_feed` | feed |
| `elevate_dxp_export` | export |
| `elevate_dxp_datahub` | datahub |
| `elevate_dxp_webhook` | webhook |
| `elevate_dxp_automation` | automation |
| `elevate_dxp_statistics` | statistics |
| `elevate_dxp_dam_metadata` | dam_metadata |
| `elevate_dxp_translation` | translation |
| `elevate_dxp_portal` | portal |
| `elevate_dxp_workflow` | workflow |
| `elevate_dxp_sso` | sso |

Some actions also check native OpenDXP permissions (for example `assets`, `asset_metadata`, `reports`). The module pages list them.

`bin/console elevate-dxp:diagnostics` prints the install state, the module keys, the permission keys and every registered admin resource.

## Schema-driven admin

Most modules ship no JavaScript. A module declares a PHP *admin resource*, and a generic ExtJS layer renders it.

### `AdminResourceInterface`

```php
interface AdminResourceInterface
{
    public const TAG = 'elevate_dxp.admin_resource';

    public function getKey(): string;          // URL-safe key, unique
    public function getLabel(): string;        // menu and tab title
    public function getGroup(): string;        // menu group: Marketing, Insights, Content, Data, Integration, Security ...
    public function getIconCls(): string;
    public function getPermission(): string;   // admins always pass
    public function getSchema(): array;

    public function list(array $query): array; // ['data' => [...], 'total' => n]
    public function get(string $id): ?array;
    public function save(array $data): array;
    public function delete(string $id): void;
    public function runAction(string $action, ?string $id, array $params): array;
}
```

Base classes:

- `AbstractAdminResource`: read-only defaults (empty list, `save`/`delete` throw, unknown actions throw), plus `paginate()` for in-memory lists such as configuration-backed resources.
- `AbstractDbalResource`: generic CRUD over one table, driven by the schema fields. Only declared field names are used as SQL identifiers (sort, filter, write), so request input never becomes an identifier. Structured fields (`json`, `keyvalue`, `tags`, `multiselect`) are stored as JSON. Hooks: `beforeSave()`, `decorate()`, `getSearchColumns()`, `getDefaultSort()`.

### Schema

```php
[
    'panel'      => 'crud',            // 'crud' | 'report' | 'custom'
    'idProperty' => 'id',
    'fields'     => [Field::text('name', 'Name', ['required' => true]), ...],
    'filters'    => [...],             // report panels: fields shown above the grid
    'actions'    => [Action::record('run', 'Run'), Action::global('import', 'Import')],
    'canCreate'  => true, 'canEdit' => true, 'canDelete' => true,
    'chart'      => ['x' => 'variant', 'y' => ['conversion_rate']],   // report panels, optional
    'jsClass'    => 'myapp.MyPanel',   // custom panels only
]
```

`Field` builders: `id`, `text`, `textarea`, `code`, `password`, `number`, `bool`, `date`, `datetime`, `select`, `multiselect`, `json`, `keyvalue`, `tags`, `element` (drop target for an element path). Common options: `required`, `readOnly`, `grid`, `width`, `flex`, `help`, `virtual` (not persisted), `default`.

`Action` builders:

- `Action::record()` needs a selected row; `Action::global()` does not.
- Options: `params` (a list of fields shown in a dialog before the action runs), `confirm` (confirmation text), `iconCls`.

An action returns an array that the UI understands. Use the helpers:

| Helper | UI result |
|---|---|
| `Action::message($text, $reload)` | Notification; optionally reloads the grid |
| `Action::table($rows, $title, $columns)` | Grid window (values are HTML-escaped) |
| `Action::text($text, $title)` | Read-only text area (JSON, YAML, XML, ...) |
| `Action::html($html, $title)` | HTML panel. **Not escaped**: escape every dynamic value yourself |
| `Action::url($url, $message)` | Opens the URL in a new tab |

### `ResourceController`

One controller serves every resource. It lives under `/admin`, so the OpenDXP admin firewall and session apply:

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/elevate-dxp/features` | Resources the current user may use |
| GET | `/admin/elevate-dxp/r/{key}/schema` | Schema |
| GET, POST | `/admin/elevate-dxp/r/{key}/list` | `start`, `limit`, `q`, `filters`, `sort` |
| GET | `/admin/elevate-dxp/r/{key}/get?id=` | One record |
| POST | `/admin/elevate-dxp/r/{key}/save` | `{"data": {...}}` |
| POST, DELETE | `/admin/elevate-dxp/r/{key}/delete` | `{"id": "..."}` |
| POST | `/admin/elevate-dxp/r/{key}/action/{action}` | `{"id": "...", "params": {...}}` |

Guards, in order:

1. Unknown key → 404.
2. The user must be an admin or have `getPermission()` → otherwise 403.
3. `save` is refused when both `canCreate` and `canEdit` are false; `delete` when `canDelete` is false.
4. `action` is refused unless the action name is declared in the schema.
5. `InvalidArgumentException`, `DomainException` and `JsonException` become `400 {"success": false, "message": ...}`. Throw them for validation errors.

CSRF: the OpenDXP admin bundle checks the CSRF token on every non-GET request in the admin context (`CsrfProtectionListener`), and the admin's `Ext.Ajax` sends the token. Custom clients must send it too.

Audit: every `save`, `delete` and `action.<name>` is written to the audit log with the user name as actor.

### ExtJS layer

The bundle registers these admin scripts (`ElevateDxpBundle::getJsPaths()`):

| File | Role |
|---|---|
| `elevatedxp.js` | Namespace, URL and JSON request helpers, `elevatedxp.open(feature)` |
| `fields.js` | Maps schema field types to ExtJS form fields and grid columns |
| `action-runner.js` | Toolbar buttons, parameter dialogs, confirmation, result rendering |
| `crud-panel.js` | Grid + form panel (`panel: crud`) |
| `report-panel.js` | Filters, grid and optional chart (`panel: report`) |
| `startup.js` | Builds the "Elevate DXP" main menu in `preMenuBuild` |
| `admin/targeting.js` | Editors for the `edxp_*` targeting conditions and the set-variant action |

The menu is built synchronously. `AdminSettingsListener` listens to `AdminEvents::INDEX_ACTION_SETTINGS` and adds `elevatedxp.features` (already filtered by the user's permissions) to the admin bootstrap settings. Menu groups are the resources' `getGroup()` values.

For `panel: custom`, the layer instantiates the ExtJS class named in `jsClass`. Load that class through your own bundle's `getJsPaths()`.

## Audit

`ElevateDxp\Core\Contract\AuditLoggerInterface` receives `AuditEvent(action, actor, status, context)`. The default implementation writes to the OpenDXP Application Logger (*Tools → Application Logger*), component `elevate-dxp.<first segment of action>`. Statuses that contain `fail`, `error`, `denied` or `forbidden` are logged at level `error`.

- Context keys named `api_key`, `apikey`, `password`, `secret`, `token`, `authorization` or `client_secret` are replaced by `***`, recursively (`SecretMasker`).
- Audit never breaks the audited operation: exceptions inside the logger are swallowed.
- `elevate_dxp.core.audit.enabled: false` turns it off.
- `PsrAuditLogger` is an alternative that writes to the PSR logger (Monolog). Alias it if you prefer:

```yaml
# config/services.yaml
services:
    ElevateDxp\Core\Contract\AuditLoggerInterface: '@ElevateDxp\Core\Audit\PsrAuditLogger'
```

## Async messaging

Core prepends this Messenger configuration:

```yaml
framework:
    messenger:
        transports:
            elevate_dxp: '%env(ELEVATE_DXP_MESSENGER_DSN)%'     # default: sync://
        routing:
            ElevateDxp\Core\Messenger\AsyncMessageInterface: elevate_dxp
```

Any message class that implements the marker interface `AsyncMessageInterface` goes to that transport. Current messages:

| Message | Handler | Purpose |
|---|---|---|
| `Experiments\Message\TrackedEvent` | `TrackedEventHandler` | Forward an event to Matomo or PostHog |
| `Webhook\Message\SendWebhookMessage` | `SendWebhookHandler` | Deliver one webhook |

With the default `sync://`, messages are handled inline during the request. Set a real DSN in production and run `bin/console messenger:consume elevate_dxp`. Retries follow the transport's retry strategy.

## How experiments plug into OpenDXP targeting

The personalization bundle resolves the visitor on each frontend request: it loads data providers, matches targeting rules, runs rule actions and assigns target groups. Elevate DXP hooks into that pipeline at documented extension points only.

### Registration

`config/opendxp/config.yaml` adds the extensions to the personalization bundle's configuration:

```yaml
opendxp_personalization:
    targeting:
        data_providers:
            edxp_experiments: ElevateDxp\Experiments\Targeting\DataProvider\ExperimentsDataProvider
            edxp_profile: ElevateDxp\Experiments\Targeting\DataProvider\VisitorProfileDataProvider
        conditions:
            edxp_experiment_variant: ElevateDxp\Experiments\Targeting\Condition\ExperimentVariant
            edxp_query_param: ElevateDxp\Experiments\Targeting\Condition\QueryParam
            edxp_utm: ElevateDxp\Experiments\Targeting\Condition\Utm
            edxp_cookie: ElevateDxp\Experiments\Targeting\Condition\Cookie
            edxp_time_window: ElevateDxp\Experiments\Targeting\Condition\TimeWindow
            edxp_returning_visitor: ElevateDxp\Experiments\Targeting\Condition\ReturningVisitor
        action_handlers:
            edxp_set_variant: ElevateDxp\Experiments\Targeting\ActionHandler\SetVariant
```

The data providers and the action handler are public services. Conditions are built from their saved rule configuration with `fromConfig()`.

### Request flow

```
kernel.request
  └─ personalization TargetingListener
       ├─ data providers (edxp_profile: sessions, UTM; edxp_experiments: assignments)
       ├─ rules → conditions (built-in + edxp_*) → actions (assign_target_group, edxp_set_variant, ...)
       └─ TargetingEvents::POST_RESOLVE
            └─ Elevate TargetingSubscriber (priority -10)
                 └─ ExperimentRuntime::assignAll(visitorInfo)
                      ├─ for each running experiment: schedule, URL scope, audience target group
                      ├─ sticky assignment (edxp_assignment), exposure event on first assignment
                      └─ assign each variant's hidden target group to the visitor (weight 1000)
controller / Twig
  └─ edxp_variant(), edxp_variant_payload(), per-target-group editables render the variant
kernel.response
  ├─ personalization TargetingListener (-115)
  └─ Elevate ResponseSubscriber (-120)
       ├─ set the signed edxp_vid cookie if newly issued
       ├─ Cache-Control: private + X-Edxp-Experiments when the visitor is in an experiment
       ├─ update edxp_visitor_profile
       └─ inject window.edxpConfig + edxp-runtime.js before </head>
```

### Hidden variant target groups

The *Create variant target groups* action (or `elevate-dxp:experiments:demo-seed`) creates one target group per variant, named `exp:<experiment>:<variant>`, and stores its id in the variant. When a visitor is assigned to a variant, `ExperimentRuntime` assigns that target group to the `VisitorInfo` with a weight of 1000, so it wins over other assigned groups. The stock per-target-group content of pages and snippets then renders that variant. No custom editable is needed.

Groups whose name starts with `exp:` are hidden from the experiment's "Audience" selector.

### Without targeting

`ExperimentRuntime` also works without a `VisitorInfo`. When targeting is disabled, the Twig helpers trigger the assignment on first use. Experiments with an audience target group are then skipped, and no variant group is assigned.

Details: [experiments.md](experiments.md).

## Extending

### Add an admin resource in your application

Any autoconfigured service that implements `AdminResourceInterface` is tagged automatically and appears in the Elevate DXP menu. No JavaScript is needed.

```php
<?php
// src/Admin/NewsletterSignupsResource.php

declare(strict_types=1);

namespace App\Admin;

use Doctrine\DBAL\Connection;
use ElevateDxp\Core\Admin\AbstractDbalResource;
use ElevateDxp\Core\Admin\Action;
use ElevateDxp\Core\Admin\Field;

final class NewsletterSignupsResource extends AbstractDbalResource
{
    public function __construct(Connection $db)
    {
        parent::__construct($db);
    }

    public function getKey(): string { return 'app-signups'; }
    public function getLabel(): string { return 'Newsletter sign-ups'; }
    public function getGroup(): string { return 'Marketing'; }
    public function getIconCls(): string { return 'opendxp_icon_settings'; }
    public function getPermission(): string { return 'app_signups'; }

    protected function getTable(): string { return 'app_signup'; }
    protected function getSearchColumns(): array { return ['email', 'source']; }

    public function getSchema(): array
    {
        return [
            'panel' => 'crud',
            'fields' => [
                Field::id(),
                Field::text('email', 'E-mail', ['required' => true]),
                Field::select('source', 'Source', ['web' => 'Website', 'fair' => 'Trade fair']),
                Field::bool('confirmed', 'Confirmed'),
                Field::tags('tags', 'Tags'),
            ],
            'actions' => [
                Action::global('stats', 'Statistics'),
            ],
        ];
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if (!filter_var($data['email'] ?? '', \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid e-mail address.');
        }

        return $data;
    }

    public function runAction(string $action, ?string $id, array $params): array
    {
        if ($action === 'stats') {
            return Action::table($this->db->fetchAllAssociative(
                'SELECT source, COUNT(*) AS signups FROM app_signup GROUP BY source',
            ), 'Sign-ups by source');
        }

        return parent::runAction($action, $id, $params);
    }
}
```

With the default `config/services.yaml` of the OpenDXP skeleton (`autowire` and `autoconfigure` on `App\`), nothing else is needed. Then:

1. Create the table (`app_signup` with the columns `id`, `email`, `source`, `confirmed`, `tags`) in a Doctrine migration of your application.
2. Register the permission in the same migration, or once from code:

   ```php
   \OpenDxp\Model\User\Permission\Definition::create('app_signups')->setCategory('Elevate DXP')->save();
   ```

3. Clear the cache and reload the admin. The resource appears under *Elevate DXP → Marketing* for admins and for users with the permission.

For a resource that is not backed by one table, extend `AbstractAdminResource` and implement `list()` (and `get()`, `save()`, `delete()` as needed). Use `$this->paginate($rows, $query)` for in-memory data.

### Add a targeting condition in your application

Conditions use the personalization bundle's own API. Register the class under `opendxp_personalization.targeting.conditions` in your configuration and add an ExtJS editor with `opendxp.bundle.personalization.settings.conditions.register(...)`, as `public/js/admin/targeting.js` does. To read experiment assignments, implement `DataProviderDependentInterface` and depend on `edxp_experiments`:

```php
public function getDataProviderKeys(): array
{
    return [\ElevateDxp\Experiments\Targeting\DataProvider\ExperimentsDataProvider::PROVIDER_KEY];
}

public function match(VisitorInfo $visitorInfo): bool
{
    $assignments = (array) $visitorInfo->get('edxp_experiments', []); // ['hero' => 'B', ...]
    return ($assignments['hero'] ?? null) === 'B';
}
```

### Add a module to the bundle (contributors)

1. Create `src/Foo/DependencyInjection/Configuration.php` whose `TreeBuilder` root is named `foo`.
2. Create `src/Foo/DependencyInjection/FooModule.php`:

   ```php
   final class FooModule implements ModuleInterface
   {
       public function key(): string { return 'foo'; }
       public function configuration(): Configuration { return new Configuration(); }
       public function prepend(ContainerBuilder $container): void {}

       public function load(array $config, ContainerBuilder $container): void
       {
           $container->setParameter('elevate_dxp_foo.enabled', $config['enabled']);
           (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config/services')))->load('foo.yaml');
       }
   }
   ```

3. Add `new FooModule()` to `Modules::all()`.
4. Create `config/services/foo.yaml` (autowire, autoconfigure, resource `../../src/Foo/*`, excluding `DependencyInjection/`).
5. Add `src/Foo/Installer/FooInstaller.php` extending `ModuleInstaller` for permissions and idempotent DDL. Use the `edxp_` table prefix. Ship later schema changes as Doctrine migrations.
6. Add admin resources under `src/Foo/Admin/`. Public routes go into `src/Foo/Controller/` and a new entry in `config/opendxp/routing.yaml`; admin routes go under the `/admin` prefix.
7. Add tests under `tests/Foo/`, a page in `docs/modules/foo.md` and a CHANGELOG entry.

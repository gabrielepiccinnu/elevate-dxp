# Elevate DXP

A single bundle for [OpenDXP](https://github.com/open-dxp/opendxp) 1.4 that adds A/B testing, conversion tracking, visitor insights, product feeds, a REST datahub, webhooks, an asset portal, a workflow designer and related tools to the classic (ExtJS) admin.

- Package: `elevate-dxp/elevate-bundle`
- Bundle class: `ElevateDxp\ElevateDxpBundle`
- Namespace: `ElevateDxp\`
- License: GPL-3.0-or-later

## What it is, and what it is not

Elevate DXP is a community extension. It builds on the public extension points of OpenDXP core, `open-dxp/admin-bundle` and the free `open-dxp/personalization-bundle`. It does not patch or replace them.

It is **not** affiliated with, endorsed by or supported by the OpenDXP core team or Pimcore GmbH. "Pimcore" is a trademark of Pimcore GmbH. For anything that concerns OpenDXP itself (installation, upgrades, core features), the [OpenDXP documentation](https://github.com/open-dxp/opendxp/tree/1.x/doc) is authoritative.

## OpenDXP core vs Elevate DXP

OpenDXP core and the free personalization bundle already provide rule-based personalization. Elevate DXP does not duplicate it. It adds experimentation and measurement on top, plus integration and content tooling.

| Capability | OpenDXP core + `open-dxp/personalization-bundle` | Added by Elevate DXP |
|---|---|---|
| Targeting rules, target groups, per-target-group document content | Yes | Uses them as-is |
| Built-in targeting conditions (browser, country, URL, visited pages, …) | Yes | 6 extra conditions: experiment variant, query parameter, UTM campaign (first/last touch), cookie, weekday/time window, returning visitor |
| Rule actions (assign target group, redirect, code snippet, …) | Yes | "Set experiment variant" action |
| A/B and multivariate experiments with sticky, deterministic assignment | No | Yes (Twig helpers and per-variant target groups) |
| Conversion tracking endpoint and JS runtime | No | Yes (`/_edxp/track`, `window.edxp.track()`, `data-edxp-track`) |
| Statistical reports (conversion rate, uplift, p-value, sample size) | No | Yes (two-proportion z-test) |
| First-party visitor profiles and rule-based segments ("CDP-lite") | No | Yes, with GDPR erasure |
| Forwarding events to Matomo or PostHog | No | Yes (async) |
| Data completeness reports for data object classes | No | Yes |
| AI copilot for editors (Anthropic Claude API) | No | Yes (opt-in, needs an API key) |
| Product feeds (generic CSV, Google Merchant) with tokenized public URLs | No | Yes |
| Scheduled-style exports to file, asset or HTTP | No | Yes (CSV, JSON, XML) |
| REST endpoints for data objects and assets | No (GraphQL via the separate `open-dxp/data-hub-bundle`) | Yes, API-key protected, read-only |
| Signed outbound webhooks on element events, delivery log, retry | No | Yes |
| n8n workflow blueprints | No | Yes |
| Read-only SQL reports with charts | Custom Reports (native) | YAML reports, admin charts, seeding into native Custom Reports |
| Asset metadata | Predefined metadata (native) | Typed schemas, validation, bulk apply to folders |
| Machine translation of XLIFF | XLIFF export/import (native) | Pseudo and LibreTranslate providers, XLIFF fill |
| Asset portal (search, cart, ZIP, collections, guest share links) | No | Yes |
| Workflows (Symfony Workflow engine) | Yes, configured in YAML | Designer: validation, Mermaid preview, BPMN import/export, apply to config |
| SSO | No | Claim-to-role mapping and user provisioning (you supply the OIDC flow) |

## Requirements

| Component | Version |
|---|---|
| PHP | 8.3, 8.4 or 8.5 (`~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0`), ext-dom |
| OpenDXP | `open-dxp/opendxp` ^1.4 |
| Admin UI | `open-dxp/admin-bundle` ^1.4 (classic ExtJS admin) |
| Personalization | `open-dxp/personalization-bundle` ^1.0, which requires `open-dxp/newsletter-bundle` ^1.0 |
| Database | MariaDB or MySQL, as supported by OpenDXP (MariaDB >= 10.3, MySQL >= 8.0) |
| Symfony | 7.4 (`symfony/messenger`, `symfony/rate-limiter`, `symfony/http-client`) |

Optional: `open-dxp/data-hub-bundle` (GraphQL API, main menu **Datahub**). It is not installed by Elevate DXP; see [step 7](#7-optional-graphql-data-hub).

## Installation

### 1. Require the package

```bash
composer require elevate-dxp/elevate-bundle
```

### 2. Register the bundles

Elevate DXP depends on the personalization bundle, which depends on the newsletter bundle. Register them in this order in `config/bundles.php`:

```php
return [
    // ... existing OpenDXP bundles
    OpenDxp\Bundle\NewsletterBundle\OpenDxpNewsletterBundle::class => ['all' => true],
    OpenDxp\Bundle\PersonalizationBundle\OpenDxpPersonalizationBundle::class => ['all' => true],
    ElevateDxp\ElevateDxpBundle::class => ['all' => true],
];
```

### 3. Install

```bash
bin/console opendxp:bundle:install OpenDxpNewsletterBundle      # skip if already installed
bin/console opendxp:bundle:install OpenDxpPersonalizationBundle  # skip if already installed
bin/console opendxp:bundle:install ElevateDxpBundle
bin/console assets:install public
bin/console cache:clear
```

`opendxp:bundle:install ElevateDxpBundle` creates every `edxp_*` table with idempotent DDL. It also registers the admin permissions in the category "Elevate DXP". Grant them to roles in *Settings → Users & Roles*. Admin users always have access. On later deployments run `bin/console elevate-dxp:setup`: it is idempotent and adds tables and permissions introduced by newer versions.

Check the result:

```bash
bin/console elevate-dxp:diagnostics
```

### 4. Enable targeting

Targeting is disabled by default in the personalization bundle. Experiments that use variant target groups, the extra conditions and the "Set experiment variant" action need it:

```yaml
# config/config.yaml
opendxp_personalization:
    targeting:
        enabled: true
```

The Twig helpers and conversion tracking also work without targeting. In that case, experiments cannot be scoped to a target group, and variant target groups are not assigned.

### 5. Asynchronous transport (production)

Webhook deliveries and analytics forwarding go through the Symfony Messenger transport `elevate_dxp`. Its DSN comes from `ELEVATE_DXP_MESSENGER_DSN`, which defaults to `sync://` (handled inline, during the request). In production, use a queue and run a worker:

```dotenv
# .env.local
ELEVATE_DXP_MESSENGER_DSN=doctrine://default?queue_name=elevate_dxp
```

```bash
bin/console messenger:consume elevate_dxp --time-limit=3600
```

Run the worker under a process manager (systemd, supervisord) like the OpenDXP core workers.

### 6. Web server

All Elevate DXP routes are served by PHP. Public routes live under `/elevate-dxp/` and `/_edxp/track`; admin routes under `/admin/elevate-dxp/`.

The public feed URLs end in a file extension: `/elevate-dxp/feed/{name}.csv` and `/elevate-dxp/feed/{name}.xml`. nginx setups usually have a regex location that serves known extensions as static files and never passes them to PHP. The [OpenDXP nginx example](https://github.com/open-dxp/opendxp/blob/1.x/doc/23_Installation_and_Upgrade/03_System_Setup_and_Hosting/02_Nginx_Configuration.md) does not list `csv` or `xml`, but many projects add them. If yours does, exclude the `/elevate-dxp/` prefix from that location:

```nginx
location ~* ^(?!/admin|/asset/webdav|/elevate-dxp/)(.+?)\.((?:css|js)(?:\.map)?|jpe?g|gif|png|svgz?|eps|exe|gz|zip|mp\d|m4a|ogg|ogv|webp|webm|pdf|docx?|xlsx?|pptx?|csv|xml)$ {
    # ... unchanged
}
```

Only the `(?!...)` lookahead changes; keep the rest of the block as it is. Apache with the skeleton `.htaccess` needs no change.

### 7. Optional: GraphQL Data Hub

Elevate DXP ships **REST** endpoints (*Elevate DXP → Integration → REST endpoints*). The **GraphQL** API and the **Datahub** main menu belong to the official, free `open-dxp/data-hub-bundle`, which is a separate package:

```bash
composer require open-dxp/data-hub-bundle
```

```php
// config/bundles.php
OpenDxp\Bundle\DataHubBundle\OpenDxpDataHubBundle::class => ['all' => true],
```

```bash
bin/console opendxp:bundle:install OpenDxpDataHubBundle
bin/console cache:clear
```

Log in again: the **Datahub** menu appears and its GraphQL configurations are also listed in *Elevate DXP → Integration → GraphQL configurations* (with endpoint and API-key status). Without the bundle that panel shows how to install it, and its *Data Hub status* action explains the steps.

## 5-minute quickstart

### 1. Seed a demo experiment

```bash
bin/console elevate-dxp:experiments:demo-seed
```

The command creates:

- the target group "VIP visitors" and a targeting rule that assigns it when the URL contains `?vip=1`, using the `edxp_query_param` condition;
- a running experiment `hero` with variants `A` and `B` (50/50), goal event `signup`, and a per-variant JSON payload;
- one hidden target group per variant, `exp:hero:A` and `exp:hero:B`.

### 2. Render the variant in a template

```twig
{# templates/default/default.html.twig #}
{% set variant = edxp_variant('hero') %}   {# 'A', 'B' or null when the visitor is not enrolled #}

<h1>{{ edxp_variant_payload('hero').headline|default('Welcome') }}</h1>

{% if edxp_in_variant('hero', 'B') %}
    <p class="badge">First-order discount applied at checkout.</p>
{% endif %}

{# Target groups assigned by OpenDXP targeting, e.g. ["VIP visitors", "exp:hero:B"] #}
{% if 'VIP visitors' in edxp_target_groups() %}
    <p>Welcome back, VIP.</p>
{% endif %}

<button data-edxp-track="signup">Sign up</button>
```

Instead of Twig conditions, editors can also fill the content of each variant with the standard per-target-group editing of pages and snippets. Select the target group `exp:hero:B` in the document editor.

### 3. Track conversions

The bundle injects a small runtime (`/bundles/elevatedxp/js/edxp-runtime.js`) before `</head>` of frontend HTML responses. Tracking options:

```html
<button data-edxp-track="signup">Sign up</button>                      <!-- tracked on click -->
<a href="/pricing" data-edxp-track="pricing_click" data-edxp-value="1">Pricing</a>
<form data-edxp-track-submit="lead"> ... </form>                       <!-- tracked on submit -->
<script>
  window.edxp.track('purchase', {value: 49.90, meta: {plan: 'pro'}});
  window.edxp.variant('hero'); // 'A' | 'B' | null
</script>
```

An event whose name equals the goal event of a running experiment is stored as a conversion.

### 4. Read the results

Open *Elevate DXP → Marketing → Experiment Results* in the admin, or run:

```bash
bin/console elevate-dxp:experiments:report hero
```

The full guide is in [docs/experiments.md](docs/experiments.md).

## Modules

All modules ship in the one bundle. Each module has a configuration subtree under `elevate_dxp:` and its own permission.

| Module | Config key | What it does | Docs |
|---|---|---|---|
| Core | `core` | Module system, schema-driven admin UI, audit log, async transport | [architecture.md](docs/architecture.md) |
| Experiments | `experiments` | A/B tests, conversion tracking, reports, 6 targeting conditions, set-variant action, Matomo/PostHog | [experiments.md](docs/experiments.md) |
| Insights | `insights` | Visitor profiles and segments, data quality, Claude copilot | [insights.md](docs/insights.md) |
| Feed | `feed` | Product feeds (generic CSV, Google Merchant), tokenized public URLs | [modules/feed.md](docs/modules/feed.md) |
| Export | `export` | CSV/JSON/XML exports to file, asset or HTTP | [modules/export.md](docs/modules/export.md) |
| Datahub | `datahub` | API-key protected REST endpoints for data objects and assets | [modules/datahub.md](docs/modules/datahub.md) |
| Webhook | `webhook` | HMAC-signed webhooks on element events, delivery log, retry | [modules/webhook.md](docs/modules/webhook.md) |
| Automation | `automation` | n8n blueprints for webhook subscriptions | [modules/automation.md](docs/modules/automation.md) |
| Statistics | `statistics` | Read-only SQL reports with charts | [modules/statistics.md](docs/modules/statistics.md) |
| DAM metadata | `dam_metadata` | Typed asset metadata schemas, bulk apply | [modules/dam_metadata.md](docs/modules/dam_metadata.md) |
| Translation | `translation` | XLIFF machine translation (Pseudo, LibreTranslate) | [modules/translation.md](docs/modules/translation.md) |
| Portal | `portal` | Asset search, cart, ZIP, collections, guest share links | [modules/portal.md](docs/modules/portal.md) |
| Workflow | `workflow` | Workflow designer for the OpenDXP workflow engine | [modules/workflow.md](docs/modules/workflow.md) |
| SSO | `sso` | IdP claim-to-role mapping and user provisioning | [modules/sso.md](docs/modules/sso.md) |

Further reading:

- [Configuration reference](docs/configuration.md)
- [Architecture and extending](docs/architecture.md)
- [Security and privacy](docs/security-and-privacy.md)
- [Migrating an existing Pimcore site](docs/migration-from-pimcore.md)

## Configuration

All settings live under one root key, `elevate_dxp:`. Every module works with its defaults. Print the full tree with defaults and descriptions:

```bash
bin/console config:dump-reference elevate_dxp
bin/console debug:config elevate_dxp      # effective values
```

Example:

```yaml
# config/packages/elevate_dxp.yaml
elevate_dxp:
    experiments:
        visitor:
            consent_cookie: cookie_consent   # track nobody until your CMP sets this cookie
        analytics:
            adapter: matomo
            matomo: { url: 'https://matomo.example.com', site_id: '3' }
    datahub:
        api_key: '%env(ELEVATE_DXP_DATAHUB_API_KEY)%'
```

See [docs/configuration.md](docs/configuration.md) for every option.

## Compatibility

| Elevate DXP | OpenDXP | admin-bundle | personalization-bundle | PHP | Symfony |
|---|---|---|---|---|---|
| 1.0.x (in development) | ^1.4 | ^1.4 | ^1.0 | 8.3 – 8.5 | 7.4 |

Pimcore is not supported. To move a Pimcore 11 site to OpenDXP first, see [docs/migration-from-pimcore.md](docs/migration-from-pimcore.md).

## Contributing and security

- [CONTRIBUTING.md](CONTRIBUTING.md): development setup, tests, coding conventions.
- [SECURITY.md](SECURITY.md): report vulnerabilities privately.
- [CHANGELOG.md](CHANGELOG.md)

## License

GPL-3.0-or-later. See [LICENSE](LICENSE). OpenDXP is licensed under GPLv3.

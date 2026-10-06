# Configuration reference

All settings live under one root key, `elevate_dxp:`, with one subtree per module. Every option has a default, so an empty configuration is valid.

```bash
bin/console config:dump-reference elevate_dxp      # tree, defaults, descriptions
bin/console debug:config elevate_dxp               # effective values in this environment
```

Put your settings in any file under `config/packages/` (for example `config/packages/elevate_dxp.yaml`) or in `config/config.yaml`. Use `%env(...)%` for every secret.

## General rules

- **Map keys are kept as written.** Names you define (feeds, export jobs, datahub endpoints and their `fields`, statistics reports, webhook subscriptions, DAM schemas, SSO providers and `role_mapping` keys, portal `object_fields` classes) keep hyphens: `google-shopping` stays `google-shopping`, and an SSO group `dxp-admins` matches the IdP group exactly.
- **`enabled: false` hides a module from editors.** Its admin resources disappear from the *Elevate DXP* menu and the admin API answers 404 for them. Services stay registered, so console commands keep working; the table lists the additional runtime effect per module. Permissions remain the way to give access to some editors only.

| Module | Effect of `enabled: false` |
|---|---|
| `experiments` | No assignment, Twig helpers return `null`/empty, no cookie, no profile update, no runtime injection |
| `feed` | Exports refused; public feed URLs answer 404 |
| `export` | Runs refused (preview still works) |
| `datahub` | Public API answers 404 `disabled` (the admin *Try request* action still works) |
| `webhook` | No events dispatched |
| `automation` | Admin list empty; blueprint generation refused |
| `statistics` | Per-report panels not registered (catalogue, CLI and seeding still work) |
| `sso` | Every SSO login denied (default: `false`) |
| `portal`, `workflow`, `dam_metadata`, `translation` | Admin resources hidden (no further runtime effect) |

## Environment variables

| Variable | Used by | Default |
|---|---|---|
| `ELEVATE_DXP_MESSENGER_DSN` | Messenger transport `elevate_dxp` (webhooks, analytics forwarding) | `sync://` |
| `ANTHROPIC_API_KEY` | `insights.copilot.api_key` default | empty (copilot disabled) |

Other variables are only read if you reference them in your configuration, for example `ELEVATE_DXP_DATAHUB_API_KEY`.

## core

```yaml
elevate_dxp:
    core:
        audit:
            enabled: true
        security:
            default_policy: deny
            allowed_actions: []
```

| Option | Default | Description |
|---|---|---|
| `audit.enabled` | `true` | Write audit events (admin saves, deletes and actions; feed, datahub, export, webhook, portal and DAM operations) to the Application Logger |
| `security.default_policy` | `deny` | `deny` or `allow`. Policy of the `AccessPolicyInterface` service (`DenyByDefaultAccessPolicy`) |
| `security.allowed_actions` | `[]` | Action names the access policy allows when the policy is `deny` |

The access policy service is available to extensions (`ElevateDxp\Core\Contract\AccessPolicyInterface`). The shipped modules authorise through OpenDXP user permissions and do not consult it.

## experiments

See [experiments.md](experiments.md).

```yaml
elevate_dxp:
    experiments:
        enabled: true
        visitor:
            cookie_name: edxp_vid
            cookie_ttl_days: 365
            secret: '%kernel.secret%'
            consent_cookie: null
            excluded_paths: ['^/admin', '^/_', '^/bundles/', '^/elevate-dxp/']
        profile:
            enabled: true
        runtime:
            inject: true
        tracking:
            enabled: true
            allowed_events: []
            max_payload_bytes: 4096
            rate_limit_per_minute: 60
        analytics:
            adapter: none
            matomo:
                url: ''
                site_id: '1'
                token: ''
            posthog:
                host: 'https://eu.i.posthog.com'
                api_key: ''
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | Master switch for assignment, cookie, profile and runtime injection |
| `visitor.cookie_name` | `edxp_vid` | Name of the signed visitor cookie |
| `visitor.cookie_ttl_days` | `365` | Cookie lifetime (min 1) |
| `visitor.secret` | `%kernel.secret%` | HMAC key for the cookie signature. Must not be empty. Changing it invalidates all visitor ids |
| `visitor.consent_cookie` | `null` | If set, nothing is issued, assigned or tracked unless the request has this cookie |
| `visitor.excluded_paths` | see above | Regular expressions (without delimiters) matched against the path. Matching requests are not trackable |
| `profile.enabled` | `true` | Maintain `edxp_visitor_profile` |
| `runtime.inject` | `true` | Inject `window.edxpConfig` and `edxp-runtime.js` before `</head>` |
| `tracking.enabled` | `true` | Enable `POST /_edxp/track` |
| `tracking.allowed_events` | `[]` | Allow-list of event names. Empty = any name matching `[a-z0-9_.:-]{1,64}` |
| `tracking.max_payload_bytes` | `4096` | Maximum request body size |
| `tracking.rate_limit_per_minute` | `60` | Requests per visitor per minute (sliding window) |
| `analytics.adapter` | `none` | `none`, `matomo` or `posthog` |
| `analytics.matomo.url` | `''` | Matomo base URL. Empty = nothing sent |
| `analytics.matomo.site_id` | `'1'` | Matomo site id |
| `analytics.matomo.token` | `''` | Optional `token_auth`; enables sending the original event time |
| `analytics.posthog.host` | `https://eu.i.posthog.com` | PostHog ingestion host |
| `analytics.posthog.api_key` | `''` | Project API key. Empty = nothing sent |

## insights

See [insights.md](insights.md).

```yaml
elevate_dxp:
    insights:
        data_quality:
            sample_limit: 500
            profiles:
                Product: [sku, name, price, image]
        copilot:
            api_key: '%env(string:default::ANTHROPIC_API_KEY)%'
            model: claude-opus-5-5
            max_tokens: 1024
```

| Option | Default | Description |
|---|---|---|
| `data_quality.sample_limit` | `500` | Objects read per report (min 1) |
| `data_quality.profiles` | `{}` | Data object class → list of required fields |
| `copilot.api_key` | `%env(string:default::ANTHROPIC_API_KEY)%` | Anthropic API key. Empty = copilot disabled |
| `copilot.model` | `claude-opus-5-5` | Model id sent to the Messages API |
| `copilot.max_tokens` | `1024` | Maximum answer length (min 1) |

## feed

See [modules/feed.md](modules/feed.md).

```yaml
elevate_dxp:
    feed:
        enabled: true
        chunk_size: 100
        feeds:
            google_shopping:
                source: { type: data_object, class: Product }
                template: google_merchant
                currency: EUR
                link_pattern: 'https://www.example.com/p/{id}'
                mappings: { id: sku, title: name, description: description, image_link: imageUrl, price: price, availability: availability }
                static: { condition: new, brand: ACME }
                channel: { title: Shop, link: 'https://www.example.com', description: Products }
                target: { type: local, path: feeds/google.xml }
                token: '%env(FEED_GOOGLE_TOKEN)%'
                cache_ttl: 300
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | See table above |
| `chunk_size` | `100` | Listing page size (min 1) |
| `feeds.<name>.source.type` | required | `data_object` or `asset` |
| `feeds.<name>.source.class` | `null` | Data object class |
| `feeds.<name>.template` | required | `generic_csv` or `google_merchant` |
| `feeds.<name>.currency` | `EUR` | Appended to prices |
| `feeds.<name>.link_pattern` | `null` | Link builder with `{field}` placeholders |
| `feeds.<name>.mappings` | `{}` | Output field → source field |
| `feeds.<name>.static` | `{}` | Values for empty or missing fields |
| `feeds.<name>.channel.title` / `.link` / `.description` | `Product feed` / `https://example.com` / `Generated by Elevate DXP` | RSS channel (Google Merchant) |
| `feeds.<name>.target.type` | `local` | `local`, `asset` or `http` |
| `feeds.<name>.target.path` | `null` | Path or URL; default `<name>.<ext>` |
| `feeds.<name>.token` | `null` | Enables the public URL; min 16 characters |
| `feeds.<name>.cache_ttl` | `300` | Seconds the public rendering is cached (0 = off) |

## export

See [modules/export.md](modules/export.md).

```yaml
elevate_dxp:
    export:
        enabled: true
        base_path: var/elevate-dxp/exports
        chunk_size: 100
        lock_dir: null
        jobs:
            products_csv:
                description: Published products
                source:
                    type: data_object
                    class: Product
                    fields: { id: id, sku: sku, name: name, modified: modificationDate }
                format: csv
                target: { type: local, path: products.csv }
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | See table above |
| `base_path` | `var/elevate-dxp/exports` | Allow-listed directory for `local` targets (relative to the project dir) |
| `chunk_size` | `100` | Listing page size (min 1) |
| `lock_dir` | `null` | Lock file directory (default: system temp dir) |
| `jobs.<name>.description` | `null` | Free text |
| `jobs.<name>.source.type` | required | `data_object` or `asset` |
| `jobs.<name>.source.class` | `null` | Data object class |
| `jobs.<name>.source.fields` | `{}` | Output column → getter |
| `jobs.<name>.format` | required | `csv`, `json` or `xml` |
| `jobs.<name>.target.type` | required | `local`, `asset` or `http` |
| `jobs.<name>.target.path` | required | File under `base_path`, asset path, or http(s) URL |

## datahub

See [modules/datahub.md](modules/datahub.md).

```yaml
elevate_dxp:
    datahub:
        enabled: true
        api_key_header: X-Elevate-Dxp-Api-Key
        api_key: '%env(ELEVATE_DXP_DATAHUB_API_KEY)%'
        max_limit: 100
        endpoints:
            products:
                type: data_object
                class: Product
                fields: { id: id, sku: sku, name: name, modified: modificationDate }
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | See table above |
| `api_key_header` | `X-Elevate-Dxp-Api-Key` | Request header carrying the key |
| `api_key` | `''` | Static API key. Empty = every request denied |
| `max_limit` | `100` | Maximum page size (min 1) |
| `endpoints.<name>.type` | required | `data_object` or `asset` |
| `endpoints.<name>.class` | `null` | Data object class |
| `endpoints.<name>.path` | `null` | Informational only; served at `/elevate-dxp/api/<name>` |
| `endpoints.<name>.fields` | `{}` | Output key → getter |

## webhook

See [modules/webhook.md](modules/webhook.md).

```yaml
elevate_dxp:
    webhook:
        enabled: true
        sink_enabled: false
        sink_secret: ''
        timeout: 5
        subscriptions:
            erp:
                active: true
                url: 'https://erp.example.com/hooks/dxp'
                secret: '%env(WEBHOOK_ERP_SECRET)%'
                events: [object.add, object.update, object.delete]
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | See table above |
| `sink_enabled` | `false` | Debug receiver at `POST /elevate-dxp/webhook-sink`. Never enable in production |
| `sink_secret` | `''` | If set, the sink verifies the signature |
| `timeout` | `5` | HTTP timeout in seconds (1–60) |
| `subscriptions.<name>.active` | `true` | |
| `subscriptions.<name>.url` | required | http(s) endpoint |
| `subscriptions.<name>.secret` | `''` | HMAC-SHA256 secret. Empty = unsigned |
| `subscriptions.<name>.events` | `[]` | `object.add`, `object.update`, `object.delete`, `asset.add`, `asset.update`, `asset.delete`, `document.add`, `document.update`, `document.delete`. Unknown names fail at container build |

## automation

See [modules/automation.md](modules/automation.md).

```yaml
elevate_dxp:
    automation:
        enabled: true
```

## statistics

See [modules/statistics.md](modules/statistics.md).

```yaml
elevate_dxp:
    statistics:
        enabled: true
        max_rows: 1000
        report_panels: true
        reports:
            assets_by_type:
                label: 'Assets by type'
                sql: 'SELECT type, COUNT(*) AS total FROM assets GROUP BY type'
                chart: bar
                x: type
                y: total
                columns: []
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | See table above |
| `max_rows` | `1000` | Maximum rows fetched from the database and shown per run (min 1); `SELECT` reports are wrapped with a `LIMIT` |
| `report_panels` | `true` | One admin report panel per report |
| `reports.<name>.label` | `''` | |
| `reports.<name>.sql` | required | A single read-only `SELECT`/`WITH` statement |
| `reports.<name>.chart` | `bar` | `bar`, `line`, `pie` or `none` |
| `reports.<name>.x` / `.y` | `null` | Label column / numeric column |
| `reports.<name>.columns` | `[]` | Column list; derived from the query when empty |

## dam_metadata

See [modules/dam_metadata.md](modules/dam_metadata.md).

```yaml
elevate_dxp:
    dam_metadata:
        enabled: true
        batch_size: 200
        schemas:
            product_assets:
                label: 'Product assets'
                path_prefix: /products
                asset_types: [image]
                fields:
                    - { name: copyright, type: input, default: '(c) ACME' }
                    - { name: usage_rights, type: select, options: [web, print, all] }
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | `false` hides the module's admin resources |
| `batch_size` | `200` | Assets per batch in bulk apply (min 1) |
| `schemas.<name>.label` | `''` | |
| `schemas.<name>.path_prefix` | `/` | Applies to assets whose full path starts with this string |
| `schemas.<name>.asset_types` | `[]` | Empty = any type |
| `schemas.<name>.fields[].name` | required | |
| `schemas.<name>.fields[].type` | `input` | `input`, `textarea`, `select`, `checkbox`, `number`, `date` |
| `schemas.<name>.fields[].label` | `''` | |
| `schemas.<name>.fields[].options` | `[]` | For `select` |
| `schemas.<name>.fields[].default` | `null` | Initial value for bulk initialisation |
| `schemas.<name>.fields[].description` | `''` | |

## translation

See [modules/translation.md](modules/translation.md).

```yaml
elevate_dxp:
    translation:
        enabled: true
        provider: pseudo
        languages: [en, it, de, fr, es, pt, nl]
        libretranslate:
            url: 'http://libretranslate:5000'
            api_key: '%env(default::LIBRETRANSLATE_API_KEY)%'
            timeout: 10
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | `false` hides the module's admin resources |
| `provider` | `pseudo` | Default provider key; unknown keys fall back to `pseudo` |
| `languages` | `[en, it, de, fr, es, pt, nl]` | Languages offered in admin dialogs |
| `libretranslate.url` | `http://libretranslate:5000` | Must be http(s) |
| `libretranslate.api_key` | `''` | |
| `libretranslate.timeout` | `10` | Seconds (min 1) |

## portal

See [modules/portal.md](modules/portal.md).

```yaml
elevate_dxp:
    portal:
        enabled: true
        products_class: Product
        object_image_field: image
        allowed_asset_paths: ['/products']
        share:
            ttl_days: 7
            max_ttl_days: 90
        search:
            backend: listing
            page_size: 24
            max_page_size: 100
            asset_fields: [filename, path]
            asset_metadata: true
            default_object_fields: [key]
            object_fields:
                Product: [name, sku]
            locale: null
            restrict_assets_to_allowed_paths: false
        branding:
            portal_name: 'Elevate DXP Portal'
            logo_url: null
            primary_color: '#0e7c7b'
            accent_color: '#16242b'
            footer_text: 'Elevate DXP portal · GPL-3.0-or-later'
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | `false` hides the module's admin resources |
| `products_class` | `Product` | Default class for object searches |
| `object_image_field` | `image` | Object field whose asset goes into ZIP downloads |
| `allowed_asset_paths` | `['/products']` | Deny-by-default allow-list for downloads and guest shares (folder boundaries) |
| `share.ttl_days` / `share.max_ttl_days` | `7` / `90` | Default and maximum share-link lifetime |
| `search.backend` | `listing` | Name of a `SearchBackendInterface` service |
| `search.page_size` / `search.max_page_size` | `24` / `100` | |
| `search.asset_fields` | `[filename, path]` | Asset columns matched by the text search |
| `search.asset_metadata` | `true` | Also match asset metadata values |
| `search.default_object_fields` | `[key]` | Fallback searchable object fields |
| `search.object_fields` | `{}` | Class → searchable fields |
| `search.locale` | `null` | Locale for localized fields |
| `search.restrict_assets_to_allowed_paths` | `false` | Limit asset search results to `allowed_asset_paths` |
| `branding.*` | see above | Public share page branding; colours must be hex |

## workflow

See [modules/workflow.md](modules/workflow.md).

```yaml
elevate_dxp:
    workflow:
        enabled: true
        storage_dir: var/elevate-dxp/workflows
        apply_dir: config/local
        mermaid_render_url: 'https://mermaid.ink/svg/'
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | `false` hides the module's admin resources |
| `storage_dir` | `var/elevate-dxp/workflows` | Designer definitions (YAML), relative to the project dir |
| `apply_dir` | `config/local` | Where *Apply* writes `elevate_dxp_workflow_<name>.yaml`; must be imported by your config |
| `mermaid_render_url` | `https://mermaid.ink/svg/` | External renderer for previews; `null` = no external call |

## sso

See [modules/sso.md](modules/sso.md).

```yaml
elevate_dxp:
    sso:
        enabled: false
        groups_claim: groups
        identifier_claim: sub
        jit_provisioning: false
        providers:
            keycloak:
                type: oidc
                issuer: 'https://idp.example.com/realms/dxp'
                client_id: opendxp
                client_secret: '%env(SSO_CLIENT_SECRET)%'
                scopes: [openid, email, profile, groups]
                role_mapping:
                    dxp_admins: admin
                    dxp_editors: Editor
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `false` | Every SSO login is denied until enabled |
| `groups_claim` | `groups` | Claim holding the IdP groups |
| `identifier_claim` | `sub` | Claim used as the OpenDXP user name (falls back to `email`) |
| `jit_provisioning` | `false` | Create unknown users on first login |
| `providers.<name>.type` | `oidc` | Only `oidc` |
| `providers.<name>.issuer` / `client_id` / `client_secret` | `''` | IdP settings for your login integration |
| `providers.<name>.scopes` | `[openid, email, profile, groups]` | |
| `providers.<name>.role_mapping` | `{}` | IdP group → OpenDXP role name, or `admin` for the admin flag. Unmapped groups grant nothing. Keys are matched exactly (hyphens are kept) |

# Migrating an existing Pimcore site

This guide describes a low-risk path from a Pimcore 11 site to OpenDXP 1.x with Elevate DXP. It is written around a typical case: a food brand that runs its corporate website and its product catalogue (products, recipes, allergens, nutrition tables, packshots) on Pimcore, with some targeting and a few exports to retailers.

The path is deliberately split into small, reversible steps. Each step ends in a working, tested site.

> **Authoritative sources.** The Pimcore → OpenDXP part is governed by the official OpenDXP documentation, in particular the [upgrade notes](https://github.com/open-dxp/opendxp/blob/1.x/doc/23_Installation_and_Upgrade/09_Upgrade_Notes/README.md) ("Get started with OpenDXP 1.0"), the [version migration notes](https://github.com/open-dxp/opendxp/blob/1.x/doc/23_Installation_and_Upgrade/09_Upgrade_Notes/Version_Migration.md) and the [repository](https://github.com/open-dxp/opendxp). This page summarises them for planning; when it differs from them, they win. Elevate DXP is not affiliated with OpenDXP or Pimcore GmbH.

## Overview

| Phase | Goal | Changes code? | Changes data? |
|---|---|---|---|
| 0 | Assess and prepare | No | No |
| 1 | Upgrade to the latest Pimcore 11.5.x | Composer, small fixes | Pimcore migrations |
| 2 | Switch to OpenDXP 1.x | Namespaces, config keys, packages | Settings store, migrations table, serialized data |
| 3 | Reinstate personalization | Packages, config | None (tables are reused) |
| 4 | Add Elevate DXP, one feature at a time | Config, templates | New `edxp_*` tables |

Plan phases 1–3 as one project with a code freeze, and phase 4 as normal feature work afterwards.

## Phase 0: assess

Collect the facts before estimating:

```bash
composer show 'pimcore/*'                     # Pimcore version and bundles
php -v                                        # OpenDXP 1.x needs PHP >= 8.3
bin/console debug:container --parameters | grep -i pimcore | head
grep -rn "Pimcore\\\\" src/ config/ templates/ | wc -l     # size of the namespace change
grep -rln "pimcore_" config/ templates/ | sort             # config keys and Twig functions
```

Check, and write down:

- **Pimcore version.** OpenDXP 1.0 starts from Pimcore 11.5.x. Pimcore 10 or older must first be upgraded to Pimcore 11 following Pimcore's own upgrade guides; that is a larger project (Symfony 6, admin UI extracted into a bundle, `o_` column removal) and out of scope here.
- **PHP and database.** OpenDXP's [system requirements](https://github.com/open-dxp/opendxp/blob/1.x/doc/23_Installation_and_Upgrade/01_System_Requirements.md): PHP >= 8.3, MariaDB >= 10.3 or MySQL >= 8.0.
- **Bundles in use.** List every `pimcore/*` package and every third-party bundle. For each, find the OpenDXP equivalent (the [open-dxp organisation](https://github.com/open-dxp) publishes ports such as `open-dxp/admin-bundle`, `open-dxp/personalization-bundle`, `open-dxp/newsletter-bundle`, `open-dxp/data-hub-bundle`, `open-dxp/data-importer-bundle`, `open-dxp/advanced-object-search-bundle`, `open-dxp/ecommerce-framework-bundle`, `open-dxp/web-to-print-bundle`) or decide to drop it. Third-party bundles that type-hint `Pimcore\` classes will not work until their vendor ports them.
- **Enterprise or commercially licensed extensions.** These have no OpenDXP version. See the [mapping table](#feature-mapping) and plan replacements before you start.
- **Studio UI.** If you use Pimcore Studio, note that OpenDXP ships the classic ExtJS admin (`open-dxp/admin-bundle`).
- **Custom admin JS.** ExtJS extensions keep working in principle but reference the `pimcore` JavaScript namespace; they need the same rename as PHP.
- **Versions.** Serialized versions contain class names; decide whether you need old versions after the migration.

Prepare:

- A staging copy of production: database dump, `var/` (assets, versions, config), `public/var/`.
- A tagged release of the current code.
- A test list (see [What to test](#what-to-test)).

## Phase 1: latest Pimcore 11.5.x

The official OpenDXP notes start with: *"Upgrade to the latest Pimcore 11.5.x first!"*

1. Set the constraint to `~11.5.0` for `pimcore/pimcore` and update the Pimcore bundles to their 11.5-compatible versions.
2. `composer update`, run the Pimcore migrations as documented by Pimcore, clear caches.
3. Fix deprecations reported in the logs; the OpenDXP 1.0 notes list removals of methods that were deprecated in Pimcore 11.
4. Deploy and run the site on 11.5.x for a while. This isolates "Pimcore upgrade" problems from "OpenDXP switch" problems.

## Phase 2: switch to OpenDXP 1.x

Follow the official "Get started with OpenDXP 1.0" section step by step. In summary:

**Packages**

- Replace `pimcore/pimcore` with `open-dxp/opendxp` (`^1.4`).
- Replace `pimcore/admin-ui-classic-bundle` with `open-dxp/admin-bundle`.
- Replace other `pimcore/*` bundles with their `open-dxp/*` counterparts. Bundle classes follow the pattern `Pimcore\Bundle\XyzBundle\PimcoreXyzBundle` → `OpenDxp\Bundle\XyzBundle\OpenDxpXyzBundle`; check each bundle's README.
- Compare `config/bundles.php` with the [OpenDXP skeleton](https://github.com/open-dxp/skeleton/blob/1.x/config/bundles.php).

**Code and configuration** (from the official notes)

| Area | Pimcore | OpenDXP |
|---|---|---|
| PHP namespace | `Pimcore\` | `OpenDxp\` |
| `bin/console` | Pimcore bootstrap | Replace use statements, or copy from the [skeleton](https://github.com/open-dxp/skeleton/blob/1.x/bin/console) |
| Config blocks | `pimcore_*` (e.g. `pimcore_admin`) | `opendxp_*` (also in `var/config`) |
| Config autoload dir | `config/pimcore` | `config/opendxp` |
| Constants | `PIMCORE_*` | `OPENDXP_*` |
| Messenger transports | `pimcore_*` | `opendxp_*` |
| Commands | `pimcore:*` | `opendxp:*` |
| Twig functions | `pimcore_*` | `opendxp_*` |
| Controller helper | `getPimcoreUser()` | `getOpenDxpUser()` |

**Database** (from the official notes)

- In `settings_store`, replace `BUNDLE_INSTALLED__Pimcore` with `BUNDLE_INSTALLED__OpenDxp`.
- Delete the entries of the removed `Pimcore\Bundle\CoreBundle\Migrations` namespace from `migration_versions`, then run the OpenDXP core migrations: `bin/console doctrine:migrations:migrate --prefix=OpenDxp\\Bundle\\CoreBundle`.
- Check `documents_editables` and `properties` for hard-coded `Pimcore\` class names; the notes give the exact `SELECT` and `UPDATE ... REPLACE(data, 'Pimcore\\', 'OpenDxp\\')` statements.
- If you need old versions: use the command from the [version migration notes](https://github.com/open-dxp/opendxp/blob/1.x/doc/23_Installation_and_Upgrade/09_Upgrade_Notes/Version_Migration.md) to rewrite `var/versions` (it works because `Pimcore` and `OpenDxp` have the same length).

**Breaking changes to check** (selection; read the full list)

- The hard-coded password salt was removed. Set `opendxp.security.password.salt: pimcore` to keep existing password logins working.
- TinyMCE is the WYSIWYG editor (the Quill bundle was removed from the skeleton).
- `symfony/templating` was removed; use Twig.
- Native Chromium support was removed; use Gotenberg.

Then:

```bash
composer update
bin/console cache:clear
bin/console doctrine:migrations:migrate --prefix=OpenDxp\\Bundle\\CoreBundle
bin/console opendxp:cache:clear
bin/console lint:container
```

Run the full test list on staging before going further. Do not install Elevate DXP yet.

## Phase 3: reinstate personalization

In Pimcore 11, targeting and personalization live in `pimcore/personalization-bundle`. The OpenDXP port is `open-dxp/personalization-bundle`, which requires `open-dxp/newsletter-bundle`.

```bash
composer require open-dxp/personalization-bundle:^1.0
```

```php
// config/bundles.php
OpenDxp\Bundle\NewsletterBundle\OpenDxpNewsletterBundle::class => ['all' => true],
OpenDxp\Bundle\PersonalizationBundle\OpenDxpPersonalizationBundle::class => ['all' => true],
```

```bash
bin/console opendxp:bundle:install OpenDxpNewsletterBundle
bin/console opendxp:bundle:install OpenDxpPersonalizationBundle
```

```yaml
# config/config.yaml   (was: pimcore_personalization)
opendxp_personalization:
    targeting:
        enabled: true
```

The personalization installer creates `targeting_rules`, `targeting_target_groups` and `targeting_storage` with `CREATE TABLE IF NOT EXISTS`, so the existing tables, rules and target groups are kept. Per-target-group document content is stored with the document editables and carries over with them. If the bundle is already recorded as installed after the settings-store rename, the install command only reports that.

Check:

- *Marketing → Personalization*: rules and target groups are listed, conditions and actions open in the editor.
- A personalised page shows the right content for a test visitor (the personalization bundle documents its debugging tools).
- If you had custom conditions or action handlers, port their namespaces and their registration (`opendxp_personalization.targeting.conditions` / `action_handlers`).

## Phase 4: add Elevate DXP feature by feature

Install the bundle (see the [README](../README.md#installation)). Installation only adds `edxp_*` tables and permissions; nothing changes on the website until you configure a module, create an experiment or add a Twig helper.

```bash
composer require elevate-dxp/elevate-bundle
# register ElevateDxp\ElevateDxpBundle after the personalization bundle
bin/console opendxp:bundle:install ElevateDxpBundle
bin/console assets:install public
bin/console elevate-dxp:diagnostics
```

Suggested order for the food brand, lowest risk first:

1. **Data quality** (read-only). Configure profiles for `Product` and `Recipe` with the fields retailers require (allergens, nutrition table, ingredients, packshot). Fix gaps before syndicating data.
2. **Statistics** (read-only). YAML SQL reports, e.g. products per category, assets without metadata.
3. **Feeds and exports.** Recreate retailer exports and the Google Merchant feed as YAML. Compare the output with the old files line by line before switching consumers to the new URLs.
4. **Datahub REST / webhooks.** Expose the product catalogue read-only to the e-commerce or ERP side; notify it on `object.update`. Run the `elevate_dxp` Messenger worker.
5. **DAM metadata, portal.** Copyright and usage-rights schemas for packshots; a press portal with expiring share links for agencies and retailers.
6. **Translation, workflow.** XLIFF pre-translation for new markets; a product review workflow.
7. **Experiments and insights.** Decide on consent first (`consent_cookie`). Then start with one experiment, e.g. a recipe page call-to-action, with a sample size computed in advance (see [experiments.md](experiments.md)).

Each step is independent: if one causes trouble, revert its configuration and templates; the rest keeps working.

## Feature mapping

Pimcore offers several features only in its commercial editions or as separately licensed extensions. OpenDXP is GPLv3 and does not include them. The table maps common needs to what exists today. "Partial" means a different, usually simpler, feature set: plan a functional review and a data migration of your own, as Elevate DXP provides no importers for proprietary formats.

| Need (Pimcore commercial edition / extension) | OpenDXP free bundle | Elevate DXP module | Coverage |
|---|---|---|---|
| Targeting rules, target groups, personalised content | `open-dxp/personalization-bundle` | — (extends it) | Full (OpenDXP) |
| A/B testing with statistics | — | Experiments | Elevate DXP |
| Customer data / visitor profiles (Customer Data Framework) | — | Insights (CDP-lite) | Partial: anonymous first-party profiles only, no customer objects, no identity merging |
| GraphQL data delivery (Datahub) | `open-dxp/data-hub-bundle` | Datahub lists its configurations | Full (OpenDXP) |
| REST delivery of objects/assets | — | Datahub (REST) | Partial: read-only, YAML-defined endpoints |
| Data import (Data Importer) | `open-dxp/data-importer-bundle` | — | OpenDXP |
| Product syndication / channel feeds (Product Data Syndicator) | — | Feed, Export | Partial: generic CSV and Google Merchant templates; CSV/JSON/XML exports |
| Datahub webhooks | — | Webhook (+ Automation for n8n) | Partial: element add/update/delete events |
| Asset Experience Portal / Portal Engine | — | Portal | Partial: admin-based search, cart, ZIP, collections, guest share links; no front-end portal builder |
| Workflow Designer | Workflows in YAML (core) | Workflow | Partial: designer, validation, BPMN import/export, Mermaid preview |
| Statistics Explorer | Custom Reports (core) | Statistics | Partial: YAML SQL reports with charts |
| Translations Provider Interface | XLIFF export/import (core `XliffBundle`) | Translation | Partial: Pseudo and LibreTranslate providers; custom providers via an interface |
| Asset metadata definitions | Predefined metadata (core) | DAM metadata | Partial: typed schemas, bulk apply |
| Single sign-on (OpenID Connect) | — | SSO | Partial: role mapping and provisioning; you add the OIDC flow |
| AI assistance (Copilot) | `open-dxp/mcp-bundle` exists; check its scope | Insights copilot | Partial: text presets via the Anthropic API |
| Advanced object search | `open-dxp/advanced-object-search-bundle` | Portal can use it through a search backend | OpenDXP |
| E-commerce framework | `open-dxp/ecommerce-framework-bundle` | — | OpenDXP |
| Newsletter | `open-dxp/newsletter-bundle` | — | OpenDXP |
| Web-to-print | `open-dxp/web-to-print-bundle` | — | OpenDXP |

Feature names in the first column are given for orientation only. Check the actual feature set of what you license today against the module documentation before committing to a replacement.

## Risk and rollback checklist

Before phase 2:

- [ ] Full database dump and copy of `var/` and `public/var/` from the same point in time.
- [ ] Tagged Pimcore 11.5.x release that can be redeployed unchanged.
- [ ] Staging environment with production data, used for a full rehearsal.
- [ ] Inventory of bundles and replacements completed; no unknown `Pimcore\` dependencies left.
- [ ] Password salt decision made (`opendxp.security.password.salt`); at least one admin login tested on staging.
- [ ] Downtime window and content freeze agreed with editors.
- [ ] Integrations (ERP, shop, retailers' feeds) listed with owners who can test on the day.

Rollback:

- Phases 2 and 3 change the database (settings store, migration table, serialized data, version files). Rolling back means **restoring the dump and `var/`** and redeploying the Pimcore release. Do not try to reverse the SQL replacements by hand.
- Content created after the switch is lost on rollback; keep the freeze until the site is accepted.
- Phase 4 is reversible per module: remove its configuration, templates and permissions. Uninstalling `ElevateDxpBundle` removes its permissions but keeps the `edxp_*` tables; drop them manually only if you want the data gone.

Risks to watch:

| Risk | Mitigation |
|---|---|
| Third-party bundle without OpenDXP port | Replace, port it yourself, or postpone the migration |
| Hidden `Pimcore\` strings in serialized data | Run the official `SELECT` checks; search `var/config` and custom tables |
| Admin logins fail | Password salt setting; test before go-live |
| Custom ExtJS breaks | Rename the `pimcore` JS namespace usages; test every custom panel |
| Personalised content missing | Check that targeting is enabled and rules are active after phase 3 |
| Caching serves wrong variants (phase 4) | See [experiments.md](experiments.md#caching) |

## What to test

After each phase, on staging, then in production:

**Editing**

- [ ] Admin login (password and, if used, 2FA), all custom perspectives and permissions.
- [ ] Open, edit, save and publish a page, a snippet, a `Product` and a `Recipe` object; version history and restore.
- [ ] Upload an asset, generate thumbnails, edit metadata.
- [ ] Translations of documents and objects; XLIFF export/import.
- [ ] Workflows: transitions on a product.

**Website**

- [ ] Home page, product listing, product detail, recipe detail, search, contact form, in every language.
- [ ] Static routes and redirects; sitemap; `robots.txt`.
- [ ] Personalised pages for at least two target groups (phase 3 onward).
- [ ] Error pages (404, 500) and maintenance mode.

**Operations**

- [ ] Maintenance cron (`opendxp:maintenance` in place of `pimcore:maintenance`) and all Messenger workers (`opendxp_*` transports, plus `elevate_dxp` in phase 4).
- [ ] Scheduled exports and imports; integrations with ERP and shop.
- [ ] Application Logger receives entries; error monitoring works.
- [ ] Backups run against the new setup.

**Elevate DXP (phase 4)**

- [ ] `bin/console elevate-dxp:diagnostics` shows the bundle installed and the expected resources.
- [ ] Each configured feed/export produces the same content as the system it replaces.
- [ ] Webhook test deliveries succeed (`elevate-dxp:webhook:test <subscription>`).
- [ ] With `consent_cookie` set, no `edxp_vid` cookie appears before consent.
- [ ] An experiment shows both variants across browsers and records conversions.

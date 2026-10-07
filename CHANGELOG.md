# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Datahub: *GraphQL configurations* explains how to install `open-dxp/data-hub-bundle` when it is not enabled (new *Data Hub status* action); README documents the optional GraphQL Data Hub installation.

## [1.0.0] - 2026-10-07

First release of Elevate DXP as a single bundle (`elevate-dxp/elevate-bundle`, `ElevateDxp\ElevateDxpBundle`) for OpenDXP ^1.4.

### Added

- **Core**
  - Module system: one `elevate_dxp:` configuration tree, one subtree and one service file per module.
  - Aggregated installer (`opendxp:bundle:install ElevateDxpBundle`): idempotent `edxp_*` tables and permissions in the "Elevate DXP" category.
  - Schema-driven admin framework (`AdminResourceInterface`, `Field`, `Action`, `AbstractDbalResource`) served by one `ResourceController` under `/admin/elevate-dxp`, with an "Elevate DXP" main menu filtered by permissions.
  - Audit trail in the Application Logger with secret masking.
  - Messenger transport `elevate_dxp` (`ELEVATE_DXP_MESSENGER_DSN`, default `sync://`).
  - `elevate-dxp:diagnostics` and idempotent `elevate-dxp:setup` (schema and permission sync for deployments).
  - `enabled: false` on a module hides its admin resources from the menu and the admin API.
  - Field mapping only ever calls read accessors (`get*`, `is*`, `has*`).
- **Experiments**
  - A/B and multivariate experiments with deterministic, sticky assignment, traffic share, schedule, URL scope and audience target group.
  - Hidden per-variant target groups for per-target-group document content.
  - Twig functions `edxp_variant`, `edxp_in_variant`, `edxp_variant_payload`, `edxp_experiments`, `edxp_target_groups`.
  - Conversion tracking endpoint `POST /_edxp/track` and runtime (`window.edxp.track()`, `data-edxp-track`, `data-edxp-track-submit`) with same-origin check, size cap, per-visitor rate limit and event allow-list.
  - Signed first-party visitor cookie `edxp_vid`, consent cookie gate, bot and path exclusion.
  - Reports with conversion rate, uplift, two-proportion z-test p-value and sample-size calculator.
  - Targeting conditions `edxp_experiment_variant`, `edxp_query_param`, `edxp_utm`, `edxp_cookie`, `edxp_time_window`, `edxp_returning_visitor`; action `edxp_set_variant`.
  - Asynchronous forwarding to Matomo or PostHog.
  - Commands `elevate-dxp:experiments:demo-seed`, `:report`, `:distribution` and `:prune` (data retention).
- **Insights**: visitor profiles with segments, timeline and erasure; data-quality reports; Claude copilot.
- **Feed**: generic CSV and Google Merchant feeds with validation, preview and tokenized public URLs.
- **Export**: CSV/JSON/XML exports to local files, assets or HTTP endpoints.
- **Datahub**: API-key protected read-only REST endpoints for data objects and assets; listing of OpenDXP Data Hub GraphQL configurations.
- **Webhook**: HMAC-signed webhooks on object, asset and document events, delivery log and re-delivery.
- **Automation**: n8n blueprints for webhook subscriptions.
- **Statistics**: read-only SQL reports with charts and seeding into native Custom Reports.
- **DAM metadata**: typed asset metadata schemas and bulk apply.
- **Translation**: XLIFF fill with Pseudo and LibreTranslate providers.
- **Portal**: asset and object search, download cart with ZIP, collections, saved views, expiring guest share links.
- **Workflow**: workflow designer with validation, Mermaid preview, BPMN import/export and apply to `opendxp.workflows`.
- **SSO**: deny-by-default claim-to-role mapping, user provisioning and login decision service.
- Documentation: README, architecture, configuration reference, module guides, security and privacy, migration from Pimcore.
- CI: GitHub Actions with static analysis (PHPStan level 6, PHP-CS-Fixer), unit tests on PHP 8.3–8.5 and an integration job that installs the bundle on a fresh OpenDXP application and runs HTTP smoke tests.

[Unreleased]: https://github.com/gabrielepiccinnu/elevate-dxp/compare/v1.0.0...dev
[1.0.0]: https://github.com/gabrielepiccinnu/elevate-dxp/releases/tag/v1.0.0

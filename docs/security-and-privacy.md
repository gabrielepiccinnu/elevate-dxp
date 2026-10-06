# Security and privacy

This page describes what Elevate DXP stores, which endpoints it exposes, and how they are protected. It is not legal advice. Whether you need consent for experiments and tracking depends on your jurisdiction and your setup; decide that with your data protection officer.

To report a vulnerability, see [SECURITY.md](../SECURITY.md).

## Principles

- **Deny by default.** Admin resources require a permission (admins always pass). The datahub denies every request until an API key is set. A feed has no public URL until it has a token of at least 16 characters. Portal guests only see assets inside `allowed_asset_paths`. SSO denies every login until enabled, and grants only mapped groups.
- **No personal data in first-party tracking.** Visitors are identified by a random id.
- **Secrets stay server side.** Admin resources show whether a key or secret is set, never its value. Audit logs mask secrets.
- **Public endpoints fail closed and quietly.** They answer with uniform errors that do not reveal which check failed.

## Visitor identification

The experiments module issues the cookie `edxp_vid` (name configurable):

| Property | Value |
|---|---|
| Content | `<id>.<mac>`: 128-bit random hex id plus a base64url HMAC-SHA256 of the id |
| Key | `elevate_dxp.experiments.visitor.secret` (default `%kernel.secret%`) |
| Flags | `HttpOnly`, `SameSite=Lax`, `Secure` when the request is HTTPS, path `/` |
| Lifetime | `cookie_ttl_days` (default 365) |
| Set when | A trackable frontend HTML `GET` response, for a visitor without a valid cookie |

A cookie with an invalid signature is ignored and replaced. The tracking endpoint takes the visitor only from this cookie, never from the request body, so clients cannot attribute events to another visitor.

Why a separate cookie: the personalization bundle's visitor id is created client-side and is missing on the very first request; experiments need a stable id immediately. When targeting is on, the profile also stores the targeting visitor id for reference.

Rotating the secret invalidates every visitor id: returning visitors get new ids and new assignments.

## What is stored

| Table | Data | Personal data |
|---|---|---|
| `edxp_assignment` | visitor id, experiment, variant, forced flag, time | Pseudonymous id only |
| `edxp_event` | visitor id, event name/type/value, URL, experiment/variant, `meta` (max 20 keys), time | Pseudonymous id; URL and `meta` as sent by your pages |
| `edxp_visitor_profile` | visitor id, first/last seen, counts, first/last URL, referrer, UTM first/last, target group names | Pseudonymous id; URLs as visited |

Not stored: IP address, user agent, name, e-mail, precise location.

The visitor id is pseudonymous data. It is still personal data under the GDPR if it can be linked to a person, for example together with your own logs. Avoid personal data in URLs, query strings, UTM values and the `meta` of tracked events.

## Consent

Set `consent_cookie` to the name of a cookie your consent management platform sets only after consent:

```yaml
elevate_dxp:
    experiments:
        visitor:
            consent_cookie: cookie_consent_statistics
```

Until that cookie exists on the request, Elevate DXP issues no cookie, assigns no variant, records no exposure, updates no profile and does not inject the runtime for tracking. Visitors without consent see the default content. The check is for the cookie's presence only; if your CMP always sets the cookie and stores the choice in its value, set a dedicated cookie on consent or disable the module for those visitors.

`edxp_vid` is not strictly necessary for the site to work. Treat it like an analytics cookie in your cookie policy.

## Exclusions

Requests are not trackable (no cookie, no assignment, no profile, no events) when:

- the path matches one of `visitor.excluded_paths` (default `^/admin`, `^/_`, `^/bundles/`, `^/elevate-dxp/`);
- the user agent matches `bot|crawl|spider|slurp|preview|headless|lighthouse|monitor|curl/|wget` (case-insensitive);
- the request is an editmode or preview request by an admin, or not a frontend request;
- consent is required and missing.

Bot detection by user agent is a heuristic. It keeps common crawlers and monitoring tools out of experiment denominators, not determined bots.

## Tracking endpoint `POST /_edxp/track`

| Control | Behaviour |
|---|---|
| Same origin | If an `Origin` header is present and its host differs from the request host: 403 |
| Size | Body above `tracking.max_payload_bytes` (4096): 413 |
| Identity | Signed cookie only; unknown visitor: 204 and nothing stored |
| Rate limit | `tracking.rate_limit_per_minute` (60) per visitor, sliding window, stored in `cache.app`: 429 |
| Event names | `[a-z0-9_.:-]{1,64}`, optional allow-list `tracking.allowed_events`; `exposure` is reserved for the server: 422 |
| Exposures | Recorded server side only, so clients cannot inflate denominators |

The endpoint is not CSRF-protected: it only accepts events for the visitor in the cookie, and SameSite=Lax prevents the cookie from being sent with cross-site POST requests in current browsers. Use `allowed_events` in production to limit what can be recorded.

## Caching and personalised responses

Responses for visitors with at least one experiment assignment get `Cache-Control: private` and `X-Edxp-Experiments: <count>`. Make sure no shared cache (OpenDXP full-page cache, reverse proxy, CDN) serves one visitor's variant to another. See [experiments.md](experiments.md#caching).

## Data subject requests and retention

- **Access / erasure:** *Elevate DXP → Insights → Visitor Profiles*. *Timeline* shows all events and assignments of a visitor; *Delete* removes the visitor's rows from `edxp_visitor_profile`, `edxp_event` and `edxp_assignment` in one transaction. Procedure: [insights.md](insights.md#gdpr-requests).
- **Third parties:** events forwarded to Matomo or PostHog must be erased there too. Copilot prompts are sent to Anthropic.
- **Retention:** there is no automatic expiry. Schedule `bin/console elevate-dxp:experiments:prune` (defaults: events and profiles older than 395 days, about 13 months) to delete old `edxp_event` and `edxp_visitor_profile` rows and the assignments of removed visitors without recent events ([details](insights.md#retention)). Schedule `elevate-dxp:webhook:prune` (default 30 days) for the webhook delivery log, which stores payloads.

## Admin security

- Admin endpoints live under `/admin/elevate-dxp/`, behind the OpenDXP admin firewall and session.
- The OpenDXP admin bundle checks the CSRF token on every non-GET admin request.
- Every resource requires its permission. Actions must be declared in the resource schema to be callable.
- `AbstractDbalResource` only uses declared field names as SQL identifiers; values are bound as parameters.
- Validation errors are returned as `400` with a message; other exceptions are not caught by the resource controller and follow the normal OpenDXP error handling.
- Some actions additionally require native permissions (for example `assets`, `asset_metadata`, `reports`, `reports_config`) and element permissions such as `save` or `view`.

Grant permissions per role. In particular, restrict:

| Permission | Why |
|---|---|
| `elevate_dxp_cdp` | Access to pseudonymous visitor histories and erasure |
| `elevate_dxp_copilot` | Sends element content to an external API |
| `elevate_dxp_statistics` | Runs read-only SQL configured by developers; results may include any table content |
| `elevate_dxp_webhook`, `elevate_dxp_datahub` | Can trigger outbound requests / see API responses |
| `elevate_dxp_sso` | Shows the role mapping and simulates logins |

## Audit log

Admin saves, deletes and actions, public feed and datahub calls, exports, webhook deliveries, portal guest views and ZIP downloads, and DAM bulk operations are written to the OpenDXP Application Logger (component `elevate-dxp.<area>`).

- Context keys `api_key`, `apikey`, `password`, `secret`, `token`, `authorization` and `client_secret` are replaced by `***`, at any depth.
- API keys and tokens are identified only by a short SHA-256 prefix (for example `apikey:3f2a…`, `token:9c1b…`).
- Audit failures never break the audited operation.
- Disable with `elevate_dxp.core.audit.enabled: false` (not recommended).

The Application Logger is personal-data-relevant if it records admin user names; apply your usual log retention.

## Public endpoints

| Endpoint | Protection |
|---|---|
| `POST /_edxp/track` | See above |
| `GET /elevate-dxp/feed/{name}.{csv\|xml}` | Per-feed token (min 16 characters, `hash_equals`), `?token=` or `X-Elevate-Dxp-Feed-Token` header; every denial is the same 404; `noindex`, `no-referrer`. Prefer the header: query strings end up in access logs |
| `GET /elevate-dxp/api/{endpoint}[/{id}]` | Static API key in a header (`hash_equals`), checked before the endpoint lookup; read-only; published objects only; `Cache-Control: no-store`; JSON errors without stack traces |
| `GET /elevate-dxp/portal/share/{token}[/download]` | 160-bit random, expiring, revocable tokens; uniform 403 for invalid, expired or revoked links; only allowed asset paths; `no-store`, `no-referrer`, `noindex`, `X-Frame-Options: DENY` |
| `POST /elevate-dxp/webhook-sink` | Debug only, 404 unless `webhook.sink_enabled: true`; optional signature check. Never enable in production |

## Outbound requests

| Module | Destination | Notes |
|---|---|---|
| Webhook | Subscription URLs | http(s) only, HMAC-SHA256 signature in `X-ElevateDxp-Signature`, no redirects, timeout `webhook.timeout` |
| Export / feed (`http` target) | Configured URL | POST, no redirects, 15 s timeout |
| Experiments analytics | Matomo / PostHog | Visitor id, event, URL, variants, `meta` |
| Insights copilot | `api.anthropic.com` | Prompt and selected element content |
| Translation | LibreTranslate URL | Texts to translate |
| Workflow preview | `mermaid_render_url` (default `mermaid.ink`) | Place and transition names; set to `null` to disable |

Secrets used for these calls (`secret`, `api_key`, `token`) belong in environment variables, never in committed YAML.

## Hardening checklist

- [ ] `opendxp_personalization.targeting.enabled` only if you use targeting.
- [ ] `experiments.visitor.consent_cookie` set if your legal basis is consent.
- [ ] `experiments.tracking.allowed_events` set to your real event names.
- [ ] A real Messenger DSN and a supervised `messenger:consume elevate_dxp` worker.
- [ ] Datahub `api_key`, feed `token`s, webhook `secret`s and copilot key from environment variables.
- [ ] `webhook.sink_enabled: false` in production.
- [ ] Permissions granted per role, not to everyone.
- [ ] Shared caches do not cache pages with experiments.
- [ ] A retention job for `edxp_event`, `edxp_visitor_profile` and `edxp_assignment` (`elevate-dxp:experiments:prune`) and for `edxp_webhook_delivery` (`elevate-dxp:webhook:prune`).
- [ ] `portal.allowed_asset_paths` limited to public material.

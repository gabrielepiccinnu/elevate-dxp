# Experiments

A/B and multivariate testing on top of OpenDXP targeting: sticky variant assignment, conversion tracking, statistical reports, six extra targeting conditions, a "set variant" rule action, and optional forwarding to Matomo or PostHog.

Config key: `elevate_dxp.experiments` · Permission: `elevate_dxp_experiments` · Admin menu: Elevate DXP → Marketing

## Concepts

| Term | Meaning |
|---|---|
| Experiment | A test with a unique `key`, a status, a traffic share, a goal event and two or more variants |
| Variant | `key`, `weight`, optional JSON `payload`, optional `target_group_id` |
| Control | The baseline for uplift: the variant named `control`, `A`, `a` or `original`, otherwise the first variant |
| Visitor | An opaque id stored in the signed first-party cookie `edxp_vid` |
| Assignment | One row per (visitor, experiment) in `edxp_assignment`. Sticky: never changes once stored |
| Exposure | Recorded once, server side, when a visitor is first assigned |
| Goal event | The event name that counts as a conversion, e.g. `signup` |
| Forced | An assignment made by the "Set experiment variant" rule action instead of the random split |

## Prerequisites

1. The bundle is installed (see the [README](../README.md#installation)).
2. For audiences, variant target groups, the extra conditions and the set-variant action: `opendxp_personalization.targeting.enabled: true`.
3. The theme outputs `</head>` in HTML responses, so the tracking runtime can be injected. Otherwise include it yourself (see [Tracking runtime](#tracking-runtime)).

## Creating an experiment

Open *Elevate DXP → Marketing → A/B Experiments* and add a record.

| Field | Notes |
|---|---|
| Key | 1–100 characters: letters, digits, `_`, `-`. Used in Twig and JS |
| Name | Free text |
| Status | `draft`, `running`, `paused`, `completed` |
| Traffic % | 0–100. Share of eligible visitors that enter the experiment. The others see the default content |
| Goal event | Lower-cased. Required before *Start* |
| Audience (target group) | Optional. Only visitors who already have this target group enter. Requires targeting |
| URL scope | Optional. A path prefix (`/shop`) or a regex delimited with `#` (`#^/shop/.+#`). Empty = whole site |
| Variants | JSON list, at least two, unique keys, total weight > 0 |
| Start / End | Optional schedule. A running experiment only assigns visitors between these times |
| Hypothesis / notes | Free text |

Variants example:

```json
[
  {"key": "A", "weight": 50, "payload": {"headline": "Welcome to our store"}},
  {"key": "B", "weight": 50, "payload": {"headline": "Save 20% on your first order"}}
]
```

Weights are relative: `[1, 1, 2]` splits 25/25/50.

### Record actions

| Action | Effect |
|---|---|
| Start | Sets status `running`. Refused without a goal event |
| Pause | Sets `paused`. No new assignments; existing visitors keep their variant when you resume |
| Complete | Sets `completed`. No new assignments |
| Create variant target groups | Creates or links one target group per variant, named `exp:<key>:<variant>` |
| Results | Per-variant report (see [Reports](#reports-and-statistics)) |
| Simulate split | Simulates N random visitors through the traffic gate and the weights |
| Sample size | Visitors needed per variant for a baseline rate and a minimum detectable lift |

While an experiment is running, you can change weights and payloads, but you cannot remove or rename variants. Pause it first. Changed weights only affect new visitors.

## Assignment

`ExperimentAssigner` is deterministic and sticky:

1. If the visitor already has an assignment for this experiment and the variant still exists, it is returned.
2. Traffic gate: if `traffic < 100`, the visitor enters only when `bucket(sha256(visitor|gate|key)) mod 100 < traffic`. Visitors who do not enter get no assignment row and are re-evaluated on later requests (with the same result while `traffic` is unchanged).
3. Variant pick: `bucket(sha256(visitor|var|key)) mod totalWeight` is placed on the cumulative weights.
4. The assignment is stored with a unique key on (visitor, experiment). An exposure event is recorded the first time.

The gate and the pick use independent hashes, so raising traffic from 20 % to 50 % keeps the existing visitors and their variants.

An experiment applies to a request when all of these hold:

- status `running`, and the current time is inside `start_at`/`end_at`;
- the request path matches the URL scope;
- the visitor has the audience target group, if one is set;
- the request is *trackable* (see [Privacy controls](#privacy-controls)).

Assignment happens during targeting (`TargetingEvents::POST_RESOLVE`) when targeting is on, and otherwise on the first call to a Twig helper or in the response listener.

Check the split without writing anything:

```bash
bin/console elevate-dxp:experiments:distribution hero --visitors=100000
```

## Rendering variants

### Option 1: Twig helpers

| Function | Returns |
|---|---|
| `edxp_variant(key)` | Variant key, or `null` when the visitor is not in the experiment |
| `edxp_in_variant(key, variant)` | `bool` |
| `edxp_variant_payload(key)` | The variant's `payload` array (empty array when not enrolled) |
| `edxp_experiments()` | `{experimentKey: variantKey}` for the current visitor |
| `edxp_target_groups()` | Names of the target groups assigned by OpenDXP targeting (empty without targeting) |

```twig
{% set payload = edxp_variant_payload('hero') %}
<h1>{{ payload.headline|default('Welcome to our store') }}</h1>

{% if edxp_in_variant('checkout_cta', 'green') %}
    <button class="btn btn-success" data-edxp-track="checkout">Checkout</button>
{% else %}
    <button class="btn btn-primary" data-edxp-track="checkout">Checkout</button>
{% endif %}
```

Always render a sensible default for `null` (not enrolled, bot, no consent, experiment paused).

### Option 2: per-target-group content (no template changes)

1. Run *Create variant target groups* on the experiment.
2. Open the page in the document editor and switch the target group selector to `exp:hero:B`.
3. Edit the editables for that group. Content you do not override falls back to the default.

When a visitor is assigned to `B`, the runtime assigns the group `exp:hero:B` with weight 1000, so the personalization bundle renders the `B` content. This needs targeting enabled.

### In JavaScript

```js
window.edxp.variant('hero');   // 'A' | 'B' | null
window.edxp.experiments;       // {hero: 'B'}
```

## Tracking

### Tracking runtime

For frontend `GET` requests that return HTML (not admin, not a redirect, not an editmode/preview request by an admin), the response listener injects:

```html
<script>window.edxpConfig={"trackUrl":"/_edxp/track","experiments":{"hero":"B"}};</script>
<script src="/bundles/elevatedxp/js/edxp-runtime.js" defer></script>
```

It is injected when tracking is enabled or the visitor is in at least one experiment. Disable injection with `elevate_dxp.experiments.runtime.inject: false` and add the two tags to your layout yourself.

### Sending events

```html
<button data-edxp-track="signup">Sign up</button>
<a href="/catalogue.pdf" data-edxp-track="download" data-edxp-value="1">Catalogue</a>
<form action="/contact" data-edxp-track-submit="lead">…</form>
```

```js
edxp.track('purchase', {value: 49.90, meta: {plan: 'pro'}});
edxp.track('video_play', {type: 'click'});
```

- Clicks are captured on the element with `data-edxp-track` or its closest ancestor; form submits through `data-edxp-track-submit`.
- `edxp.track()` uses `navigator.sendBeacon`, or `fetch` with `keepalive` as a fallback. Pass `{sync: true}` to force `fetch`.
- Names are lower-cased. Allowed pattern: `[a-z0-9_.:-]{1,64}`.

### `POST /_edxp/track`

Request body (JSON): `event` (required), `value` (numeric), `type` (`click`, `pageview`, `custom`), `url`, `meta` (object; at most 20 keys are kept).

| Response | When |
|---|---|
| 204 | Recorded, or the request has no valid visitor cookie (nothing to attribute) |
| 400 `invalid_json` | Body is not a JSON object |
| 403 `cross_origin` | `Origin` header present and its host differs from the request host |
| 404 `tracking_disabled` | `tracking.enabled: false` |
| 413 `payload_too_large` | Body larger than `tracking.max_payload_bytes` (4096) |
| 422 `event_not_allowed` | Invalid name, not in `tracking.allowed_events`, or `exposure` |
| 429 `rate_limited` | More than `tracking.rate_limit_per_minute` (60) requests per visitor per minute (sliding window, stored in `cache.app`) |

Rules:

- The visitor comes only from the signed cookie, never from the body.
- Exposures are recorded only by the server; clients cannot send `exposure`.
- If the event name equals the goal event of any running experiment, it is stored with type `conversion`.
- Conversions are not tied to an experiment in the request. The report attributes them by joining on the visitor (see below).

Restrict accepted events in production:

```yaml
elevate_dxp:
    experiments:
        tracking:
            allowed_events: [signup, lead, purchase, download, checkout]
```

## Reports and statistics

Open *Elevate DXP → Marketing → Experiment Results*, select an experiment, or run:

```bash
bin/console elevate-dxp:experiments:report hero
```

### Counting

For each variant:

- **visitors**: distinct visitors assigned to the variant (`edxp_assignment`);
- **conversions**: distinct assigned visitors who sent the goal event **at or after** their assignment time;
- **forced**: assignments made by the set-variant rule action.

A visitor counts at most once as a conversion per experiment. Conversions after the experiment was completed still count for visitors assigned before; compare reports at a fixed date if that matters.

### Columns

| Column | Formula |
|---|---|
| `conversion_rate` | conversions / visitors × 100 |
| `uplift` | (rate − control rate) / control rate × 100 |
| `p_value` | Two-sided two-proportion z-test against the control |
| `confidence` | (1 − p) × 100 |
| `significant` | p < 0.05 |

### Two-proportion z-test

For control (`c₁` conversions of `n₁` visitors) and a variant (`c₂` of `n₂`):

```
p̂  = (c₁ + c₂) / (n₁ + n₂)                    pooled rate
SE = √( p̂ (1 − p̂) (1/n₁ + 1/n₂) )
z  = (c₂/n₂ − c₁/n₁) / SE
p  = 2 · (1 − Φ(|z|))
```

Φ is the standard normal CDF, computed with the Abramowitz–Stegun approximation of `erf` (error < 1.5·10⁻⁷). `p` is `null` when a group has no visitors or SE is 0 (for example, no conversions at all).

Example: control 150 / 5000 (3.00 %), variant 190 / 5000 (3.80 %). Uplift +26.67 %, p ≈ 0.0273, confidence 97.3 %, significant.

### Sample size

The *Sample size* action estimates the visitors needed **per variant** to detect a relative lift with α = 0.05 (two-sided) and power 0.8:

```
p₁ = baseline, p₂ = p₁ · (1 + lift), p̄ = (p₁ + p₂) / 2
n  = ( 1.959964 · √(2 p̄ (1 − p̄)) + 0.841621 · √(p₁(1 − p₁) + p₂(1 − p₂)) )² / (p₂ − p₁)²
```

| Baseline | Relative lift | Visitors per variant |
|---|---|---|
| 3 % | 10 % | 53,211 |
| 5 % | 20 % | 8,158 |

### Reading results responsibly

- Decide the sample size and the run time before starting. Stop when the planned sample is reached, not when p first drops below 0.05. Checking repeatedly and stopping at the first significant result inflates false positives.
- With more than two variants, each variant is tested against the control separately. No multiple-comparison correction is applied; consider a stricter threshold (for example 0.05 / number of comparisons).
- The z-test assumes reasonably large samples. With fewer than about 10 conversions per variant, treat p-values as unreliable.
- Forced assignments are not random. If many visitors are forced, the comparison is biased; check the `forced` column.
- Run full weeks to cover weekday effects.

## Targeting conditions

Available in *Marketing → Personalization → Targeting Rules* (rule conditions and target group entry conditions).

| Key | Label | Configuration | Matches when |
|---|---|---|---|
| `edxp_experiment_variant` | Experiment variant | `experiment`, `variant` (empty = any) | The visitor is assigned to that experiment (and variant) |
| `edxp_query_param` | Query parameter | `name`, `mode`, `value` | The current request's query parameter matches |
| `edxp_utm` | UTM campaign | `parameter` (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`), `mode`, `value`, `touch` (`last` or `first`) | The campaign parameter matches. `last` = current request, else the last stored campaign; `first` = first recorded campaign |
| `edxp_cookie` | Cookie | `name`, `mode`, `value` | The request cookie matches |
| `edxp_time_window` | Time window | `days` (1 = Mon … 7 = Sun; empty = every day), `from`, `to` (`HH:MM`), `timezone` | Current time is inside the window. `22:00–06:00` spans midnight |
| `edxp_returning_visitor` | Returning visitor | `minSessions` (default 2), `inverse` | Visitor has at least N sessions (inverse: fewer, i.e. new visitors) |

String modes: `equals` (case-insensitive), `contains`, `starts_with`, `regex` (case-insensitive), `exists`.

Sessions come from `edxp_visitor_profile`. A new session starts after 30 minutes of inactivity. The current request counts.

Example rule: visitors from the spring newsletter see the seasonal hero.

- Condition: `edxp_utm`, parameter `utm_campaign`, mode `equals`, value `spring-2026`, touch `last`.
- Action: assign target group "Spring campaign" (weight 5).

## Rule action: Set experiment variant

`edxp_set_variant` with `experiment` and `variant` pins the visitor to a variant of a running experiment. Use it, for example, to make paid-campaign landing traffic always see variant B. The pin is stored as a sticky assignment with `forced = 1`, replaces an earlier assignment, and swaps the variant target group.

Forced visitors are included in the report and shown in the `forced` column. Prefer an audience target group (to restrict who enters) over forcing (which removes randomization).

## Analytics forwarding

Every recorded event (exposures included) can be forwarded to one analytics tool. Forwarding is asynchronous on the `elevate_dxp` Messenger transport and best effort: errors never affect tracking.

```yaml
elevate_dxp:
    experiments:
        analytics:
            adapter: posthog            # none | matomo | posthog
            posthog:
                host: 'https://eu.i.posthog.com'
                api_key: '%env(POSTHOG_PROJECT_API_KEY)%'
```

| Adapter | Request | Mapping |
|---|---|---|
| `matomo` | `POST <url>/matomo.php` (HTTP Tracking API) | Event category `edxp`, action = event name, name = `exp:variant,...`, value. `_id`/`cid` = first 16 hex chars of the visitor id. With `token`, the original timestamp is sent as `cdt` |
| `posthog` | `POST <host>/capture/` | `distinct_id` = visitor id. Properties `$current_url`, `edxp_type`, `value`, `meta` keys, and `$feature/<experiment>` = variant |

Run a worker in production (`bin/console messenger:consume elevate_dxp`). With the default `sync://` transport, forwarding runs during the tracking request.

## Privacy controls

Summary (details in [security-and-privacy.md](security-and-privacy.md)):

- The visitor id is random, HMAC-signed and stored in an `HttpOnly`, `SameSite=Lax` cookie (`Secure` on HTTPS). No personal data is stored.
- `visitor.consent_cookie`: no visitor id is issued, and nothing is assigned or tracked, until that cookie exists.
- Requests are not trackable when the path matches `visitor.excluded_paths` (default `^/admin`, `^/_`, `^/bundles/`, `^/elevate-dxp/`) or the user agent looks like a bot (`bot`, `crawl`, `spider`, `slurp`, `preview`, `headless`, `lighthouse`, `monitor`, `curl/`, `wget`).
- Responses for visitors in an experiment get `Cache-Control: private` and `X-Edxp-Experiments: <count>`.

### Caching

Personalised responses must not be served from a shared cache. Elevate DXP marks them `private`, but a full-page cache that keys only on the URL could still serve variant A to a variant B visitor. If you use OpenDXP's full-page cache or a reverse proxy (Varnish, CDN), exclude pages that run experiments, or vary the cache on the `edxp_vid` cookie, and verify with the `X-Edxp-Experiments` header.

## CLI

| Command | Purpose |
|---|---|
| `elevate-dxp:experiments:demo-seed` | Demo audience "VIP visitors" (`?vip=1` rule) and running experiment `hero` (A/B, goal `signup`) with variant target groups. Idempotent |
| `elevate-dxp:experiments:report <key>` | Per-variant results table |
| `elevate-dxp:experiments:distribution <key> [--visitors=10000]` | Simulates the weighted split (no writes, ignores the traffic gate) |
| `elevate-dxp:experiments:prune [--events-days=395] [--profiles-days=395] [--chunk=1000] [--dry-run]` | Data retention: deletes old events, visitor profiles not seen for `--profiles-days`, and the assignments of those visitors without recent events. Chunked and idempotent. See [insights.md](insights.md#retention) |

## Admin resources

| Resource | Key | Type |
|---|---|---|
| A/B Experiments | `experiments` | CRUD with the actions above |
| Experiment Results | `experiment-results` | Report with experiment filter and conversion-rate chart |
| Tracking Events | `tracking-events` | Read-only event log, searchable by event, visitor, experiment, URL |

Visitor profiles and segments are in the [Insights](insights.md) module.

## Configuration

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
            matomo: { url: '', site_id: '1', token: '' }
            posthog: { host: 'https://eu.i.posthog.com', api_key: '' }
```

Option reference: [configuration.md](configuration.md#experiments).

## Troubleshooting

| Symptom | Check |
|---|---|
| `edxp_variant()` is always `null` | Status `running`, schedule, URL scope, audience; the request is not a bot and not excluded; consent cookie present if configured; `elevate_dxp.experiments.enabled` |
| Variant content is not shown in documents | Targeting enabled; *Create variant target groups* was run; content was edited for the `exp:<key>:<variant>` group |
| No conversions | The runtime is injected (look for `edxp-runtime.js` in the HTML); the event name equals the goal event (lower case); `allowed_events` contains it; browser devtools show `204` from `/_edxp/track` |
| `403 cross_origin` | The page and the tracking endpoint are on different hosts |
| Every visitor gets the same variant | Shared cache in front of the site (see [Caching](#caching)) |

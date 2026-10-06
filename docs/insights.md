# Insights

Three admin tools built on data the site already has:

- **Visitor profiles (CDP-lite):** first-party profiles collected by the [experiments](experiments.md) module, rule-based segments, per-visitor timelines and GDPR erasure.
- **Data quality:** completeness reports for data object classes.
- **Copilot:** an editor assistant that calls the Anthropic Messages API, optionally grounded on a data object or page.

Config key: `elevate_dxp.insights` · Permissions: `elevate_dxp_cdp`, `elevate_dxp_data_quality`, `elevate_dxp_copilot` · Admin menu: Elevate DXP → Insights (profiles, data quality), Elevate DXP → Content (copilot)

## Visitor profiles

### Where the data comes from

The experiments module writes the profile in the response listener of every trackable frontend HTML request that returns a 2xx status, when `elevate_dxp.experiments.profile.enabled` is true (the default). The insights module only reads it; it creates no tables.

`edxp_visitor_profile`:

| Column | Content |
|---|---|
| `visitor_id` | Random id from the signed `edxp_vid` cookie |
| `targeting_visitor_id` | The personalization bundle's visitor id, when targeting is on |
| `first_seen`, `last_seen` | Timestamps |
| `pageviews` | Count of tracked HTML page views |
| `sessions` | Incremented when more than 30 minutes passed since `last_seen` |
| `first_url`, `last_url`, `referrer` | Truncated URLs |
| `utm_first`, `utm_last` | JSON: `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content` (values cut to 150 characters) |
| `target_groups` | JSON: names of the target groups assigned on the last request |

No name, e-mail, IP address or user agent is stored. URLs and UTM values are stored as received: do not put personal data in URLs or campaign parameters.

### Admin: Visitor Profiles (CDP)

Resource `visitor-profiles`, permission `elevate_dxp_cdp`. A read-only grid sorted by `last_seen`, searchable by visitor id, last URL, last campaign and target groups. Each row is enriched with:

- `conversions`: events of type `conversion`;
- `experiments`: number of experiment assignments;
- `segments`: matching segments (see below).

Actions:

| Action | Scope | Result |
|---|---|---|
| Timeline | record | The visitor's events (up to 200, newest first) merged with their experiment assignments (`assignment` or `forced`) |
| Segments overview | global | Visitors per segment and share of all profiles |
| Delete | record | **Erasure**: deletes the visitor's rows from `edxp_visitor_profile`, `edxp_event` and `edxp_assignment` in one transaction |

### Segments

Segments are fixed rules evaluated by `SegmentEvaluator`:

| Segment | Rule |
|---|---|
| `converters` | At least one conversion event |
| `engaged` | 5 or more page views |
| `returning` | 2 or more sessions |
| `campaign` | A last-touch UTM campaign is stored |
| `experiment_exposed` | Assigned to at least one experiment |

Segments are for analysis in the admin. To *act* on similar criteria on the website, use targeting rules with the `edxp_returning_visitor`, `edxp_utm` and `edxp_experiment_variant` conditions (see [experiments.md](experiments.md#targeting-conditions)), which assign OpenDXP target groups.

### GDPR requests

Visitors are identified only by the random id in their `edxp_vid` cookie. To handle an access or erasure request:

1. Ask the person for the cookie value (browser devtools → Storage → Cookies → `edxp_vid`). The value has the form `<id>.<signature>`; the id is the part before the last dot.
2. Search for the id in *Visitor Profiles*. Use *Timeline* for an access request.
3. Use *Delete* for an erasure request.

Data you forwarded to Matomo or PostHog must be erased there as well. The OpenDXP Application Logger may also contain audit entries; see [security-and-privacy.md](security-and-privacy.md).

### Retention

There is no automatic expiry. Schedule `elevate-dxp:experiments:prune` with retention periods that match your privacy policy, for example as a daily cron job:

```
bin/console elevate-dxp:experiments:prune [--events-days=395] [--profiles-days=395] [--chunk=1000] [--dry-run]
```

- `edxp_event` rows older than `--events-days` are deleted.
- `edxp_visitor_profile` rows not seen for `--profiles-days` are deleted, together with the `edxp_assignment` rows of those visitors that have no event newer than the more recent of the two cut-offs (a visitor still generating events keeps its variant).
- Assignments of visitors that never had a profile (`profile_enabled: false`) are not touched.
- Deletes run in chunks of `--chunk` rows, so the tables are never locked for long. The command is idempotent; `--dry-run` only counts.

Deleting assignments and events changes the results of past experiments. Export the reports you want to keep first.

## Data quality

Completeness of data objects: for each configured class, how many objects have each required field filled.

```yaml
elevate_dxp:
    insights:
        data_quality:
            sample_limit: 500
            profiles:
                Product: [sku, name, description, price, image]
                Recipe: [name, ingredients, allergens, nutritionTable]
```

- Keys are data object class names (`OpenDxp\Model\DataObject\<Class>`). Values are field names; the value is read with `get<Field>()`.
- A value counts as missing when it is `null`, an empty string or an empty array. Any object (relation, image, quantity value, ...) counts as filled.
- Unpublished objects are included. At most `sample_limit` objects are read per report; with more objects, the report covers a sample, not the whole class.
- An unknown class returns an empty report.

Admin resource `data-quality`, permission `elevate_dxp_data_quality`: a report panel with a class filter. The first row is the average completeness; the following rows show filled count and completeness % per field, with a chart. The global action *Least complete records* lists up to 20 objects with missing fields (id, key, missing fields).

## Copilot

An assistant for editors in *Elevate DXP → Content → Copilot* (resource `copilot`, permission `elevate_dxp_copilot`). It is disabled until an API key is set.

```dotenv
# .env.local
ANTHROPIC_API_KEY=sk-ant-...
```

```yaml
elevate_dxp:
    insights:
        copilot:
            api_key: '%env(string:default::ANTHROPIC_API_KEY)%'   # default
            model: claude-opus-5-5                                 # default
            max_tokens: 1024
```

### Presets

| Preset | Instructions (system prompt) |
|---|---|
| `product_description` | Concise, factual product description (80–120 words) from the attributes, no invented specifications |
| `seo_metadata` | `title` (max 60 characters) and `description` (max 155 characters) as JSON |
| `summary` | One neutral paragraph |
| `ab_variant` | 5 alternative headlines to A/B test, each with a one-line hypothesis |
| `free` | No system prompt |

Select a preset and run *Run preset*. Parameters:

- **Data object or page** (optional): drop an element from the tree. For a data object, the class name, path and every non-empty field are sent (relations reduced to paths, dates as `Y-m-d`). For a page or snippet, its scalar editables are sent with HTML tags stripped. The context is cut at 8000 characters.
- **Prompt** (optional, max 4000 characters).

At least one of the two is required. The answer is shown as text to copy. Nothing is written back to the element.

*Status* reports whether the copilot is configured and which model it uses. The API key is never sent to the browser.

### Data protection

The selected element's content is sent to `https://api.anthropic.com/v1/messages`. Do not use the copilot on elements that contain personal or confidential data unless your agreement with Anthropic and your policies allow it. Restrict the `elevate_dxp_copilot` permission accordingly. Requests time out after 90 seconds; errors are shown as messages.

## Configuration reference

```yaml
elevate_dxp:
    insights:
        data_quality:
            sample_limit: 500       # min 1
            profiles: {}            # Class: [field, ...]
        copilot:
            api_key: '%env(string:default::ANTHROPIC_API_KEY)%'
            model: claude-opus-5-5
            max_tokens: 1024        # min 1
```

The insights module has no `enabled` flag. Hide its tools by not granting the permissions. Profiles are collected by the experiments module; turn collection off with `elevate_dxp.experiments.profile.enabled: false`.

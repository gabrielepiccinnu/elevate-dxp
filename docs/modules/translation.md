# Translation

An XLIFF 1.2 service with pluggable machine-translation providers. Two providers ship: `pseudo` (offline, deterministic) and `libretranslate` (self-hosted LibreTranslate). Admin tools translate text, build XLIFF files and auto-fill the empty targets of an XLIFF file, for example a native OpenDXP translations export.

Config key: `elevate_dxp.translation` · Permission: `elevate_dxp_translation` · Admin menu: Elevate DXP → Content

See also: [Configuration](../configuration.md), [Architecture](../architecture.md), [Security and privacy](../security-and-privacy.md).

The bundle installer (`bin/console opendxp:bundle:install ElevateDxpBundle`) registers the permission; the module creates no tables.

## Configuration

Defaults shown:

```yaml
elevate_dxp:
    translation:
        enabled: true                      # currently not read by any service
        provider: pseudo                   # default provider key
        languages: [en, it, de, fr, es, pt, nl]   # offered in the admin dialogs; first two are the default pair
        libretranslate:
            url: 'http://libretranslate:5000'     # must start with http:// or https://
            api_key: ''                           # e.g. '%env(LIBRETRANSLATE_API_KEY)%'
            timeout: 10                           # seconds, min 1
```

If `provider` names an unknown key, the default falls back to `pseudo`. Providers requested explicitly in the admin or with `fill-xliff --provider` must exist.

## Providers

- `pseudo` returns `[<TO>] <text>` (for example `[DE] Hello world`); useful to test the XLIFF round-trip without a service.
- `libretranslate` sends `POST <url>/translate` with `q`, `source`, `target`, `format: text` and, when set, `api_key`, using `symfony/http-client` (the `http_client` service when available, otherwise `HttpClient::create()`), with no redirects followed. On any error it returns the source text unchanged; the admin shows the error.

Text sent to LibreTranslate leaves OpenDXP; point `url` to an instance you control.

Custom provider: implement `ElevateDxp\Translation\Provider\TranslationProviderInterface` (`name()`, `translate($text, $from, $to)`) and tag the service explicitly (there is no autoconfiguration):

```yaml
App\Translation\MyProvider:
    tags: [{ name: elevate_dxp_translation.provider, key: my_provider }]
```

## Admin resource `translation_providers`

Read-only list of providers ("Translation") with default flag, endpoint and whether an API key is set; the key itself is never shown.

| Action | Scope | Params | What it does |
|---|---|---|---|
| `test` | record | none | translates "Hello world" en → de with the selected provider; fails with the provider error, if any |
| `translate` | global | `provider`, `from`, `to`, `text` (max 5000 chars) | translates the text |
| `xliff_build` | global | `from`, `to`, `units` (key = trans-unit id, value = source text; max 500) | builds an XLIFF 1.2 file (sources only, no `<target>` elements) |
| `xliff_fill` | global | `provider`, `from`, `to`, `xliff` (max 2,000,000 bytes, max 500 trans-units) | fills missing or empty `<target>` elements; `source-language`/`target-language` on each `<file>` take precedence over the dialog values |

Language codes must look like `en`, `pt-BR` or `zh_Hans`. XLIFF documents containing a `DOCTYPE` are rejected and parsing uses `LIBXML_NONET`.

## CLI

| Command | Description |
|---|---|
| `elevate-dxp:translation:demo [--from=en] [--to=de] [--provider=]` | XLIFF export → auto-fill → import round-trip on three sample units; an unknown provider falls back to the default |
| `elevate-dxp:translation:fill-xliff <input> [<output>] [--provider=] [--from=en] [--to=de]` | fills the empty targets of an XLIFF file (stdout when no output); `--from`/`--to` apply only when a `<file>` declares no languages |

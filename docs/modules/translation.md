# Elevate DXP Translation Bundle

An XLIFF 1.2 service with pluggable machine-translation providers (Pseudo, LibreTranslate). Ported from `OpenPimcore\TranslationBundle`. GPL-3.0-or-later.

## Configuration

```yaml
elevate_dxp_translation:
    provider: pseudo                 # default provider key; an unknown key falls back to pseudo
    languages: [en, it, de, fr, es, pt, nl]
    libretranslate:
        url: 'http://libretranslate:5000'   # http(s) only
        api_key: '%env(default::LIBRETRANSLATE_API_KEY)%'
        timeout: 10
```

LibreTranslate needs `symfony/http-client`, which uses the `http_client` service when it exists. On any error it returns the source text, so nothing is lost.

To add a custom provider, implement `ElevateDxp\Translation\Provider\TranslationProviderInterface` and tag it `{ name: elevate_dxp_translation.provider, key: <name> }`.

## CLI

- `elevate-dxp:translation:demo [--from --to --provider]` runs an XLIFF round-trip.
- `elevate-dxp:translation:fill-xliff <in> [out] [--provider --from --to]` fills the empty `<target>`s of an XLIFF file, for example an export from the native **XliffBundle** (Translations → Export).

## Admin resource `translation_providers`

This resource needs the `elevate_dxp_translation` permission. It appears in the Content menu group as a read-only list of providers. API keys are never shown.

| Action | Scope | Params | What it does |
|---|---|---|---|
| `test` | record | none | Translates "Hello world" with the selected provider. |
| `translate` | global | `provider`, `from`, `to`, `text` (max 5000 chars) | Translates the text. |
| `xliff_build` | global | `from`, `to`, `units` (key = source text, max 500) | Builds an XLIFF 1.2 file. |
| `xliff_fill` | global | `provider`, `from`, `to`, `xliff` (max 2 MB, max 500 units) | Auto-translates an XLIFF file. The languages declared on each `<file>` take precedence over the dialog values. DTDs are rejected. |

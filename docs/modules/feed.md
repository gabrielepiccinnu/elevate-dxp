# Elevate DXP Feed Bundle

`elevate-dxp/feed-bundle` (`ElevateDxp\Feed`) manages YAML-defined product feeds on top of `elevate-dxp/export-bundle`. It provides two templates: a generic CSV feed and a Google Merchant RSS 2.0 feed. It also adds required-field validation, preview and export commands, admin actions, and a tokenized public URL. It is ported from the legacy `OpenPimcore\FeedBundle`. License: GPL-3.0-or-later.

## Configuration

```yaml
elevate_dxp_feed:
    enabled: true
    chunk_size: 100
    feeds:
        google_shopping:                       # name: [A-Za-z0-9_-]+
            source: { type: data_object, class: Product }   # data_object | asset
            template: google_merchant          # generic_csv | google_merchant
            currency: EUR
            link_pattern: 'https://shop.example.com/p/{id}' # optional, {field} placeholders
            mappings: { id: sku, title: name, description: description, image_link: imageUrl, price: price, availability: availability }
            static: { condition: new, brand: ACME }        # fills empty/missing fields only
            channel: { title: Shop, link: 'https://shop.example.com', description: Products }
            target: { type: local, path: feeds/google.xml } # export targets: local | asset | http (default path: <name>.<ext>)
            token: '%env(FEED_GOOGLE_TOKEN)%'  # enables the public URL (min 16 chars)
            cache_ttl: 300                     # seconds the public URL serves a cached rendering (0 = off)
```

- The required fields for `generic_csv` are `id` and `title`.
- The required fields for `google_merchant` are `id`, `title`, `description`, `link`, `image_link`, `availability` and `price`.
- In `google_merchant`, `price` and `sale_price` are formatted as `12.99 EUR`. `title`, `link` and `description` have no prefix. Every other field is emitted as `g:<field>`.

## Public URL

`GET /elevate-dxp/feed/{name}.{format}?token=...`: the format is `csv` or `xml` and must match the template. The token can also be sent in the `X-Elevate-Dxp-Feed-Token` header.

- **Deny-by-default:**
  - A feed with no token, or a token shorter than 16 characters, is never served.
  - The token is compared with `hash_equals`.
  - Every denial returns the same `404 Not found.`, whatever the cause (unknown feed, missing token, wrong token, wrong format or disabled bundle), so feed names cannot be enumerated.
  - No data is read for a denied request.
- **Response headers:** a successful response carries `Cache-Control: private, max-age=0`, `X-Robots-Tag: noindex, nofollow` and `Referrer-Policy: no-referrer`. Rendering errors return a plain-text `503`.
- **Audit:** every access is audited as `feed.<name>.public` with status `ok`, `denied` (plus a reason) or `error`. The actor is the first 12 hex characters of the token's sha256, never the token itself.
- **Caching:** rendered output is cached in `cache.app` for `cache_ttl` seconds.
- **Routing:** the route is declared in `config/opendxp/routing.yaml` with attribute routes (`elevate_dxp_feed_public`). It sits outside `/admin`, so no firewall applies.

## CLI

```
bin/console elevate-dxp:feed:preview <feed> [--limit=10]   # rows + validation issues
bin/console elevate-dxp:feed:export <feed>                 # render and write to the target
```

## Admin

The resource `feeds` (group *Marketing*, permission `elevate_dxp_feed`) is a read-only list of feeds. The list shows whether a public URL exists and its path; the token is never displayed. It has three actions:

- **Validate:** returns a table of the rows with missing required fields, or a success message.
- **Preview:** takes the parameters `limit` (at most 200) and `as` (`table` or `rendered`). The table has a `_missing` column per row.
- **Export:** asks for confirmation, then writes the feed to its target and returns a message.

## Porting notes

- `GenericCsvTemplate` delegates to the export bundle's `CsvRenderer`, so the formula-injection guard is shared instead of duplicated.
- `FeedTemplateInterface` gained `extension()` and `contentType()`, which the public URL needs.
- `FeedRunner` takes `SourceReaderInterface`, passes `limit` down to the listing for previews, and gained `render()` (in memory) and `describe()`.
- Feed targets now also accept `http`, because the export targets are shared.
- The public tokenized URL is new and the Studio API controller was removed.

## Web server note

The skeleton nginx config serves URLs ending in `.csv`/`.xml`-like extensions as static files. Exclude the Elevate DXP prefix so the public feed URL reaches PHP:

```nginx
location ~* ^(?!/admin|/asset/webdav|/studio/api|/elevate-dxp/)(.+?)\.((?:css|js)(?:\.map)?|...|csv|...)$ {
```

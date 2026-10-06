# Elevate DXP Export Bundle

`elevate-dxp/export-bundle` (`ElevateDxp\Export`) runs YAML-defined exports of OpenDXP data objects and assets. It renders them as CSV, JSON or XML and writes them to a local file, an OpenDXP asset or an HTTP endpoint. It is ported from the legacy `OpenPimcore\ExportBundle`. License: GPL-3.0-or-later.

## Configuration

```yaml
elevate_dxp_export:
    enabled: true
    base_path: var/elevate-dxp/exports   # allow-listed base dir for "local" targets (relative to project dir, or absolute)
    chunk_size: 100                   # listing page size
    lock_dir: ~                       # lock files dir (default: system temp dir)
    jobs:
        products_csv:                 # name: [A-Za-z0-9_-]+
            description: Published products
            source:
                type: data_object     # data_object | asset
                class: Product        # data object class (type data_object)
                fields: { id: id, sku: sku, name: name, modified: modificationDate }   # output column => getter
            format: csv               # csv | json | xml
            target:
                type: local           # local | asset | http
                path: products.csv    # file under base_path / asset path / http(s) URL
```

Fields are mapped explicitly by the core `FieldMapperInterface` (`ReflectiveFieldMapper`). Only published objects of the configured class are read, through `OpenDxp\Model\DataObject\<Class>\Listing`. For assets, every asset except folders is read.

## Security properties

- **PathGuard:** local targets are confined to `base_path`. Paths containing `..`, NUL bytes, or an empty file name are rejected. Files are written atomically (a temp file, then a rename).
- **CSV formula-injection guard:** any cell, header included, that starts with `= + - @ \t \r` is prefixed with `'`. See `CsvRenderer::sanitize()`, which the feed bundle reuses.
- **XML escaping:** XML is built with DOM. Element names are sanitised, and names that start with `xml` or a digit get the prefix `f_`.
- **Lock:** a job holds an exclusive non-blocking `flock`, so a concurrent run of the same job fails with "already running".
- **Audit:** every run logs `export.<job>` with `start`, then `success` or `failure`. Logging goes through `ElevateDxp\Core\Contract\AuditLoggerInterface`, and the actor is `cli` or `admin:<user>`.
- **HTTP target:** sends a POST via the Guzzle client registered by OpenDXP. Redirects are not followed, timeouts are 15 s total and 5 s to connect, and any non-2xx status counts as a failure. The returned location shows only the scheme and host, never the query string.

## CLI

```
bin/console elevate-dxp:export:run <job>
bin/console elevate-dxp:export:run --list
```

## Admin

The resource `exports` (group *Data*, permission `elevate_dxp_export`) lists the configured jobs read-only. It has two record actions:

- **Run export** (asks for confirmation) returns a message with the row count and location.
- **Preview** takes the parameters `limit` (at most 200) and `as` (`table` or `rendered`). It reads the first rows without writing or locking, and returns either a table or the rendered text.

## Services and extension points

- Renderers are tagged `elevate_dxp_export.renderer` with `key: csv|json|xml`.
- Targets are tagged `elevate_dxp_export.target` with `key: local|asset|http`. The feed bundle uses the same tag.
- `ElevateDxp\Export\Contract\SourceReaderInterface` is an alias of `Source\SourceReader`, and the feed bundle reuses it.
- `Runner\ExportRunner` provides `listJobs()`, `preview()` and `run()`.

## Porting notes

- The Studio API controller (`/pimcore-studio/api/openpimcore/export/...`) is replaced by the admin resource.
- The HTTP target uses `GuzzleHttp\ClientInterface`, because `symfony/http-client` is not part of the OpenDXP stack.
- `AssetTarget` creates new assets with `Asset::create()`, which picks the right asset type. Existing assets are updated in place.
- The source reader has an optional `$limit` (used by preview), stable ordering by id, and an explicit error for an unknown or invalid class name. The legacy reader silently returned no rows.

# Export

Runs YAML-defined export jobs over OpenDXP data objects or assets. Each job renders its rows as CSV, JSON or XML and writes them to a local file, an OpenDXP asset or an HTTP endpoint. The [feed](feed.md) module reuses its source reader, CSV sanitiser and targets.

Config key: `elevate_dxp.export` · Permission: `elevate_dxp_export` · Admin menu: Elevate DXP → Data

Installed with the bundle; no tables (permission only).

## Configuration

```yaml
elevate_dxp:
    export:
        enabled: true                       # false: runs are refused (preview still works)
        base_path: var/elevate-dxp/exports  # base dir for "local" targets, relative to the project dir or absolute
        chunk_size: 100                     # listing page size (min 1)
        lock_dir: ~                         # lock file dir; null = system temp dir
        jobs:
            products_csv:                   # name: [A-Za-z0-9_-]+
                description: Published products
                source:
                    type: data_object       # data_object | asset (required)
                    class: Product          # data object class (type data_object)
                    fields: { id: id, sku: sku, name: name, modified: modificationDate }   # output column => accessor
                format: csv                 # csv | json | xml (required)
                target:
                    type: local             # local | asset | http (required)
                    path: products.csv      # file under base_path, asset path, or http(s) URL (required)
```

## Source

- **data_object:** published objects of `class`, read through `OpenDxp\Model\DataObject\<Class>\Listing` and ordered by id. An invalid or unknown class name fails the run with `Unknown data object class "<name>"`. The name is not checked at container build.
- **asset:** all non-folder assets, ordered by id.

Rows are read in pages of `chunk_size`, but the whole result is held in memory and rendered at once. Fields are mapped by the core `FieldMapperInterface` (`ReflectiveFieldMapper`). For each accessor it calls `get<Accessor>()`, `is<Accessor>()` or `has<Accessor>()`; no other method is ever invoked. Values are scalarised: dates become ATOM strings, elements become their full path.

## Formats

- **csv:** the header comes from the keys of the first row. Formula-injection guard: any cell (header included) that starts with `=`, `+`, `-`, `@`, tab or CR is prefixed with `'` (`CsvRenderer::sanitize()`). An empty result gives an empty file.
- **json:** a pretty-printed array of row objects.
- **xml:** `<items><item><field>value</field>...</item></items>`, built with DOM so values are escaped. In element names, characters outside `[A-Za-z0-9_.-]` become `_`, and names that do not start with a letter or `_`, or that start with `xml`, get the prefix `f_`.

## Targets

- **local:** written under `base_path` (`PathGuard`). Paths containing `..` or NUL, an empty path, or a path that resolves outside the base are rejected. Directories are created as needed. The write is atomic (temp file, then rename).
- **asset:** creates the asset at `path` (parent folders included, type chosen by `Asset::create()`) or updates an existing one in place. It fails if the path is a folder or contains `..`.
- **http:** `POST` of the raw content (`Content-Type: application/octet-stream`) to the absolute http(s) URL in `path`.
  - Uses the OpenDXP Guzzle client (`GuzzleHttp\ClientInterface`).
  - Timeouts: 15 s total, 5 s connect. Redirects are not followed. Any non-2xx status is a failure.
  - The reported location contains only the scheme and host.

## Runs

- A run takes an exclusive non-blocking `flock` on `<lock_dir>/edxp_export_<job>.lock`. A concurrent run of the same job fails with "already running".
- Every run is audited as `export.<job>` with `start`, then `success` (rows, location) or `failure` (error). The actor is `cli` or `admin:<user>`.
- Preview reads the first rows and renders them without writing, locking or auditing.

## Admin

**Exports** (`exports`) is a read-only grid of the configured jobs (format, source, class, target, field mapping). Record actions:

- **Run export** (asks for confirmation): runs the job and reports the row count and location.
- **Preview**: parameters `limit` (default 20, max 200) and `as` (`table` or `rendered`).

## CLI

```
bin/console elevate-dxp:export:run <job>
bin/console elevate-dxp:export:run --list     # or -l
```

Without a job name, the command prints the job list and exits with `INVALID`. Schedule runs with cron or the scheduler of your choice.

## Extension points

- Renderers implement `ElevateDxp\Export\Contract\ExportRendererInterface` and are tagged `elevate_dxp_export.renderer` with `key: <format>`.
- Targets implement `ExportTargetInterface` and are tagged `elevate_dxp_export.target` with `key: <type>`. The feed module uses the same locator.
- `ElevateDxp\Export\Contract\SourceReaderInterface` is an alias of `Source\SourceReader`.
- `Runner\ExportRunner` provides `listJobs()`, `preview()` and `run()`.

The `format` and `target.type` values are validated against fixed enums in the configuration, so a new renderer or target key also needs a configuration change. See [../architecture.md](../architecture.md).

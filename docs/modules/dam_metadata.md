# DAM metadata

Typed asset-metadata schemas defined in YAML, built on native OpenDXP predefined asset metadata (`OpenDxp\Model\Metadata\Predefined`). The module creates the predefined definitions, validates values, and applies them in bulk to the assets of a folder. Values are stored as native asset metadata.

Config key: `elevate_dxp.dam_metadata` · Permission: `elevate_dxp_dam_metadata` · Admin menu: Elevate DXP → Content

See also: [Configuration](../configuration.md), [Architecture](../architecture.md).

The bundle installer (`bin/console opendxp:bundle:install ElevateDxpBundle`) registers the permission; the module creates no tables.

## Configuration

```yaml
elevate_dxp:
    dam_metadata:
        enabled: true          # currently not read by any service
        batch_size: 200        # assets loaded per batch during bulk apply (min 1)
        schemas:               # default: none
            product_assets:
                label: 'Product assets'        # default: '' (the schema name is shown)
                path_prefix: /products         # default '/'; plain string prefix of the asset full path
                asset_types: [image]           # default [] = any type
                fields:
                    - { name: copyright, type: input, default: '(c) ACME' }
                    - { name: usage_rights, type: select, options: [web, print, all] }
                    - { name: reviewed, type: checkbox, default: 'false' }
                    - { name: priority, type: number }   # stored as native "input"
                    - { name: expires, type: date }      # stored as a Unix timestamp
```

Field keys: `name` (required), `type` (`input`, `textarea`, `select`, `checkbox`, `number`, `date`; default `input`), `label`, `options` (for `select`), `default`, `description`.

`path_prefix` is a plain prefix match: `/products` also matches `/products-internal/...`. Use a trailing slash (`/products/`) to restrict to the folder. Folders never match.

Validation: `number` must be numeric; `checkbox` accepts a boolean or `0`, `1`, `true`, `false`; `date` accepts a timestamp or anything `strtotime()` parses; `select` must be one of `options`. `textarea` maps to native `textarea`, `number` to native `input`.

## Predefined metadata sync

Sync creates one native predefined definition per field and target asset type (one without a target subtype when `asset_types` is empty). The group is the schema label; `select` options become the comma-separated config. It is idempotent: a definition with the same name and either no subtype or the same subtype counts as existing, and existing definitions are never modified. Each sync is audited as `dam.metadata.sync_predefined`.

## Bulk apply

`apply_folder` (admin) and `apply-folder` (CLI) scan the folder recursively in batches of `batch_size` and only touch assets that match the schema:

- With `field` and `value`: the value is validated first, then set; existing values are kept unless `overwrite` is set.
- Without `field`: every field that has a `default` and is missing on the asset is initialised (all of them with `overwrite`).
- From the admin, the native `save` permission is checked per asset (denied assets are counted). Failures are counted and the run continues.
- The result reports matched, updated, unchanged, denied and failed assets. Each run is audited as `dam.metadata.bulk_apply`.

## Admin resource `dam_metadata_schemas`

Read-only grid of the configured schemas ("DAM metadata schemas") with a "Predefined synced" column (`existing/total` definitions).

| Action | Scope | What it does | Extra check |
|---|---|---|---|
| Show fields (`fields`) | record | fields of the schema with native type, options and default | none |
| Sync predefined metadata (`sync_predefined`) | global | creates missing predefined definitions; optional param `schema` (empty = all) | native `asset_metadata` permission (or admin) |
| Apply to folder (`apply_folder`) | global | params: `folder` (asset folder), `schema`, optional `field` and `value`, `overwrite` | native `assets` permission (or admin), plus `save` per asset |
| Native predefined metadata (`predefined`) | global | lists all native predefined definitions | none |
| Show asset metadata (`show_asset`) | global | metadata of one asset (id or path) | native `view` permission on the asset |

## CLI

`bin/console elevate-dxp:dam:metadata <action>`:

| Action | Options | Description |
|---|---|---|
| `schemas` | | list schemas and fields |
| `sync` | `[--schema=]` | create the native predefined definitions |
| `apply` | `--schema [--field --value] [--overwrite]` | apply to every asset matching the schema (scans from `path_prefix`). With `--field`/`--value` the field is set, overwriting. Without `--field` only missing fields are initialised with their defaults; existing values are replaced only with `--overwrite` |
| `apply-folder` | `--folder --schema [--field --value] [--overwrite]` | bulk apply as described above; `--folder` defaults to `/` |
| `show` | `--asset=<id>` | show an asset's metadata |

The CLI does not check user permissions. `apply --schema=<name>` without `--field` never overwrites existing values unless `--overwrite` is given (same default as the admin *Apply to folder* action); the result reports updated and unchanged assets.

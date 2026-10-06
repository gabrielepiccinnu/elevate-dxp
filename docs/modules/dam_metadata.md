# Elevate DXP DAM Metadata Bundle

Typed asset-metadata schemas built on the native OpenDXP predefined asset metadata (`OpenDxp\Model\Metadata\Predefined`). The bundle validates values and applies them in bulk to an asset folder. Ported from `OpenPimcore\DamMetadataBundle`. GPL-3.0-or-later.

## Configuration

```yaml
elevate_dxp_dam_metadata:
    batch_size: 200
    schemas:
        product_assets:
            label: 'Product assets'
            path_prefix: /products        # only assets below this path
            asset_types: [image]          # empty = any type
            fields:
                - { name: copyright, type: input, default: '(c) ACME' }
                - { name: usage_rights, type: select, options: [web, print, all] }
                - { name: reviewed, type: checkbox, default: 'false' }
                - { name: priority, type: number }   # stored as native "input"
                - { name: expires, type: date }      # stored as timestamp
```

## CLI

`elevate-dxp:dam:metadata <action>` supports these actions:

- `schemas`: list the schemas.
- `sync [--schema=]`: create the native predefined definitions.
- `apply --schema --field --value`: the legacy action. It sets one field on every asset that matches the schema.
- `apply-folder --folder --schema [--field --value] [--overwrite]`: apply to the assets in a folder.
- `show --asset=<id>`: show an asset's metadata.

## Admin resource `dam_metadata_schemas`

This resource needs the `elevate_dxp_dam_metadata` permission. It appears in the Content menu group as a read-only grid of schemas, with a column showing how many predefined definitions are synced.

| Action | Scope | What it does | Extra permission |
|---|---|---|---|
| `fields` | record | Shows the fields of the selected schema. | none |
| `sync_predefined` | global | Creates the native predefined definitions. Optional param: `schema`. It is idempotent, and one definition is created per field and target asset type. | `asset_metadata` |
| `apply_folder` | global | Applies to a folder. See the parameters below. | `assets` |
| `predefined` | global | Lists the native predefined metadata. | none |
| `show_asset` | global | Shows the metadata of one asset. | none |

`apply_folder` takes these params:

- `folder` (asset path) and `schema`;
- `field` and `value`, both optional;
- `overwrite`.

It works on the folder recursively and only touches assets that match the schema. It checks the native `save` permission on each asset. Without a `field`, it initializes the missing fields that have a `default`. Values are validated before any asset is touched.

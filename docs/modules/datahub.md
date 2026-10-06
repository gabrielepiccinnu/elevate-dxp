# Datahub

Exposes YAML-declared, read-only REST endpoints for OpenDXP data objects and assets, protected by a static API key. In the admin it also lists the native OpenDXP DataHub GraphQL configurations (`open-dxp/data-hub-bundle`) when that bundle is installed.

Config key: `elevate_dxp.datahub` · Permission: `elevate_dxp_datahub` · Admin menu: Elevate DXP → Integration

Installed with the bundle; no tables (permission only).

## Configuration

```yaml
elevate_dxp:
    datahub:
        enabled: true                         # false: every public request answers 404 {"error":"disabled"}
        api_key_header: X-Elevate-Dxp-Api-Key
        api_key: '%env(ELEVATE_DXP_DATAHUB_API_KEY)%'   # default ''; empty = every request denied
        max_limit: 100                        # upper bound for ?limit= (min 1)
        endpoints:
            products:                         # name: [A-Za-z0-9_-]+, served at /elevate-dxp/api/products
                type: data_object             # data_object | asset (required)
                class: Product                # data object class (type data_object)
                fields: { id: id, sku: sku, name: name, modified: modificationDate }   # output key => accessor
            media:
                type: asset
                fields: { id: id, filename: filename, path: fullPath, mime: mimeType }
```

- `path` is accepted but informational only. The URL is always `/elevate-dxp/api/{name}`.
- `class` is not validated at build time. An unknown or invalid class name (it must match `^[A-Za-z][A-Za-z0-9_]*$`) makes the list return an empty page and the detail return `404`.
- Fields are mapped by the core `FieldMapperInterface` (`ReflectiveFieldMapper`). For each accessor it calls `get<Accessor>()`, `is<Accessor>()` or `has<Accessor>()`; no other method is ever invoked, so a mapping can never trigger a state change. Values are scalarised: dates become ATOM strings, elements their full path, objects with `__toString()` their string, arrays are mapped element by element, and anything else becomes `null`. Only list getters in `fields`.

## REST API

| Route | Request | Response |
|---|---|---|
| `elevate_dxp_datahub_list` | `GET /elevate-dxp/api/{endpoint}?page=1&limit=20` | `{"data":[...],"meta":{"page","limit","total"}}` |
| `elevate_dxp_datahub_detail` | `GET /elevate-dxp/api/{endpoint}/{id}` | `{"data":{...}}` |

- **Authentication:** send the key in the configured header. It is compared with `hash_equals`. Authentication runs before the endpoint lookup, so endpoint names cannot be probed without a key.
- **Pagination:** `limit` defaults to 20 and is clamped to `1..max_limit`. `page` is clamped to `1..1000000`. Non-numeric values fall back to the defaults.
- **Data:**
  - Data objects: `OpenDxp\Model\DataObject\<Class>\Listing`, published only, ordered by id. Detail returns only a published object of the configured class.
  - Assets: `Asset\Listing`, folders excluded, ordered by id.
- **Errors (JSON, no stack traces):**
  - `401 unauthorized`
  - `404 disabled`, `unknown_endpoint`, `not_found`
  - `500 no_provider`, `internal_error`
- **Headers:** every response carries `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.
- **Audit:** every call is logged through `AuditLoggerInterface` as `datahub.<endpoint>.<list|detail|auth>`.
  - Status: `ok`, the error code, or `error`.
  - Actor: `apikey:<first 12 hex chars of the key's sha256>`.
  - Context: `ip`, plus `count`, `id` or `error`.

Rotate the key by changing the environment variable. Only one key is supported. See [../security-and-privacy.md](../security-and-privacy.md).

## Admin

- **REST endpoints** (`datahub_endpoints`): a read-only list of the declared endpoints (type, class, public path, fields, auth header, field mapping).
  - *Try request* (record): parameters are `id` (empty = list), `page` and `limit`. It runs the endpoint through the same `EndpointExecutor` as the public API, without the key check, and shows the request, the HTTP status, the JSON body and a curl example.
  - The API key is never shown. The output warns when no key is configured.
  - `enabled: false` does not affect this action.
- **GraphQL configurations** (`datahub_graphql`): a read-only list from `OpenDxp\Bundle\DataHubBundle\Configuration::getList()`, empty when that bundle is absent. It shows name, type, group, the active flag, whether an API key is set (never its value), the endpoint `/opendxp-graphql-webservices/{name}` for `graphql` configurations, and the description.

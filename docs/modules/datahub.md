# Elevate DXP Datahub Bundle

`elevate-dxp/datahub-bundle` (`ElevateDxp\Datahub`) exposes YAML-declared, API-key protected REST endpoints for OpenDXP data objects and assets. In the admin it also lists the native OpenDXP DataHub GraphQL configurations (`open-dxp/data-hub-bundle`). It is ported from the legacy `OpenPimcore\DatahubBundle`. License: GPL-3.0-or-later.

## Configuration

```yaml
elevate_dxp_datahub:
    enabled: true
    api_key_header: X-Elevate-Dxp-Api-Key
    api_key: '%env(ELEVATE_DXP_DATAHUB_API_KEY)%'   # empty => every request is denied
    max_limit: 100
    endpoints:
        products:                    # served at /elevate-dxp/api/products
            type: data_object        # data_object | asset
            class: Product
            fields: { id: id, sku: sku, name: name, modified: modificationDate }   # output key => getter
        media:
            type: asset
            fields: { id: id, filename: filename, path: fullPath, mime: mimeType }
```

## REST API

| Route | Path |
|---|---|
| `elevate_dxp_datahub_list` | `GET /elevate-dxp/api/{endpoint}?page=1&limit=20` returns `{"data":[...],"meta":{"page","limit","total"}}` |
| `elevate_dxp_datahub_detail` | `GET /elevate-dxp/api/{endpoint}/{id}` returns `{"data":{...}}` |

- **Authentication:** the API key goes in the configured header and is compared with `hash_equals`. Authentication runs before the endpoint lookup, so endpoint names cannot be probed.
- **Limits and pagination:** `limit` is clamped to `1..max_limit`. Non-numeric `page` or `limit` values fall back to the defaults.
- **Data access:**
  - Data objects come from `OpenDxp\Model\DataObject\<Class>\Listing` (published only, ordered by id). A detail request only returns a published object of the configured class.
  - Assets come from `Asset\Listing`, folders excluded.
- **Field mapping:** fields are mapped by the core `FieldMapperInterface`. There is no local `ReflectiveFieldMapper`.
- **Errors:** errors are JSON with no stack traces:
  - `401 unauthorized`
  - `404 unknown_endpoint|not_found|disabled`
  - `500 no_provider|internal_error`
- Responses carry `Cache-Control: no-store`.
- **Audit:** every call is logged through `AuditLoggerInterface` as `datahub.<endpoint>.<list|detail|auth>`. The actor is `apikey:<sha256 prefix>` and the context includes `ip`, `count` and `id`.

## Admin

Both resources use group *Integration* and permission `elevate_dxp_datahub`.

- **`datahub_endpoints` (REST endpoints):** a read-only list of the declared endpoints with their path, fields and auth header. Its **Try request** action takes an optional `id`, plus `page` and `limit`. It runs the endpoint through the same `EndpointExecutor` the public API uses and shows the request, the HTTP status, the JSON body and a curl example. The API key is never shown, and the action warns when no key is configured.
- **`datahub_graphql` (GraphQL configurations):** a read-only list from `OpenDxp\Bundle\DataHubBundle\Configuration::getList()`, guarded by `class_exists`. It shows the name, type, group, active flag, whether an API key is set (never its value), the endpoint `/opendxp-graphql-webservices/{name}` and the description.

## Porting notes

- The public path changed from `/openpimcore/api/...` to `/elevate-dxp/api/...`, and the default header from `X-OpenPimcore-Api-Key` to `X-Elevate-Dxp-Api-Key`. The endpoint `path` option is informational only.
- **Security fix:** a legacy detail request fell back to `AbstractObject::getById()` when the class lookup failed, which could expose objects of other classes. That fallback is gone. Class names are validated against `^[A-Za-z][A-Za-z0-9_]*$`.
- Provider exceptions are now caught and returned as JSON `500 internal_error` (and audited). In the legacy bundle they bubbled up as HTML error pages.
- The Studio controller was replaced by the two admin resources.

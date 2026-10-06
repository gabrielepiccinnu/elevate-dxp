# Elevate DXP Portal Bundle

`elevate-dxp/portal-bundle` (`ElevateDxp\Portal`), GPL-3.0-or-later.

A DAM / Experience portal for OpenDXP 1.4. It provides:

- **Search** through a pluggable `SearchBackendInterface`. The default backend is `ListingSearchBackend`, which runs on plain OpenDXP listings and needs no index.
- A session **download cart** with streamed **ZIP** export.
- Named **collections**.
- Per-user **saved views**.
- Tokenized, expiring **guest share links**, served by a public Twig page.

It is a port of the legacy `OpenPimcore\PortalBundle`. The Studio "DAM Portal" React panel is replaced by Elevate DXP admin resources, so the bundle ships no JavaScript.

## Installation

```bash
bin/console opendxp:bundle:install ElevateDxpPortalBundle
# or, idempotent (tables + permission only):
bin/console elevate-dxp:portal:install
```

The installer creates these tables:

- `edxp_portal_collection`
- `edxp_portal_collection_item`
- `edxp_portal_saved_view`

It also registers the permission `elevate_dxp_portal` in the "Elevate DXP" category. Uninstalling keeps the tables.

## Configuration (`elevate_dxp_portal`)

```yaml
elevate_dxp_portal:
    enabled: true
    products_class: Product           # default class for object searches
    object_image_field: image         # object field whose asset goes into ZIPs
    allowed_asset_paths: ['/products'] # deny-by-default download/guest allow-list (folder boundaries)
    share:
        ttl_days: 7                   # default lifetime of a share link
        max_ttl_days: 90
    search:
        backend: listing              # SearchBackendInterface::getName()
        page_size: 24
        max_page_size: 100
        asset_fields: [filename, path]  # columns of `assets` matched by LIKE
        asset_metadata: true          # also match assets_metadata.data
        default_object_fields: [key]
        object_fields:                # per class searchable fields (validated against the class definition)
            Product: [name, sku]
        locale: null                  # locale for localized fields in object listings
        restrict_assets_to_allowed_paths: false
    branding:
        portal_name: 'Elevate DXP Portal'
        logo_url: null
        primary_color: '#0e7c7b'      # only hex colours are accepted (CSS-injection guard)
        accent_color: '#16242b'
        footer_text: 'Elevate DXP portal · GPL-3.0-or-later'
```

## Search backends

`Search\PortalSearchService` takes an immutable `SearchQuery` and returns a `SearchResult` shaped `{items,total,page,pageSize}`. A `SearchQuery` holds:

- the type (asset or object) and the class;
- the text;
- numeric ranges;
- a folder prefix;
- folder exclusion;
- the ordering;
- the page.

The legacy helpers `searchAssets()` and `searchDataObjects()` are kept.

`ListingSearchBackend` translates each part of the query as follows:

- **Full text:** the text is split into terms (at most 8). Each term must match at least one configured column with `LIKE` (an AND of ORs). Wildcards are escaped and every value is bound as a parameter.
- **Asset metadata:** values in asset metadata are matched through `id IN (SELECT cid FROM assets_metadata WHERE data LIKE ?)`.
- **Numeric ranges:** `>=` and `<=` on allow-listed fields. Object fields must exist in the class definition.
- **Folders:** assets exclude folders with `type != 'folder'`. Object listings only return objects.
- **Path:** `path LIKE '/folder/%'`.
- **Ordering:** only system fields, configured fields and range fields can be sorted on. Paging uses limit and offset.

You can plug in a richer engine, such as an AdvancedObjectSearch or OpenSearch backend, without touching the portal:

```php
final class AdvancedObjectSearchBackend implements \ElevateDxp\Portal\Search\SearchBackendInterface
{
    public function getName(): string { return 'advanced'; }
    public function supports(SearchQuery $q): bool { return $q->type === SearchQuery::TYPE_OBJECT; }
    public function search(SearchQuery $q): SearchResult { /* ... */ }
}
```

Autoconfiguration tags the service with `elevate_dxp_portal.search_backend`. Select it with `elevate_dxp_portal.search.backend: advanced`. If the selected backend does not support a query, for example asset searches here, the service falls back to any backend that does.

Smoke test: `bin/console elevate-dxp:portal:search-smoke --q=shoe --class=Product`

## Admin resources

All of them are in the **Content** group and require `elevate_dxp_portal`.

| Key | Panel | Features |
|---|---|---|
| `portal_search` | report | Filters: type, text, class, folder, range field/min/max, order. Global actions: add to cart, show cart, remove, clear, download cart (ZIP), save cart as collection. Paste element refs (`asset:12, object:7`) from the "Ref" column. |
| `portal_collections` | crud | Owner-scoped; admins see all. Name and elements (`asset:ID` / `object:ID` tags). Actions: show elements, download ZIP, create share link (lifetime in days, which replaces the previous link), revoke, add to cart. Global action: new collection from the cart. |
| `portal_share_links` | crud (read-only) | Collections that have a token: guest URL, status, expiry. Actions: open, extend, revoke. Global action: remove expired links. |
| `portal_saved_views` | crud | Saved searches. The JSON params use the same keys as the search filters (`type,q,class,path,ranges,order_by,order_dir,page_size`) and are validated and normalised before storage. Action: run view. |

## Routes

| Route | Path | Notes |
|---|---|---|
| `elevate_dxp_portal_share_view` | `GET /elevate-dxp/portal/share/{token}` | **Public**, Twig page `@ElevateDxpPortal/share.html.twig` |
| `elevate_dxp_portal_share_download` | `GET /elevate-dxp/portal/share/{token}/download` | **Public**, ZIP |
| `elevate_dxp_portal_admin_download_cart` | `GET /admin/elevate-dxp/portal/download/cart` | admin session + `elevate_dxp_portal` |
| `elevate_dxp_portal_admin_download_collection` | `GET /admin/elevate-dxp/portal/download/collection/{id}` | admin session + `elevate_dxp_portal` + owner scope |

`config/opendxp/routing.yaml` loads all of them automatically.

### Guest share security

- Tokens are 160-bit random values (40 hex characters), generated with `random_bytes`.
- Every link expires. The default lifetime is 7 days and the maximum is `max_ttl_days`. Links can be revoked or extended.
- **Anti-enumeration:** a malformed, unknown, revoked or expired token always gets the same `403 This share link is invalid or has expired.` response, with no hint about which case applies.
- Responses send `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex` and `X-Frame-Options: DENY`.
- Guests only see, and only download, assets that `PortalPolicy` allows. The policy is deny-by-default: an asset must sit inside `allowed_asset_paths`, the match respects folder boundaries, and folders themselves are never allowed. Unpublished objects are hidden.
- Every guest view and ZIP download is audited through the core `AuditLoggerInterface` (`portal.share.view`, `portal.zip`).

### Optional: a dedicated portal firewall

The share routes are anonymous and need no firewall. If you build an authenticated front-end portal under `/elevate-dxp/portal`, for example with your own Twig pages that use `PortalSearchService`, `CartStorage` and `CollectionRepository`, add a firewall in front of the default one. The firewall can reuse the OpenDXP user provider. Keep the share routes public:

```yaml
# config/packages/security.yaml
security:
    providers:
        opendxp_portal_users:
            id: OpenDxp\Security\User\UserProvider   # authenticates against OpenDXP users
    firewalls:
        elevate_dxp_portal:
            pattern: ^/elevate-dxp/portal(?!/share/)
            provider: opendxp_portal_users
            form_login:
                login_path: app_portal_login
                check_path: app_portal_login
            logout:
                path: app_portal_logout
    access_control:
        - { path: ^/elevate-dxp/portal/share/, roles: PUBLIC_ACCESS }
        - { path: ^/elevate-dxp/portal, roles: ROLE_OPENDXP_USER }
```

The app already declares this provider as `opendxp_admin`, so you can reuse that name instead. Put the portal firewall **before** `opendxp_admin`.

## Porting notes (from `OpenPimcore\PortalBundle`)

- **Generic Data Index search → `SearchBackendInterface` + `ListingSearchBackend`.** Results are plain arrays of `id, type, key, fullPath, subtype, mimetype, modificationDate, fields`. The legacy `index` blob from the data index is not reproduced.
- **Studio controller `/pimcore-studio/api/openpimcore/portal/*` → admin resources.** ZIP downloads live in a small `/admin` controller.
- **Share URL** changed from `/openpimcore/share/{token}` to `/elevate-dxp/portal/share/{token}`. Old links stop working.
- **Hardening:**
  - The path allow-list now matches on folder boundaries. `/products` no longer allows `/products-internal`.
  - Guests no longer see names or previews of assets that the policy does not allow.
  - Branding colours are validated before they are used.
  - Collections can now be renamed, edited, deleted, revoked and extended.
- Twig function `edxp_branding()`. `op_branding()` is kept as an alias.
- Tables were renamed from `op_portal_*` to `edxp_portal_*`. The share columns are now created directly instead of through `ALTER`.
- `open-pimcore:portal:*` → `elevate-dxp:portal:install` and `elevate-dxp:portal:search-smoke`.

## Tests

```bash
docker compose exec -T php vendor/bin/phpunit -c /var/www/packages/phpunit.xml.dist --filter PortalBundle
```

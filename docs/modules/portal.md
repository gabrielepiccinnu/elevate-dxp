# Portal

A DAM / experience portal on top of OpenDXP assets and data objects: search through a pluggable backend, a session download cart with streamed ZIP export, owner-scoped collections, saved searches, and tokenized, expiring guest share links served by a public Twig page. All admin features are Elevate DXP admin resources; the module ships no JavaScript of its own.

Config key: `elevate_dxp.portal` · Permission: `elevate_dxp_portal` · Admin menu: Elevate DXP → Content

See also: [Configuration](../configuration.md), [Architecture](../architecture.md), [Security and privacy](../security-and-privacy.md).

## Installation

The bundle installer (`bin/console opendxp:bundle:install ElevateDxpBundle`) creates the portal tables and registers the permission together with every other module. To re-apply only the portal schema and permission (idempotent):

```bash
bin/console elevate-dxp:portal:install
```

Tables:

| Table | Content |
|---|---|
| `edxp_portal_collection` | collections: owner, name, `share_token` (CHAR(40), unique), `share_expires_at` |
| `edxp_portal_collection_item` | collection elements (`element_type`, `element_id`), unique per collection |
| `edxp_portal_saved_view` | saved searches (`params_json`), per owner |

Uninstalling the bundle removes the permission and keeps the tables.

## Configuration

Defaults shown:

```yaml
elevate_dxp:
    portal:
        enabled: true                      # currently not read by any service
        products_class: Product            # default DataObject class for object searches
        object_image_field: image          # object field whose asset is put into ZIPs
        allowed_asset_paths: ['/products'] # deny-by-default allow-list for downloads and guests
        share:
            ttl_days: 7                    # default share-link lifetime (min 1)
            max_ttl_days: 90               # upper bound for create/extend (min 1)
        search:
            backend: listing               # SearchBackendInterface::getName()
            page_size: 24
            max_page_size: 100
            asset_fields: [filename, path] # columns of `assets` matched with LIKE
            asset_metadata: true           # also match assets_metadata.data
            default_object_fields: [key]
            object_fields: {}              # per class, e.g. { Product: [name, sku] }
            locale: null                   # locale for localized fields in object listings
            restrict_assets_to_allowed_paths: false  # also limit asset search to allowed_asset_paths
        branding:
            portal_name: 'Elevate DXP Portal'
            logo_url: null
            primary_color: '#0e7c7b'       # hex only; anything else falls back to the default
            accent_color: '#16242b'
            footer_text: 'Elevate DXP portal · GPL-3.0-or-later'
```

`allowed_asset_paths` is matched on folder boundaries: `/products` allows `/products/a.jpg` but not `/products-internal/a.jpg`. `/` allows everything; an empty list denies everything. Folders are never downloadable.

## Search

`ElevateDxp\Portal\Search\PortalSearchService` takes an immutable `SearchQuery` and returns a `SearchResult` (`{items,total,page,pageSize}`). `SearchQuery::fromArray()` accepts the keys `type` (`asset`|`object`), `q` or `text` (max 200 chars), `class`, `path`, `min`/`max`/`range_field` (default field `price`), `ranges` (list of `{field,min,max}`), `order_by`, `order_dir`, `page`, `page_size` (or `start` + `limit`) and `include_folders`. The convenience methods `searchAssets()` and `searchDataObjects()` are also available.

The default backend, `ListingSearchBackend` (name `listing`), uses plain OpenDXP listings and needs no index:

- **Text:** split on whitespace into at most 8 distinct terms; every term must match at least one configured column with `LIKE` (AND of ORs). Wildcards are escaped, values are bound parameters, identifiers are validated and quoted.
- **Asset metadata:** when `asset_metadata` is true, each term may also match `id IN (SELECT cid FROM assets_metadata WHERE data LIKE ?)`.
- **Object fields:** `object_fields[<class>]`, else `default_object_fields`. Each field must be a system field (`id, key, path, published, creationDate, modificationDate`) or exist in the class definition; otherwise the query fails.
- **Ranges:** `>=` / `<=` on allow-listed fields only (asset system fields plus `asset_fields`, or valid object fields).
- **Folders:** asset searches exclude `type = 'folder'` unless `include_folders` is set.
- **Path:** subtree restriction `path LIKE '/folder/%'`.
- **Ordering:** allow-listed fields only; default `filename` (assets) or `key` (objects). Paging uses limit/offset.

It is SQL-`LIKE` based and suits small and medium repositories. To plug in another engine, implement `ElevateDxp\Portal\Search\SearchBackendInterface`:

```php
use ElevateDxp\Portal\Search\SearchBackendInterface;
use ElevateDxp\Portal\Search\SearchQuery;
use ElevateDxp\Portal\Search\SearchResult;

final class OpenSearchBackend implements SearchBackendInterface
{
    public function getName(): string { return 'opensearch'; }
    public function supports(SearchQuery $q): bool { return $q->type === SearchQuery::TYPE_OBJECT; }
    public function search(SearchQuery $q): SearchResult { /* ... */ }
}
```

Autoconfiguration tags the service with `elevate_dxp_portal.search_backend`; select it with `elevate_dxp.portal.search.backend: opensearch`. If the selected backend does not support a query, the service uses the first registered backend that does.

Smoke test:

```bash
bin/console elevate-dxp:portal:search-smoke --q=shoe --class=Product   # --class= (empty) skips objects
```

## Admin resources

All resources are in the Content group and require `elevate_dxp_portal`. Collections, share links and saved views are scoped to the current user name; admin users see all records.

| Key | Panel | Features |
|---|---|---|
| `portal_search` ("Portal search & cart") | report | Filters: type, text, class, folder, range field, min, max, order by, direction. Global actions: add to cart, show cart, remove from cart, clear cart, download cart (ZIP), save cart as collection (clears the cart). Element lists use refs such as `asset:12, object:7` (copy from the "Ref" column; bare ids are assets). |
| `portal_collections` | crud | Name and elements (`asset:ID` / `object:ID`). Record actions: show elements, download ZIP, create share link (lifetime in days, replaces the previous link), revoke share link, add to cart. Global action: new collection from the cart. The raw token is never shown in this grid. |
| `portal_share_links` | crud, read-only | Collections that have a token: guest URL, status (active/expired), expiry, element count. Record actions: open, extend (days from now), revoke. Global action: remove expired links. |
| `portal_saved_views` | crud | Name plus JSON search parameters (same keys as `SearchQuery::fromArray()`), normalised before storage. Record action: run view. |

The session cart holds at most 1000 elements; a ZIP includes at most the first 1000 items.

## Routes

Defined in `config/opendxp/routing.yaml` (loaded automatically).

| Route | Path | Access |
|---|---|---|
| `elevate_dxp_portal_share_view` | `GET /elevate-dxp/portal/share/{token}` | public; renders `@ElevateDxp/portal/share.html.twig` |
| `elevate_dxp_portal_share_download` | `GET /elevate-dxp/portal/share/{token}/download` | public; ZIP |
| `elevate_dxp_portal_admin_download_cart` | `GET /admin/elevate-dxp/portal/download/cart` | admin session + `elevate_dxp_portal` (or admin) |
| `elevate_dxp_portal_admin_download_collection` | `GET /admin/elevate-dxp/portal/download/collection/{id}` | as above, plus owner scope |

The JSON admin features go through the core resource controller (`/admin/elevate-dxp/r/{key}/...`); the ZIP downloads have their own controller because they are binary streams.

## ZIP export and access policy

`ZipBuilder` streams a ZIP (via `maennchen/zipstream-php`) of asset binaries. Data objects contribute the asset in `object_image_field`. Every asset passes `PortalPolicy::canDownload()` (inside `allowed_asset_paths`, not a folder); other assets are silently skipped. Duplicate file names get a `-N` suffix. Each download is audited as `portal.zip` through the core audit logger.

## Guest share links

- Tokens are 160-bit random values (`bin2hex(random_bytes(20))`, 40 hex characters).
- Every link expires: default `share.ttl_days`, bounded by `share.max_ttl_days`. Links can be extended or revoked; "Remove expired links" clears expired tokens.
- Malformed, unknown, revoked and expired tokens get the same `403` response (`This share link is invalid or has expired.`).
- Responses send `Cache-Control: private, no-store, max-age=0`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow` and `X-Frame-Options: DENY`.
- The guest page only lists assets that `PortalPolicy` allows and published data objects; the ZIP applies the same asset policy.
- Each guest page view is audited as `portal.share.view`; guest downloads as `portal.zip` with actor `guest`.

## Branding

The Twig function `edxp_branding()` returns the `branding` settings. Colours that are not hex values (`#rgb` / `#rrggbb`) are replaced by the defaults, because they are injected into a `<style>` block.

## Optional: an authenticated front-end portal

The share routes are anonymous and need no firewall. If you build your own authenticated pages under `/elevate-dxp/portal` (for example with `PortalSearchService`, `CartStorage` and `CollectionRepository`), add a firewall before `opendxp_admin` that reuses the OpenDXP user provider, and keep the share routes public:

```yaml
# config/packages/security.yaml
security:
    providers:
        opendxp_admin:
            id: OpenDxp\Security\User\UserProvider   # already declared by OpenDXP
    firewalls:
        elevate_dxp_portal:
            pattern: ^/elevate-dxp/portal(?!/share/)
            provider: opendxp_admin
            form_login:
                login_path: app_portal_login
                check_path: app_portal_login
            logout:
                path: app_portal_logout
        # opendxp_admin: '%opendxp_admin_bundle.firewall_settings%'
    access_control:
        - { path: ^/elevate-dxp/portal/share/, roles: PUBLIC_ACCESS }
        - { path: ^/elevate-dxp/portal, roles: ROLE_OPENDXP_USER }
```

`OpenDxp\Security\User\User` exposes `ROLE_OPENDXP_ADMIN` for admins and `ROLE_OPENDXP_USER` otherwise; the standard OpenDXP `role_hierarchy` maps `ROLE_OPENDXP_ADMIN` to `ROLE_OPENDXP_USER`. The `app_portal_*` routes are yours to implement.

# Statistics

Config-driven, read-only SQL reports. Each report can be shown in the admin as a table, with a chart for `bar` and `line` reports. Reports can also be run from the CLI and seeded as native OpenDXP Custom Reports. The admin catalogue also lists the existing native Custom Reports.

Config key: `elevate_dxp.statistics` · Permission: `elevate_dxp_statistics` · Admin menu: Elevate DXP → Insights

Installed with the bundle; no tables (permission only).

## Configuration

```yaml
elevate_dxp:
    statistics:
        enabled: true          # false: no per-report panels (catalogue, CLI and seeding still work)
        max_rows: 1000         # cap on rows shown per run (min 1)
        report_panels: true    # one "report" admin panel per configured report
        reports:
            assets_by_type:    # name: [A-Za-z0-9_-]+
                label: 'Assets by type'   # default: the name
                sql: 'SELECT type, COUNT(*) AS total FROM assets GROUP BY type'   # required
                chart: bar     # bar | line | pie | none (default bar)
                x: type        # label column
                y: total       # numeric column
                columns: []    # optional; derived from the query when empty
```

## SQL guard

Every query passes `ReadOnlySqlGuard` before execution:

- It must be a single `SELECT` or `WITH` statement. Trailing semicolons are stripped, and any other `;` is rejected.
- These keywords (whole words, case-insensitive) are rejected anywhere in the query, including inside string literals: `insert update delete drop alter truncate create grant revoke replace merge call into load lock rename set outfile dumpfile handler prepare execute deallocate`.
- When no transaction is already open, the query runs inside `START TRANSACTION READ ONLY`, followed by `ROLLBACK`.

The full result set is fetched, then cut to `max_rows`. For large tables, put a `LIMIT` in the query. Report SQL runs on the default DBAL connection with that connection's database rights. See [../security-and-privacy.md](../security-and-privacy.md).

## Admin

- **Statistics reports** (`statistics_reports`) is a read-only catalogue of the YAML reports (`config:<name>`). It also lists the native Custom Reports (`native:<name>`) visible to the user, when the user is admin or has `reports` or `reports_config`.
  - *Run report* (record): shows up to `max_rows` rows. Native reports use the native adapter and sharing rules, and need `reports` or `reports_config`.
  - *Seed native Custom Reports* (global): see below. Needs admin or `reports_config`.
- **Statistics: \<label\>** (`statistics_report_<name>`): one `report` panel per configured report. It is registered only when `enabled` and `report_panels` are both true.
  - Shows a table, plus a bar chart when `chart` is `bar` or `line` and both `x` and `y` are set. `pie` and `none` show the table only.
  - Columns come from `columns`, or are derived once with `LIMIT 1` and cached in `cache.app` for 24 h (5 min if empty). The cache key includes the SQL.

## Native Custom Reports

`elevate-dxp:statistics:seed-native` and the admin action create each configured report as an `OpenDxp\Bundle\CustomReportsBundle\Tool\Config` in the group "Elevate DXP Statistics". The SQL data source, the columns and the chart settings are pre-configured.

- Existing reports with the same name are skipped and never overwritten.
- `WITH` queries are reported as unsupported, because the native SQL adapter cannot run them.

Custom Reports are stored with OpenDXP's location-aware config. With file-based storage, seeded reports may only appear after the cache or container has been rebuilt.

## CLI

```
bin/console elevate-dxp:statistics:run [report]               # omit the name to list reports
bin/console elevate-dxp:statistics:seed-native [reports...]   # idempotent; exits 1 if any report is unsupported
```

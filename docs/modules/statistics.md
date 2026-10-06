# Elevate DXP Statistics Bundle

Config-driven, **read-only** SQL reports for OpenDXP. Each report gets a table + chart admin panel, can be run from the CLI and can be seeded as a native OpenDXP Custom Report. Ported from `OpenPimcore\StatisticsBundle`. GPL-3.0-or-later.

## Configuration

```yaml
elevate_dxp_statistics:
    enabled: true
    max_rows: 1000          # cap on rows returned to the admin UI / per run
    report_panels: true     # one "report" admin panel per report
    reports:
        assets_by_type:     # name: [A-Za-z0-9_-]+
            label: 'Assets by type'
            sql: 'SELECT type, COUNT(*) AS total FROM assets GROUP BY type'
            chart: bar      # bar | line | pie | none
            x: type
            y: total
            columns: []     # optional; derived from the query (cached 24h in cache.app) when empty
```

**SQL guard:** the query must be a single `SELECT`/`WITH` statement. Statement stacking and the keywords `insert update delete drop alter truncate create grant revoke replace merge call into load lock rename set outfile dumpfile handler prepare execute deallocate` are rejected. The query also runs inside `START TRANSACTION READ ONLY`.

## CLI

- `elevate-dxp:statistics:run [report]` runs a report. Leave out the name to list the reports.
- `elevate-dxp:statistics:seed-native [reports...]` creates the reports as native Custom Reports (`OpenDxp\Bundle\CustomReportsBundle\Tool\Config`, group "Elevate DXP Statistics", columns pre-configured). It is idempotent. `WITH` queries are reported as unsupported because the native SQL adapter cannot run them.

## Admin resources

All resources need the `elevate_dxp_statistics` permission. They appear in the Insights menu group.

- **`statistics_reports`** is a read-only catalogue of the YAML reports plus the native Custom Reports.
  - `run` (record) shows the rows. Native reports also need the `reports` permission and native sharing.
  - `seed_native` (global) seeds the native reports. It needs `reports_config` or admin.
- **`statistics_report_<name>`** is a `report` panel per configured report (table plus bar chart for `bar`/`line`).

With the default `symfony-config` write target, seeded reports appear once the container has been rebuilt. This is native OpenDXP behaviour.

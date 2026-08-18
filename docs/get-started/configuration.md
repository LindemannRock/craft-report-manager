# Configuration

Most of Report Manager's settings are managed from **Report Manager → Settings** and stored in the database — no config file required. The settings are split across four tabs:

| Tab | Covers |
|-----|--------|
| **General** | Plugin name, log level |
| **Interface** | Items per page, date/number formatting, which export formats are offered |
| **Scheduling** | Whether scheduled reports run, and the default schedule |
| **Export** | Default format, batch size, storage location, CSV options, retention/cleanup |

## Overriding Settings with a Config File

To lock settings down (or vary them per environment), create `config/report-manager.php`. Any key you set there **overrides** the database value and the field is shown as read-only in the Control Panel with an "overridden by config" notice.

```php
<?php
// config/report-manager.php

use craft\helpers\App;

return [
    // Applies to all environments
    '*' => [
        'defaultExportFormat' => 'csv',
        'maxExportBatchSize' => 500,
        'exportRetention' => 30,
        'autoCleanupExports' => true,
    ],

    // Development overrides
    'dev' => [
        'enableScheduledReports' => false,
    ],

    // Production overrides
    'production' => [
        'exportVolumeUid' => App::env('REPORT_MANAGER_EXPORT_VOLUME_UID'),
    ],
];
```

## Scheduling Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enableScheduledReports` | `bool` | `true` | Master switch for scheduled report generation. When off, no report runs on a schedule regardless of its own setting. |
| `defaultSchedule` | `string` | `'daily2am'` | The schedule pre-selected for new reports. See [Scheduling](../feature-tour/scheduling.md) for the full list of values. |

Valid `defaultSchedule` values: `disabled`, `every6hours`, `every12hours`, `daily`, `daily2am`, `weekly`, `monthly`, `every2months`, `quarterly`, `every6months`, `yearly`.

## Export Storage

By default exports are written to the local filesystem. Set a volume UID to store them in a Craft asset volume instead.

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `exportVolumeUid` | `string\|null` | `null` | Asset volume UID for export storage. When `null`, `exportPath` is used. |
| `exportPath` | `string` | `'@storage/report-manager/exports'` | Filesystem path for exports when no volume is set. |

> [!IMPORTANT]
> `exportPath` must use a Craft path alias (`@storage` or `@root` only) and must resolve **outside** the webroot. Paths that resolve to the webroot, the project root, or contain `..` are rejected.

```php
'exportPath' => '@storage/report-manager/exports', // recommended
// or
'exportPath' => '@root/exports/report-manager',
```

## Export Format & CSV

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `defaultExportFormat` | `string` | `'csv'` | Pre-selected format for new reports. One of `csv`, `xlsx`, `json`. |
| `maxExportBatchSize` | `int` | `10000` | Configured ceiling for records loaded per standard export batch. Range: 100–100000; Report Manager applies a runtime safety ceiling of 1,000. |
| `csvDelimiter` | `string` | `','` | Single character used as the CSV field delimiter. |
| `csvEnclosure` | `string` | `'"'` | Single character used to enclose CSV field values. |
| `csvIncludeBom` | `bool` | `true` | Prepend a UTF-8 BOM to CSV files for Excel compatibility. |

> [!NOTE]
> Which formats appear in the format dropdowns is controlled by the **Interface** settings (CSV / JSON / Excel toggles). `defaultExportFormat` must be one of the formats you've enabled.

Standard CSV, JSON, and XLSX exports are generated incrementally for both separate and combined exports. Report Manager never hydrates more than 1,000 source records at once, even when an older installation retains the original `10000` setting. Set `maxExportBatchSize` lower when records contain unusually heavy fields. Registered custom data sources must honor the `limit` and `offset` options supplied to `exportToArray()`.

## Cleanup & Retention

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `exportRetention` | `int` | `30` | Days to keep generated export files and records. Set to `0` to keep forever. |
| `autoCleanupExports` | `bool` | `true` | When enabled, a daily queue job deletes exports older than `exportRetention` days. |

Automatic cleanup is active only when `autoCleanupExports` is `true` and `exportRetention` is greater than `0`. Report Manager keeps one recurring daily cleanup chain in Craft's queue. Disabling the toggle or changing retention to `0` cancels that cleanup family; re-enabling it creates one new daily chain. Changing one positive retention value to another keeps the existing schedule and applies the new retention when cleanup runs.

The daily target is calculated in Craft's configured timezone. Delay-limited queue backends use one or more handoffs capped at 900 seconds; intermediate handoffs do not run cleanup. Native database queues and unknown non-SQS backends retain the complete delay. These queue details affect scheduling only — export storage paths, asset volumes, file formats, and retention semantics stay the same.

## General

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `pluginName` | `string` | `'Report Manager'` | The name shown in the Control Panel menu. |
| `logLevel` | `string` | — | Log verbosity for the plugin's log channel (requires the Logging Library). |

Shared interface settings — items per page, date/time formatting, default date range, and the CSV/JSON/Excel format toggles — are provided by the base plugin and can also be set here. See the base plugin's date-format configuration for the formatting keys.

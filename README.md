# Report Manager for Craft CMS

[![Latest Version](https://img.shields.io/packagist/v/lindemannrock/craft-report-manager.svg)](https://packagist.org/packages/lindemannrock/craft-report-manager)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-5.0+-orange.svg)](https://craftcms.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net/)
[![Logging Library](https://img.shields.io/badge/Logging%20Library-5.0+-green.svg)](https://github.com/LindemannRock/craft-logging-library)
[![License](https://img.shields.io/packagist/l/lindemannrock/craft-report-manager.svg)](LICENSE)

Saved reporting, content inventory, and export management for Craft CMS with extensible data source support.

## License

This is a commercial plugin licensed under the [Craft License](https://craftcms.github.io/license/). It will be available on the [Craft Plugin Store](https://plugins.craftcms.com) soon. See [LICENSE.md](LICENSE.md) for details.

## ⚠️ Pre-Release

This plugin is in active development and not yet available on the Craft Plugin Store. Features and APIs may change before the initial public release.

## Features

### Report Management
- **Saved Reports** - Create and save report configurations for repeated use
- **Multiple Data Sources** - Extensible architecture for Craft-native content, Formie, and future integrations
- **Field Selection** - Choose which fields to include in exports
- **Date Range Filtering** - Filter data with the shared LindemannRock Base date presets or a custom range
- **Filter by Date** - Choose which date the range applies to per data source (entries by Post Date, Created, or Updated; categories by Created or Updated; Formie by Submission or Updated date)
- **Multi-Site Support** - Filter exports by site

### Scheduled Reports
- **Automatic Generation** - Schedule reports to run automatically
- **Flexible Scheduling** - Hourly-style, daily, weekly, monthly, and longer recurring schedules
- **Queue Integration** - Uses Craft's queue system for reliable background processing
- **Self-Rescheduling** - Jobs automatically reschedule after completion

### Export Formats
- **CSV** - Universal format with BOM support for Excel compatibility
- **Excel (XLSX)** - Native Excel format with styled headers, auto-sized columns, and frozen header row
- **JSON** - Structured data for developers and API integrations

### Export Management
- **Dashboard** - View all generated exports with status, size, and download links
- **Automatic Cleanup** - Configurable retention period for old exports
- **Flexible Storage** - Store exports locally or in a Craft volume
- **Combined Exports** - Merge multiple entities into a single export file

### Craft Content Exports
- **Content Inventory** - Export entries by section for audits, migrations, and Feed Me-compatible source files
- **Field Mapping** - Include system fields and section field-layout fields
- **Date Filtering** - Filter by Post Date (default), Created, or Updated date, and site

### Formie Integration
- **Form Selection** - Export submissions from any Formie form
- **Field Mapping** - Automatic field detection and selection
- **Submission Filtering** - Filter by date range and site

## Requirements

- PHP 8.2+
- Craft CMS 5.0+
- LindemannRock Logging Library ^5.0 (installed automatically)
- LindemannRock Base Plugin ^5.0 (installed automatically)
- PhpSpreadsheet ^2.0 || ^3.0 (installed automatically)

### Optional
- [Formie](https://plugins.craftcms.com/formie) - Required for Formie data source

## Installation

### Via Composer (Development)

Until published on Packagist, install directly from the repository:

```bash
cd /path/to/project
composer config repositories.report-manager vcs https://github.com/LindemannRock/craft-report-manager
composer require lindemannrock/craft-report-manager:dev-main
./craft plugin/install report-manager
```

### Via Composer (Production - Coming Soon)

Once published on Packagist:

```bash
cd /path/to/project
composer require lindemannrock/craft-report-manager
./craft plugin/install report-manager
```

### Via Plugin Store (Future)

1. Go to the Plugin Store in your Craft control panel
2. Search for "Report Manager"
3. Click "Install"

## Configuration

### Config File

Create a `config/report-manager.php` file to override default settings:

```php
<?php

use craft\helpers\App;

return [
    // Plugin name (displayed in CP)
    'pluginName' => 'Report Manager',

    // Scheduled Reports
    'enableScheduledReports' => true,
    'defaultSchedule' => 'daily2am',  // every6hours, every12hours, daily, daily2am, weekly, monthly, every2months, quarterly, every6months, yearly

    // Export Settings
    'defaultExportFormat' => 'csv',  // csv, xlsx, json
    'maxExportBatchSize' => 10000,
    'exportRetention' => 30,  // days (0 = keep forever)
    'autoCleanupExports' => true,

    // CSV Settings
    'csvDelimiter' => ',',
    'csvEnclosure' => '"',
    'csvIncludeBom' => true,  // BOM for Excel compatibility

    // Storage
    'exportVolumeUid' => null,  // Volume UID for exports (null = local storage)
    'exportPath' => '@storage/report-manager/exports',  // Local path when not using volume

    // Report/export defaults
    'defaultDateRange' => 'last30days',

    // Interface
    'itemsPerPage' => 50,

    // Logging
    'logLevel' => 'error',  // error, warning, info, debug
];
```

Export retention is rolling time-based retention. For example, `exportRetention => 1`
keeps exports from the last 24 hours, not the current and previous calendar day.
Set `exportRetention` to `0` to keep generated exports until they are manually deleted.

### Environment-Specific Configuration

```php
<?php

return [
    '*' => [
        'enableScheduledReports' => true,
        'defaultExportFormat' => 'xlsx',
    ],
    'production' => [
        'logLevel' => 'error',
        'exportRetention' => 90,
    ],
    'dev' => [
        'logLevel' => 'debug',
        'exportRetention' => 7,
    ],
];
```

## Usage

### Creating a Report

1. Navigate to **Report Manager → Reports**
2. Click **New Report**
3. Configure:
   - **Name** - Descriptive name for the report
   - **Data Source** - Select data source (e.g., Formie)
   - **Entity** - Select form(s) to include
   - **Export Mode** - Separate (one file per form) or Combined (all in one file)
   - **Fields** - Select which fields to export
   - **Date Range** - Filter by date
   - **Export Format** - CSV, XLSX, or JSON
4. Save the report

### Generating Exports

**Manual Generation:**
1. Go to **Report Manager → Reports**
2. Click on a report
3. Click **Generate Export**
4. Download from the exports list

**Scheduled Generation:**
1. Enable **Auto Generate** on a report
2. Set the schedule in **Settings → General → Default Schedule**
3. Exports generate automatically via queue

### Viewing Exports

1. Navigate to **Report Manager → Dashboard**
2. View all generated exports
3. Filter by status (completed, failed, pending)
4. Download completed exports

### Export Storage

**Local Storage (default):**
- Exports saved to `storage/report-manager/exports/`
- Configure path via the `exportPath` setting. Alias-based paths such as `@storage/...` and `@root/...` are supported, and paths resolving inside `@webroot` are rejected.

**Volume Storage:**
1. Create a volume in Craft (Settings → Filesystems → Volumes)
2. Go to **Report Manager → Settings → Export**
3. Select the volume
4. Exports saved to `report-manager/exports/` within the volume

Local and remote volumes are supported. Local volumes that resolve inside `@webroot` are rejected; for remote volumes, public accessibility depends on the filesystem/provider settings configured in Craft.

## Scheduled Reports

Report Manager uses Craft's queue system for scheduled report generation.

### How It Works

1. When enabled, each scheduled report gets its own `ProcessScheduledReportJob` queued for its next run time
2. The report job creates export records and dispatches `GenerateExportJob` jobs for the actual file generation
3. After dispatching export jobs, the report job calculates that report's next run time and queues the next report job
4. Export retention runs independently through `CleanupExportsJob`
5. Jobs appear in the queue as: **Report Manager: Scheduled report - Weekly Leads (Jan 24, 3:00am)**

### Queue Worker

Ensure your queue worker is running:

```bash
# Run queue listener
php craft queue/listen

# Or via cron (every minute)
* * * * * /path/to/craft queue/run
```

### Schedule Options

Short schedules use **fixed time slots** to prevent drift. Monthly and longer schedules are based on the day/time the report is scheduled and clamp to the last valid day of shorter months.

| Setting | Timing |
|---------|--------|
| `every6hours` | 00:00, 06:00, 12:00, 18:00 |
| `every12hours` | 00:00, 12:00 |
| `daily` | 00:00 (midnight) |
| `daily2am` | 02:00 (default) |
| `weekly` | Craft’s default week start day at 00:00 |
| `monthly` | Same day/time as the report schedule start |
| `every2months` | Every 2 months from the report schedule start |
| `quarterly` | Every 3 months from the report schedule start |
| `every6months` | Every 6 months from the report schedule start |
| `yearly` | Every 12 months from the report schedule start |

**Note:** Manual report generation updates "Last Generated" but does not affect the schedule.

## Export Formats

### CSV
- Universal compatibility
- Optional BOM for Excel
- Configurable delimiter and enclosure

### Excel (XLSX)
- Native Excel format
- Bold headers with gray background
- Auto-sized columns
- Frozen header row (stays visible when scrolling)
- Sheet name from entity/form name

### JSON
- Pretty-printed output
- UTF-8 encoded
- Array of objects with field names as keys

## Permissions

| Permission | Description |
|------------|-------------|
| **View Dashboard** | View exports dashboard |
| **View Reports** | View saved reports |
| **Manage Reports** | Create, edit, delete reports (nested under View Reports) |
| **View Exports** | View export records |
| **Create Exports** | Generate new exports (nested under View Exports) |
| **Download Exports** | Download export files (nested under View Exports) |
| **Delete Exports** | Delete export records (nested under View Exports) |
| **View Logs** | Access plugin logs |
| **Download Logs** | Download log files (nested under View Logs) |

## Logging

Report Manager uses the [LindemannRock Logging Library](https://github.com/LindemannRock/craft-logging-library) for centralized logging.

### Log Levels
- **Error**: Critical errors only (default)
- **Warning**: Errors and warnings
- **Info**: General information
- **Debug**: Detailed debugging (requires devMode)

### Configuration

```php
// config/report-manager.php
return [
    'logLevel' => 'error',  // error, warning, info, debug
];
```

**Note:** Debug level requires Craft's `devMode` to be enabled. If set to debug with devMode disabled, it automatically falls back to info level.

### Log Files
- **Location**: `storage/logs/report-manager-YYYY-MM-DD.log`
- **Retention**: 30 days (automatic cleanup via Logging Library)
- **Web Interface**: View and filter logs at Report Manager → Logs

## Data Sources

Report Manager uses an extensible data source architecture. Currently supported:

### Craft Entries
- Export entries by section
- System fields and section field-layout fields
- Date range filtering by entry creation date
- Multi-site support with explicit site ID, handle, and name columns
- Combined exports from multiple sections
- Useful for content inventory, audit, migration, bulk-edit, and Feed Me-compatible source files

### Craft Categories
- Export categories by category group
- System fields and group field-layout fields
- Date range filtering by category creation date
- Multi-site support with explicit site ID, handle, and name columns
- Combined exports from multiple category groups
- Useful for taxonomy inventory, audit, migration, bulk-edit, and Feed Me-compatible source files

### Formie
- Export form submissions
- Field-level selection
- Date range filtering
- Multi-site support
- Combined exports from multiple forms

### Adding Custom Data Sources

Data sources implement the `DataSourceInterface`:

```php
interface DataSourceInterface
{
    public static function handle(): string;
    public static function displayName(): string;
    public static function description(): string;
    public static function uiLabels(): array;
    public static function capabilities(): array;
    public static function iconUrl(): ?string;
    public static function isAvailable(): bool;
    public function getAvailableEntities(): array;
    public function getEntity(int $id): ?array;
    public function getEntityFields(int $entityId): array;
    public function getRecords(int $entityId, array $options = []): array;
    public function getRecordCount(int $entityId, array $options = []): int;
    public function getAnalytics(int $entityId, string $dateRange = 'last30days'): array;
    public function getTrendData(int $entityId, string $dateRange = 'last30days'): array;
    public function exportToArray(int $entityId, array $fieldHandles = [], array $options = []): array;
    public function getSettingsHtml(): ?string;
}
```

`uiLabels()` keeps the control panel wording source-specific. Formie uses forms/submissions, while future sources can use sections/entries, users, assets, or another domain language. `capabilities()` advertises whether a source supports fields, date ranges, analytics, combined exports, site filtering, and scheduling.

Register data sources through `DataSourcesService::EVENT_REGISTER_DATA_SOURCES`:

```php
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\services\DataSourcesService;
use yii\base\Event;

Event::on(
    DataSourcesService::class,
    DataSourcesService::EVENT_REGISTER_DATA_SOURCES,
    function(RegisterDataSourcesEvent $event) {
        $event->register(
            MyDataSource::handle(),
            MyDataSource::displayName(),
            MyDataSource::class
        );
    }
);
```

Use data sources when your report naturally has entities, fields, analytics, and tabular exports, such as forms or other record collections managed inside Report Manager.

## Queued Export Providers

Queued export providers let another plugin use Report Manager's queue, status, storage, and download flow without fitting the Formie-style `dataSource + entityId` model.

Providers implement `QueuedExportProviderInterface` or extend `BaseQueuedExportProvider`, then register themselves with `QueuedExportProvidersService::EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS`.

Use providers when the source plugin already owns the UI and permissions, and only needs Report Manager to create, queue, store, track, and serve the export file.

```php
use lindemannrock\reportmanager\events\RegisterQueuedExportProvidersEvent;
use lindemannrock\reportmanager\services\QueuedExportProvidersService;
use yii\base\Event;

Event::on(
    QueuedExportProvidersService::class,
    QueuedExportProvidersService::EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS,
    function(RegisterQueuedExportProvidersEvent $event) {
        $event->register(
            MyAnalyticsExportProvider::handle(),
            MyAnalyticsExportProvider::displayName(),
            MyAnalyticsExportProvider::class
        );
    }
);
```

Provider contract:

```php
interface QueuedExportProviderInterface
{
    public static function handle(): string;
    public static function displayName(): string;
    public static function isAvailable(): bool;
    public static function supportedFormats(): array;
    public function normalizePayload(array $payload): array;
    public function getExportName(array $payload): string;
    public function getFilename(array $payload, string $format): string;
    public function getPermissions(array $payload): array;
    public function generate(array $payload, QueuedExportContext $context): QueuedExportResult;
}
```

Create and queue a provider export:

```php
use lindemannrock\reportmanager\jobs\GenerateExportJob;
use lindemannrock\reportmanager\ReportManager;

$export = ReportManager::getInstance()->exports->createQueuedExport(
    'my-plugin.analytics',
    'xlsx',
    ['dateRange' => 'last30days']
);

Craft::$app->getQueue()->push(new GenerateExportJob([
    'exportId' => $export->id,
]));
```

Report Manager stores the provider handle, normalized payload, metadata, warnings, status, progress, filename, storage path, and file size on the export record. The payload must be JSON-encodable.

Providers can report progress while generating:

```php
$context->updateProgress(25, 'Collecting rows');
$context->updateProgress(75, 'Writing workbook');
```

Provider results can be:
- `QueuedExportResult::table($headers, $rows)` for CSV, JSON, or XLSX.
- `QueuedExportResult::workbook($sheets)` for multi-sheet XLSX exports.
- `QueuedExportResult::files($files)` for ZIP manifests containing generated contents or readable file paths.

Example multi-sheet workbook result:

```php
return QueuedExportResult::workbook([
    [
        'name' => 'Summary',
        'headers' => ['Metric', 'Value'],
        'rows' => [
            ['Total', 123],
        ],
    ],
    [
        'name' => 'Rows',
        'headers' => ['Date', 'Name', 'Count'],
        'rows' => $rows,
    ],
]);
```

Example ZIP result:

```php
return QueuedExportResult::files([
    [
        'filename' => 'summary.json',
        'contents' => json_encode($summary, JSON_PRETTY_PRINT),
    ],
    [
        'filename' => 'exports/raw.csv',
        'path' => $temporaryCsvPath,
    ],
]);
```

Providers can return `status` and `download` permissions from `getPermissions()` so the source plugin can keep its own CP permission model while Report Manager handles the export file.

```php
public function getPermissions(array $payload): array
{
    return [
        'status' => 'myPlugin:exportReports',
        'download' => 'myPlugin:exportReports',
    ];
}
```

If a permission is omitted, Report Manager falls back to its own export permissions.

### Development Schema Note

While Report Manager is still in active development, existing test databases may need manual schema updates instead of a plugin migration file. Fresh installs receive the current schema from `src/migrations/Install.php`.

For existing dev/test databases, apply the provider export columns if they are missing:

```sql
ALTER TABLE `reportmanager_exports`
  ADD COLUMN `providerHandle` varchar(128) NULL DEFAULT NULL COMMENT 'Queued export provider handle' AFTER `entityName`,
  ADD COLUMN `payload` text NULL DEFAULT NULL COMMENT 'JSON queued export payload' AFTER `providerHandle`,
  ADD COLUMN `metadata` text NULL DEFAULT NULL COMMENT 'JSON queued export metadata' AFTER `payload`,
  ADD COLUMN `warnings` text NULL DEFAULT NULL COMMENT 'JSON queued export warnings' AFTER `metadata`;

CREATE INDEX `idx_reportmanager_exports_providerHandle`
  ON `reportmanager_exports` (`providerHandle`);
```

Skip the SQL if the columns and index already exist. Apply your Craft table prefix if the site uses one.

## Troubleshooting

### Exports Not Generating

1. **Check queue is running:**
   ```bash
   php craft queue/info
   ```

2. **Check for failed jobs:**
   ```bash
   php craft queue/retry-all
   ```

3. **Check logs:**
   ```
   CP → Report Manager → Logs
   ```

4. **Enable debug logging:**
   ```php
   // config/report-manager.php
   return [
       'logLevel' => 'debug',
   ];
   ```

### Export Is Empty (No Rows)

If a report generates a file with no rows:

1. **Check the date range.** For a **Custom Range**, the End Date must be on or after the Start Date. An inverted range (e.g. Start `4/30`, End `3/30`) matches no records. The edit screen now flags this inline and blocks saving, but reports saved before this check may still hold an inverted range — re-open and fix the dates.
2. **Check "Filter by date".** The range is applied to the field chosen here. Entries default to **Post Date** — entries with no Post Date (drafts/pending) won't match a dated range; switch to **Created** to include them. Formie uses the submission date; categories use Created.
3. **Widen the range.** Confirm records actually exist within the selected dates for the chosen data source.
4. **Check the selected items and sites.** If specific items or sites are selected, ensure they contain data for the range.

### Scheduled Reports Not Running

1. Check **Settings → General → Enable Scheduled Reports** is enabled
2. Ensure queue worker is running
3. Check if job exists in queue (Utilities → Queue Manager)

### Scheduled Export Cleanup Missing

Report Manager schedules a recurring queue job for generated export cleanup when automatic cleanup is enabled.

If the cleanup job is missing:

1. Confirm the queue worker is running.
2. Visit any CP page to let Report Manager bootstrap the cleanup job.
3. Check that **Auto Cleanup Exports** is enabled.
4. Check that `exportRetention` is greater than `0`.

### Settings Save Shows a Validation Error

Numeric settings such as maximum export batch size, export retention, and items per page must be whole numbers within the allowed range. If a value is invalid, Report Manager keeps you on the same settings page and shows the field error inline.

When a setting is overridden in `config/report-manager.php`, the Control Panel field is skipped during save. Change the config file value instead.

### XLSX Export Issues

1. Ensure PhpSpreadsheet is installed:
   ```bash
   composer show phpoffice/phpspreadsheet
   ```

2. Check PHP memory limit for large exports
3. Try reducing `maxExportBatchSize` setting

### Storage Permission Errors

1. **Local storage:** Ensure `storage/report-manager/exports/` is writable
2. **Volume storage:** Check volume filesystem permissions and remote provider access rules

## Support

- **Documentation**: [https://github.com/LindemannRock/craft-report-manager](https://github.com/LindemannRock/craft-report-manager)
- **Issues**: [https://github.com/LindemannRock/craft-report-manager/issues](https://github.com/LindemannRock/craft-report-manager/issues)
- **Email**: [support@lindemannrock.com](mailto:support@lindemannrock.com)

## License

This plugin is licensed under the [Craft License](https://craftcms.github.io/license/). See [LICENSE.md](LICENSE.md) for details.

---

Developed by [LindemannRock](https://lindemannrock.com)

# Report Manager for Craft CMS

[![Latest Version](https://img.shields.io/packagist/v/lindemannrock/craft-report-manager.svg)](https://packagist.org/packages/lindemannrock/craft-report-manager)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-5.0+-orange.svg)](https://craftcms.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net/)
[![Logging Library](https://img.shields.io/badge/Logging%20Library-5.0+-green.svg)](https://github.com/LindemannRock/craft-logging-library)
[![License](https://img.shields.io/packagist/l/lindemannrock/craft-report-manager.svg)](LICENSE)

Report generation and export management for Craft CMS with extensible data source support.

## Beta Notice

This plugin is currently in active development and provided under the MIT License for testing purposes.

**Licensing is subject to change.** We are finalizing our licensing structure and some or all features may require a paid license when officially released on the Craft Plugin Store. Some plugins may remain free, others may offer free and Pro editions, or be fully commercial.

If you are using this plugin, please be aware that future versions may have different licensing terms.

## Features

### Report Management
- **Saved Reports** - Create and save report configurations for repeated use
- **Multiple Data Sources** - Extensible architecture starting with Formie integration
- **Field Selection** - Choose which fields to include in exports
- **Date Range Filtering** - Filter data by today, last 7/30/90/365 days, or custom range
- **Multi-Site Support** - Filter exports by site

### Scheduled Reports
- **Automatic Generation** - Schedule reports to run automatically
- **Flexible Scheduling** - Every 6 hours, 12 hours, daily, or weekly
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
- **Combined Exports** - Merge multiple forms/entities into a single export file

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
    'defaultSchedule' => 'daily2am',  // every6hours, every12hours, daily, daily2am, weekly

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

    // Display
    'defaultDateRange' => 'last30days',
    'itemsPerPage' => 50,
    'dashboardRefreshInterval' => 0,  // seconds (0 = disabled)

    // Logging
    'logLevel' => 'error',  // error, warning, info, debug
];
```

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
- Configure path via `exportPath` setting

**Volume Storage:**
1. Create a volume in Craft (Settings → Filesystems → Volumes)
2. Go to **Report Manager → Settings → Export**
3. Select the volume
4. Exports saved to `report-manager/exports/` within the volume

## Scheduled Reports

Report Manager uses Craft's queue system for scheduled report generation.

### How It Works

1. When enabled, the plugin pushes a `ProcessScheduledReportsJob` to the queue
2. The job checks for reports due for generation
3. After processing, it reschedules itself based on your schedule setting
4. Jobs appear in the queue as: **Report Manager: Processing scheduled reports (Jan 24, 3:00am)**

### Queue Worker

Ensure your queue worker is running:

```bash
# Run queue listener
php craft queue/listen

# Or via cron (every minute)
* * * * * /path/to/craft queue/run
```

### Schedule Options

Schedules use **fixed time slots** to prevent drift:

| Setting | Fixed Times |
|---------|-------------|
| `every6hours` | 00:00, 06:00, 12:00, 18:00 |
| `every12hours` | 00:00, 12:00 |
| `daily` | 00:00 (midnight) |
| `daily2am` | 02:00 (default) |
| `weekly` | Monday 00:00 |

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
    public function getHandle(): string;
    public function getName(): string;
    public function getEntities(): array;
    public function getEntity(int $id): ?array;
    public function getEntityFields(int $entityId): array;
    public function exportToArray(int $entityId, array $fieldHandles, array $options): array;
}
```

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

### Scheduled Reports Not Running

1. Check **Settings → General → Enable Scheduled Reports** is enabled
2. Ensure queue worker is running
3. Check if job exists in queue (Utilities → Queue Manager)

### XLSX Export Issues

1. Ensure PhpSpreadsheet is installed:
   ```bash
   composer show phpoffice/phpspreadsheet
   ```

2. Check PHP memory limit for large exports
3. Try reducing `maxExportBatchSize` setting

### Storage Permission Errors

1. **Local storage:** Ensure `storage/report-manager/exports/` is writable
2. **Volume storage:** Check volume filesystem permissions

## Support

- **Documentation**: [https://github.com/LindemannRock/craft-report-manager](https://github.com/LindemannRock/craft-report-manager)
- **Issues**: [https://github.com/LindemannRock/craft-report-manager/issues](https://github.com/LindemannRock/craft-report-manager/issues)
- **Email**: [support@lindemannrock.com](mailto:support@lindemannrock.com)

## License

This plugin is licensed under the MIT License. See [LICENSE](LICENSE) for details.

---

Developed by [LindemannRock](https://lindemannrock.com)

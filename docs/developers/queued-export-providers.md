# Queued Export Providers

@since(5.3.0)

A **queued export provider** lets another plugin push arbitrary data through Report Manager's export pipeline — its queue, progress tracking, status, storage, retention, and download flow — **without** modelling that data as a Report Manager report.

Use this when your plugin already knows what it wants to export (say, an analytics table or a multi-sheet workbook) and just wants Report Manager to handle generating, storing, and serving the file.

> [!NOTE]
> If instead you want users to *define and schedule* reports against your data in Report Manager's UI, build a [Custom Data Source](data-sources.md).

## How It Works

1. Your plugin registers a provider and calls `ExportService::createQueuedExport()` with a payload.
2. Report Manager creates a pending export record and queues a `GenerateExportJob`.
3. When the job runs, it calls your provider's `generate()`, passing a `QueuedExportContext`.
4. Your provider returns a `QueuedExportResult` — a table, a multi-sheet workbook, or a set of files.
5. Report Manager writes the file(s) to storage and the export becomes downloadable, with its own status and progress.

## Implementing a Provider

Extend `BaseQueuedExportProvider` for defaults (`isAvailable() → true`, `supportedFormats() → ['csv', 'json', 'xlsx']`, a `{handle}_{timestamp}.{ext}` filename, and no custom permissions).

```php
<?php

namespace mymodule\export;

use lindemannrock\reportmanager\export\BaseQueuedExportProvider;
use lindemannrock\reportmanager\export\QueuedExportContext;
use lindemannrock\reportmanager\export\QueuedExportResult;

class AnalyticsProvider extends BaseQueuedExportProvider
{
    public static function handle(): string
    {
        return 'my-analytics';
    }

    public static function displayName(): string
    {
        return 'My Analytics Export';
    }

    public function generate(array $payload, QueuedExportContext $context): QueuedExportResult
    {
        $context->updateProgress(10, 'Querying data…');

        $headers = ['Date', 'Visits'];
        $rows = [
            ['2026-06-01', 120],
            ['2026-06-02', 98],
        ];

        $context->updateProgress(100, 'Done');

        return QueuedExportResult::table($headers, $rows);
    }
}
```

## Interface Reference

`lindemannrock\reportmanager\export\QueuedExportProviderInterface`

| Method | Returns | Default (BaseQueuedExportProvider) | Purpose |
|--------|---------|-----------------------------------|---------|
| `handle()` *(static)* | `string` | — | Unique provider identifier |
| `displayName()` *(static)* | `string` | — | Human-readable name |
| `isAvailable()` *(static)* | `bool` | `true` | Whether the provider can be used |
| `supportedFormats()` *(static)* | `array` | `['csv', 'json', 'xlsx']` | Formats the provider can produce |
| `normalizePayload(array $payload)` | `array` | passthrough | Validate/normalize the payload |
| `getExportName(array $payload)` | `string` | `displayName()` | Display name for the export record |
| `getFilename(array $payload, string $format)` | `string` | `{handle}_{timestamp}.{ext}` | Output filename |
| `getPermissions(array $payload)` | `array` | `[]` | Per-operation permission overrides — see below |
| `generate(array $payload, QueuedExportContext $context)` | `QueuedExportResult` | — | Produce the export |

### Provider permissions

`getPermissions()` may return `status` and/or `download` keys mapping to Craft permission strings. When set, Report Manager uses **your** plugin's permissions to gate polling the export's status and downloading it. When omitted, it falls back to Report Manager's own `manageExports` / `downloadExports`.

```php
public function getPermissions(array $payload): array
{
    return [
        'status'   => 'myPlugin:viewAnalytics',
        'download' => 'myPlugin:downloadAnalytics',
    ];
}
```

## QueuedExportContext

Passed to `generate()`:

| Method | Purpose |
|--------|---------|
| `getExport()` | The `ExportRecord` being generated |
| `getFormat()` | The requested format string |
| `updateProgress(int $progress, ?string $message = null)` | Report progress (0–100) and an optional message, shown live on the export detail page |

## QueuedExportResult

Build the result with one of three static constructors:

| Constructor | Result | Output |
|-------------|--------|--------|
| `QueuedExportResult::table($headers, $rows, $recordCount = null, $warnings = [])` | Single table | CSV, JSON, or XLSX |
| `QueuedExportResult::workbook($sheets, $recordCount = null, $warnings = [])` | Multi-sheet workbook | XLSX |
| `QueuedExportResult::files($files, $recordCount = null, $warnings = [])` | Multiple files | ZIP |

**Shapes:**

- **table** — `$headers` is a list of column names; `$rows` is a list of value arrays.
- **workbook** — each sheet is `['name' => ..., 'headers' => [...], 'rows' => [...]]`.
- **files** — each entry is `['filename' => ..., 'contents' => ...]` **or** `['filename' => ..., 'path' => ...]` (read from disk). The set is zipped.

`$warnings` are non-fatal messages surfaced on the export detail page.

> [!NOTE]
> ZIP (from `files()`) is only available through this provider path — it is **not** a format users can pick in the report/export UI, which offers CSV, Excel, and JSON only.

## Registering the Provider

```php
use lindemannrock\reportmanager\events\RegisterQueuedExportProvidersEvent;
use lindemannrock\reportmanager\services\QueuedExportProvidersService;
use yii\base\Event;

Event::on(
    QueuedExportProvidersService::class,
    QueuedExportProvidersService::EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS,
    function(RegisterQueuedExportProvidersEvent $event) {
        $event->register('my-analytics', 'My Analytics Export', AnalyticsProvider::class);
    }
);
```

## Triggering an Export

From your plugin, create the queued export through `ExportService`:

```php
use lindemannrock\reportmanager\ReportManager;

ReportManager::getInstance()->exports->createQueuedExport(
    'my-analytics',   // provider handle
    'xlsx',           // format (must be in supportedFormats())
    $payload,         // arbitrary data your provider understands
    [],               // options
);
```

Report Manager queues and generates it; the resulting file appears in the exports list and is downloadable like any other export.

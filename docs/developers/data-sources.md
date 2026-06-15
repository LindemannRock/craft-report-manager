# Custom Data Sources

A **data source** supplies the entities and records that reports export. Report Manager ships with Formie, Craft Entries, and Craft Categories — but any plugin or module can register its own (Craft users, assets, Commerce orders, log data, etc.).

A custom data source means: implement `DataSourceInterface`, then register it via the `registerDataSources` event.

## Implementing the Interface

Extend `BaseDataSource` rather than implementing `DataSourceInterface` from scratch — it provides sensible defaults (capabilities, UI labels, the legacy `getSubmissions()`/`getSubmissionCount()` aliases) so you only override what your source needs.

```php
<?php

namespace mymodule\datasources;

use lindemannrock\reportmanager\datasources\BaseDataSource;

class OrdersDataSource extends BaseDataSource
{
    public static function handle(): string
    {
        return 'commerce-orders';
    }

    public static function displayName(): string
    {
        return 'Commerce Orders';
    }

    public static function description(): string
    {
        return 'Generate reports from Craft Commerce orders';
    }

    public static function isAvailable(): bool
    {
        return \Craft::$app->plugins->isPluginEnabled('commerce');
    }

    public function getAvailableEntities(): array
    {
        // Return the selectable "entities" — e.g. order statuses
    }

    public function getEntityFields(int $entityId): array
    {
        // Return the exportable fields for an entity
    }

    public function getRecords(int $entityId, array $options = []): array
    {
        // Return the records to export
    }

    public function exportToArray(int $entityId, array $fieldHandles = [], array $options = []): array
    {
        // Return rows shaped for export
    }
}
```

## Interface Reference

`lindemannrock\reportmanager\datasources\DataSourceInterface`

### Static metadata

| Method | Returns | Purpose |
|--------|---------|---------|
| `handle()` | `string` | Unique source identifier |
| `displayName()` | `string` | Human-readable name |
| `description()` | `string` | Short description |
| `uiLabels()` | `array` | Source-specific UI strings (entity singular/plural, selection labels, empty-state messages, date-filter info, etc.) |
| `capabilities()` | `array` | Capability flags — see below |
| `dateFieldOptions()` | `array` | Options for the report's "Filter by date" select @since(5.4.0) |
| `defaultDateField()` | `string` | Default date field @since(5.4.0) |
| `iconUrl()` | `?string` | Optional icon URL |
| `isAvailable()` | `bool` | Whether the source can be used (e.g. a required plugin is installed) |

### Instance methods

| Method | Returns | Purpose |
|--------|---------|---------|
| `getAvailableEntities()` | `array` | The selectable entities |
| `getEntity(int $entityId)` | `?array` | One entity |
| `getEntityFields(int $entityId)` | `array` | Exportable fields for an entity |
| `getRecords(int $entityId, array $options = [])` | `array` | Records to export |
| `getRecordCount(int $entityId, array $options = [])` | `int` | Record count |
| `getAnalytics(int $entityId, string $dateRange = 'last30days')` | `array` | Analytics data |
| `getTrendData(int $entityId, string $dateRange = 'last30days')` | `array` | Trend data for charts |
| `exportToArray(int $entityId, array $fieldHandles = [], array $options = [])` | `array` | Rows shaped for export |
| `getSettingsHtml()` | `?string` | Optional per-source settings UI |

### Capability flags

`capabilities()` returns a flag map. `BaseDataSource` defaults all of these to `true`:

```php
[
    'fields'         => true, // field selection
    'dateRanges'     => true, // date-range filtering
    'analytics'      => true, // analytics + trend data
    'combinedExport' => true, // merge entities into one file
    'siteFiltering'  => true, // limit to specific sites
    'scheduling'     => true, // schedulable
]
```

Override `capabilities()` to turn off anything your source doesn't support — the report UI adapts accordingly.

## Registering the Source

```php
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\services\DataSourcesService;
use yii\base\Event;

Event::on(
    DataSourcesService::class,
    DataSourcesService::EVENT_REGISTER_DATA_SOURCES,
    function(RegisterDataSourcesEvent $event) {
        $event->register('commerce-orders', 'Commerce Orders', OrdersDataSource::class);
    }
);
```

Once registered and `isAvailable()`, your source appears in the **Data Source** dropdown when creating a report.

> [!NOTE]
> For pushing arbitrary data through Report Manager's export queue **without** modelling it as a report, use a [Queued Export Provider](queued-export-providers.md) instead.

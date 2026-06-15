# Events

Report Manager fires two registration events. Both let other plugins or a custom module extend what Report Manager can export. Register handlers from your plugin/module `init()` method.

## Register a Data Source

A data source supplies entities (forms, sections, category groups…) and the records to export from them. Register one with the `registerDataSources` event on `DataSourcesService`.

```php
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\services\DataSourcesService;
use yii\base\Event;

Event::on(
    DataSourcesService::class,
    DataSourcesService::EVENT_REGISTER_DATA_SOURCES,
    function(RegisterDataSourcesEvent $event) {
        $event->register('my-source', 'My Source', \mymodule\datasources\MySource::class);
    }
);
```

The class must implement `DataSourceInterface` (extend `BaseDataSource` for sensible defaults). See [Custom Data Sources](data-sources.md) for the full contract.

## Register a Queued Export Provider

A queued export provider lets another plugin push arbitrary tabular, multi-sheet, or multi-file data through Report Manager's queue, status, and download flow — without modelling its data as a Report Manager "report". Register one with the `registerQueuedExportProviders` event on `QueuedExportProvidersService`.

@since(5.3.0)

```php
use lindemannrock\reportmanager\events\RegisterQueuedExportProvidersEvent;
use lindemannrock\reportmanager\services\QueuedExportProvidersService;
use yii\base\Event;

Event::on(
    QueuedExportProvidersService::class,
    QueuedExportProvidersService::EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS,
    function(RegisterQueuedExportProvidersEvent $event) {
        $event->register('my-provider', 'My Provider', \mymodule\export\MyProvider::class);
    }
);
```

The class must implement `QueuedExportProviderInterface` (extend `BaseQueuedExportProvider` for defaults). See [Queued Export Providers](queued-export-providers.md) for the full contract.

## Event Reference

| Event constant | Class | Fired by | Purpose |
|----------------|-------|----------|---------|
| `DataSourcesService::EVENT_REGISTER_DATA_SOURCES` | `RegisterDataSourcesEvent` | `DataSourcesService` | Register a data source |
| `QueuedExportProvidersService::EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS` | `RegisterQueuedExportProvidersEvent` | `QueuedExportProvidersService` | Register a queued export provider |

Both event classes expose the same `register(string $handle, string $name, string $class)` helper.

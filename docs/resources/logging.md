# Logging

Report Manager writes structured, per-day log files through the required [Logging Library](https://github.com/LindemannRock/craft-logging-library) 5.18.2 or later.

> [!NOTE]
> Logging Library is required by Composer. Install or activate it in Craft to enable log viewing.

```bash title="PHP"
php craft plugin/install logging-library
```

```bash title="DDEV"
ddev craft plugin/install logging-library
```

Or via the Control Panel: **Settings → Plugins → Logging Library → Install**

Use this page when you need to check what Report Manager did: report changes, scheduled-job admission, export generation, storage operations, cleanup, and provider or data-source availability.

## Log levels

Four log levels are available, in order of verbosity:

| Level | What is logged |
|-------|----------------|
| `error` | Critical errors only |
| `warning` | Errors and warnings |
| `info` | General informational messages |
| `debug` | Detailed debugging, including timing and step-by-step diagnostics |

Each level includes all messages from the levels above it. `error` is the least verbose; `debug` is the most.

> [!WARNING]
> Debug level requires Craft's `devMode` to be enabled. If `logLevel` is set to `debug` while `devMode` is disabled, Report Manager falls back to `info` and records a warning. Use `debug` for local development or short diagnostic sessions, because it can create much more log output.

## Configuration

```php
// config/report-manager.php
return [
    'logLevel' => 'error', // 'error', 'warning', 'info', or 'debug'
];
```

For environment-specific logging, keep production quieter and enable debug only where Craft's `devMode` is enabled:

```php
// config/report-manager.php
return [
    '*' => [
        'logLevel' => 'error',
    ],
    'production' => [
        'logLevel' => 'error',
    ],
    'staging' => [
        'logLevel' => 'warning',
    ],
    'dev' => [
        'logLevel' => 'debug',
    ],
];
```

## Log file location

```text
storage/logs/report-manager-YYYY-MM-DD.log
```

Log files are rotated daily. Retention is managed by Logging Library, with a 30-day default.

Logs are written as structured JSON with context data alongside each message, so they can be searched in the Control Panel or ingested by external logging tools.

## Viewing logs in the CP

The **Report Manager → Logs** screen reads, filters, and downloads these log files without leaving the Control Panel.

From there you can:

- Browse log entries for the current and recent days
- Filter by log level
- Search log messages and context
- View file sizes and entry counts
- Download individual log files for external analysis

The `reportManager:viewSystemLogs` permission is required to access the Logs section. The `reportManager:downloadSystemLogs` sub-permission is required to download log files. In the Craft permissions UI, both are nested under the `reportManager:viewLogs` parent group.

## What gets logged

The level of detail depends on your configured `logLevel`.

### Error (`error`)

- Report save, deletion, or reorder failures
- Export generation or queue-admission failures
- Export storage read, stream, deletion, and provider-generation failures

### Warning (`warning`)

- Missing, invalid, or unavailable data sources and queued export providers
- Export-file deletion problems that preserve the record for retry
- Provider workbook cleanup failures
- Invalid configured export paths that fall back to the safe default
- Debug fallback when `logLevel` is set to `debug` without `devMode`

### Info (`info`)

- Reports created, updated, or deleted
- Scheduled report jobs queued or duplicate jobs collapsed
- Separate, combined, and provider exports completed
- Exports deleted and expired exports cleaned up

### Debug (`debug`)

- Report Manager currently emits no plugin-specific debug-only events; this level remains available for shared logging diagnostics and future troubleshooting detail

## Permissions

| Action | Permission |
|--------|------------|
| Access the Logs section in the CP | `reportManager:viewSystemLogs` |
| Download log files | `reportManager:downloadSystemLogs` |
| Logs group (parent, Craft permissions UI only) | `reportManager:viewLogs` |

See [Permissions](../developers/permissions.md) for the full permission hierarchy.

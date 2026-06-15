# Features Overview

Report Manager turns the data in your Craft site into downloadable reports. You define a **report** once — a data source, what to pull from it, a date range, and a format — and Report Manager generates **export files** from it on demand or on a schedule.

> [!TIP]
> New to Report Manager? Start with [Installation](../get-started/installation.md) and the [Quickstart](../get-started/quickstart.md), then come back here for a tour.

## What It Does

A report is a saved definition: *which* data source, *which* entities within it, *which* fields, *which* date range, and the output format. Generating a report produces one or more export files (CSV, Excel, or JSON), stored on the local filesystem or in a Craft asset volume. Every generation is recorded so you can re-download files, see record counts, and track failures. Reports can run automatically on a recurring schedule.

## Core Capabilities

- **[Reports](reports.md)** — Define a report against a data source: pick entities, choose fields, set a date range and the date field to filter on, and select an export format. Export each entity to its own file (**separate**) or merge them into one (**combined**).

- **[Data Sources](data-sources.md)** — Three built-in sources: **Formie** form submissions, **Craft Entries** (by section), and **Craft Categories** (by group). Entries and Categories work on every install; Formie appears when the Formie plugin is enabled. Each source exposes its own entities, fields, date fields, and analytics.

- **[Exports](exports.md)** — Generate files in **CSV**, **Excel (XLSX)**, or **JSON**. Exports run through Craft's queue with live progress. Files are stored locally or in an asset volume, and are auto-cleaned after a retention period you control.

- **[Scheduling](scheduling.md)** — Have a report generate automatically — every 6 hours through to yearly. A master switch in settings gates all scheduled reports; each report then opts in with its own frequency.

## Control Panel

![Report Manager navigation and Dashboard in the Craft Control Panel](images/overview-nav.webp)

Report Manager adds a navigation item with these sections:

| Section | What's there |
|---------|--------------|
| **Dashboard** | A combined view of every generated export across all reports, filterable by status, trigger type, and format |
| **Reports** | The list of report definitions, with **Generate Now** and **View Generated** actions |
| **Settings** | General, Interface, Scheduling, and Export configuration |
| **Logs** | Plugin logs (only when the [Logging Library](https://github.com/LindemannRock/craft-logging-library) is installed) |

A report's generated files are reached from the Reports list via **View Generated**, or the **Generated Files** tab on the report's edit screen.

## For Developers

Report Manager is extensible. Other plugins can add their own data sources, or push arbitrary data through Report Manager's export queue:

- **[Custom Data Sources](../developers/data-sources.md)** — implement `DataSourceInterface` and register it via the `registerDataSources` event.
- **[Queued Export Providers](../developers/queued-export-providers.md)** — implement `QueuedExportProviderInterface` to send table, multi-sheet workbook, or multi-file data through Report Manager's queue/status/download flow. @since(5.3.0)

## Next Steps

1. [Install the plugin](../get-started/installation.md)
2. [Configure it](../get-started/configuration.md)
3. [Create your first report](reports.md)
4. [Generate and download an export](exports.md)
5. [Set up scheduling](scheduling.md)

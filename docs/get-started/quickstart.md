# Quickstart

Get Report Manager running in under 5 minutes. By the end of this guide you'll have a saved report and a generated export file you can download.

## 1. Install the Plugin

See [Installation](installation.md) for full details including DDEV and the optional Logging Library.

```bash title="Composer"
composer require lindemannrock/craft-report-manager && php craft plugin/install report-manager
```

```bash title="DDEV"
ddev composer require lindemannrock/craft-report-manager && ddev craft plugin/install report-manager
```

## 2. Create Your First Report

1. In the control panel, go to **Report Manager → Reports**
2. Click **New Report**
3. Enter a **Name** (e.g., "Monthly Entries")
4. Pick a **Data Source** — **Craft Entries** works on every site with no extra setup
5. Select one or more **Sections** to export
6. In the sidebar, set a **Date Range**, **Export Format** (CSV, Excel, or JSON), and **Export Mode** (separate files per section, or one combined file)
7. Click **Save**

## 3. Generate an Export

From the report's edit screen or the Reports list, choose **Generate Now**. Report Manager queues the export and processes it through Craft's queue.

> [!TIP]
> Exports run on Craft's queue, not inline. If nothing happens, make sure a queue worker is running (`queue/listen` or a cron-driven `queue/run`).

## 4. Download the File

1. Open the report and switch to the **Generated Files** tab (or use **View Generated** from the Reports list)
2. When the export's status shows **Completed**, click **Download**

## What's Next

- [Configuration](configuration.md) — set the default format, storage location, CSV options, and retention
- [Reports](../feature-tour/reports.md) — date ranges, field selection, combined exports, and scheduling
- [Data Sources](../feature-tour/data-sources.md) — Formie submissions, Craft entries, and Craft categories
- [Scheduling](../feature-tour/scheduling.md) — generate reports automatically on a recurring schedule

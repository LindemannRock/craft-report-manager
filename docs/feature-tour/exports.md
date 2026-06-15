# Exports

An **export** is a single generated file produced from a report (or a scheduled run). Every generation is recorded — its format, record count, file size, status, what triggered it, and the exact date range it covered — so you can re-download files and audit what was produced.

## Export Formats

| Format | Notes |
|--------|-------|
| **CSV** | Configurable delimiter, enclosure, and an optional UTF-8 BOM for Excel compatibility. |
| **Excel (XLSX)** | Native spreadsheet output. Combined exports become a multi-sheet workbook. |
| **JSON** | Structured output for programmatic consumption. |

Which formats are offered is controlled by the CSV / JSON / Excel toggles in **Settings → Interface**. The pre-selected format for new reports is set by **Default Export Format** in **Settings → Export**.

## How Generation Works

Exports run through Craft's **queue**, not inline — so large exports don't tie up a web request:

1. You trigger a report (**Generate Now**) or a scheduled run fires.
2. Report Manager creates a **pending** export record and queues a job.
3. A queue worker picks it up; the record moves to **processing** with a live progress percentage.
4. On success the record becomes **completed** with the file written to storage; on error it becomes **failed** with an error message.

> [!TIP]
> Make sure a queue worker is running (`queue/listen`, or a cron-driven `queue/run`). Without it, exports stay **pending**.

### Statuses

| Status | Meaning |
|--------|---------|
| **Pending** | Queued, not yet started |
| **Processing** | Currently generating (shows a progress bar) |
| **Completed** | File written and ready to download |
| **Failed** | Generation failed — see the error message on the detail page |

## Where Files Are Stored

Exports are written either to the **local filesystem** (default: `@storage/report-manager/exports`) or to a **Craft asset volume**. Choose in **Settings → Export**. See [Configuration](../get-started/configuration.md#export-storage) for the storage rules.

## Viewing & Downloading

There are two ways into the generated files:

- **Per report** — open a report and use the **Generated Files** tab, or **View Generated** from the Reports list. This lists just that report's exports.
- **Dashboard** — **Report Manager → Dashboard** shows every export across all reports, filterable by status, trigger type (manual / scheduled / API), and format.

![An export detail page showing status, file details, and the date range used](images/exports-detail.webp)

Open any export to see its **detail page**: status, data source, entity, format, the date range used, file details (filename, records, size), timing (triggered by, created, started, completed), plus any warnings or error message. While an export is still pending or processing, the detail page shows a live progress bar that refreshes automatically. **Download** is available once the file is completed and present.

## Retention & Cleanup

Generated files don't accumulate forever. With **Auto Cleanup Exports** enabled, a daily queue job deletes exports older than the **Export Retention (Days)** value — removing both the file and its record together. Set retention to `0` to keep everything. Configure both in **Settings → Export** (see [Configuration](../get-started/configuration.md#cleanup--retention)).

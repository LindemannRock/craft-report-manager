# Exports

An **export** is a single generated file produced from a report (or a scheduled run). Every generation is recorded — its format, record count, file size, status, what triggered it, and the exact date range it covered — so you can re-download files and audit what was produced.

## Export Formats

| Format           | Notes                                                                                                         |
| ---------------- | ------------------------------------------------------------------------------------------------------------- |
| **CSV**          | Configurable delimiter, enclosure, and an optional UTF-8 BOM for Excel compatibility.                         |
| **Excel (XLSX)** | Native spreadsheet output. Combined exports include a source column that identifies each contributing entity. |
| **JSON**         | Structured output for programmatic consumption.                                                               |

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

| Status         | Meaning                                                      |
| -------------- | ------------------------------------------------------------ |
| **Pending**    | Queued, not yet started                                      |
| **Processing** | Currently generating (shows a progress bar)                  |
| **Completed**  | File written and ready to download                           |
| **Failed**     | Generation failed — see the error message on the detail page |

## Where Files Are Stored

Exports are written either to the **local filesystem** (default: `@storage/report-manager/exports`) or to a **Craft asset volume**. Choose in **Settings → Export**. See [Configuration](../get-started/configuration.md#export-storage) for the storage rules.

Report Manager captures each export's effective storage when it creates the pending record, before its queue job runs. Local records keep their exact absolute path. Volume records keep the exact Craft volume UID and a wrapper-relative path such as `report-manager/exports/example-{record-uid}.csv`. Changing Export settings or `config/report-manager.php` later does not move or redirect an existing export, including one that is still pending in the queue.

Every newly created export gets a record-specific stored object key, even when two exports have the same filename. The filename shown in Report Manager—and supplied to the browser on download—does not change. Deleting or retaining either new export therefore targets only its own object. Completed exports created before this behavior keep their historical recorded paths exactly; Report Manager does not move or rename them.

For a volume record, Report Manager resolves the recorded UID for every later write, availability check, download, deletion, and retention run, then lets Craft apply that volume's current configured subpath. It never substitutes the currently selected volume or falls back to `exportPath`. If the recorded volume or its filesystem is unavailable, the operation fails with an actionable storage error and retries the same UID later, so restoring that exact volume restores access.

Craft Cloud's application filesystem is ephemeral, so its local paths—and volumes backed by local filesystems—are not durable export storage. Use a volume with Craft Cloud's **Cloud** filesystem type; see Craft's [local filesystem guidance](https://craftcms.com/docs/cloud/assets.html#local). The Export settings page evaluates config-file overrides before showing its colored warning. A valid non-local filesystem suppresses only that warning; it does not certify a third-party filesystem as compatible with Craft Cloud. An unavailable-volume error is shown separately and is never classified as a local fallback or durable storage.

An export created before storage identity was recorded can remain **Unresolved storage** after an upgrade. Report Manager keeps that row listed and preserves every possible object: it does not call it missing, guess from current settings, scan volumes, move files, permit download or deletion, or remove it during retention. An administrator can assign a volume only after Craft's Volume wrapper proves the exact recorded object exists there and the row-to-volume match is unambiguous. See [Upgrading pre-release export storage records](../resources/troubleshooting.md#upgrading-pre-release-export-storage-records) for the additive SQL process.

## How Files Are Named

Files generated from a saved report begin with that report's unique handle, followed by the exported entity (or `combined`), the date-range selection, and the generation timestamp:

```text
{report-handle}-{entity-or-combined}-{date-range}-{YYYY-MM-DD-HHmmss}.{format}
```

For example:

```text
fpl-ar-combined-2024-07-25-to-2026-08-18-2026-08-18-150512.xlsx
fpl-en-combined-last-12-months-2026-08-18-143918.xlsx
```

Custom ranges include their exact available boundaries: `2024-07-25-to-2026-08-18`, `from-2024-07-25`, or `through-2026-08-18`. Named ranges use readable stable names such as `last-30-days`, `this-month`, `last-quarter`, or `all`.

Ad-hoc exports have no report handle, so they use the data-source handle as their prefix. Queued export providers continue to own their filenames and may supply a custom name.

If exported fields share a human-facing label, Report Manager keeps the first label and adds stable field-handle suffixes to later columns. Combined exports also reserve their source-identifying column before field headers are resolved. Provider-supplied tables use ordinal suffixes when headers repeat. Unique labels remain exactly as supplied in every format.

## Viewing & Downloading

There are two ways into the generated files:

- **Per report** — open a report and use the **Generated Files** tab, or **View Generated** from the Reports list. This lists just that report's exports.
- **Dashboard** — **Report Manager → Dashboard** shows every export across all reports, filterable by status, trigger type (manual / scheduled / API), and format.

![An export detail page showing status, file details, and the date range used](../images/exports-detail.webp)

Open any export to see its **detail page**: status, data source, entity, format, the date range used, captured storage location, file details (filename, records, size), timing (triggered by, created, started, completed), plus any warnings or error message. While an export is still pending or processing, the detail page shows a live progress bar that refreshes automatically. **Download** is available once the file is completed and present in its recorded storage.

Deleting an export removes its exact recorded local file or its exact wrapper-relative object on the recorded Craft volume, then removes the database record. If that location confirms the file is already absent, Report Manager can still remove the record. If the existence check or deletion fails—for example because storage is unavailable, read-only, or denied—the record and its captured storage identity remain in place for a later retry. Bulk deletion reports partial failures instead of treating the whole selection as deleted.

## Retention & Cleanup

Generated files don't accumulate forever. With **Auto Cleanup Exports** enabled, Report Manager maintains one daily cleanup chain in Craft's queue. Each successful occurrence routes every resolved export through its own captured storage identity and deletes exports older than the **Export Retention (Days)** value. An export counts as deleted only after its exact file was deleted or confirmed already absent and its database record was removed. Failed or uncertain storage operations preserve the record for retry, and unresolved legacy rows remain untouched. Set retention to `0` to keep everything. Configure both options in **Settings → Export** (see [Configuration](../get-started/configuration.md#cleanup--retention)).

The intended run remains the next daily boundary in Craft's configured timezone. Queue backends that limit long delays receive bounded handoff jobs of no more than 900 seconds until the target is close enough; those handoffs only carry the schedule forward and never delete exports. Native database queues and other backends without that limit keep the full delay. This scheduling behavior does not change where generated files are stored or how retention is calculated.

# Reports

A **report** is a saved export definition. Instead of re-specifying what to export every time, you configure it once — a data source, the entities to pull from, the fields to include, a date range, and an output format — then generate files from it on demand or on a schedule.

## Creating a Report

Go to **Report Manager → Reports → New Report**.

![The New Report edit screen with data source, entities, and the export sidebar](images/reports-edit.webp)

### Main fields

| Field | Description |
|-------|-------------|
| **Name** | A descriptive name for the report. |
| **Handle** | A unique identifier, auto-generated from the name and editable. |
| **Description** | Optional notes about the report. |
| **Data Source** | The source of the data — Formie, Craft Entries, or Craft Categories. Changing it reloads the entity list below. See [Data Sources](data-sources.md). |
| **Sites** | *(multi-site only)* Which sites to export. Leave all unchecked to export every site. |
| **Entities** | What to pull from the source — forms, sections, or category groups, depending on the data source. The label and instructions adapt to the selected source. |

An info box under the data source explains exactly how the date filter is applied for that source (for example, "Includes submissions from the selected forms whose date falls within the date range").

### Sidebar fields

| Field | Description |
|-------|-------------|
| **Enabled** | Whether the report is active. Disabled reports don't run on a schedule and are dimmed in the list. |
| **Filter by date** | Which date column the date range filters on. The options depend on the data source (e.g. Submission Date, Post Date, Date Created, Date Updated). |
| **Date Range** | A named range (e.g. Last 30 Days) or **Custom**. |
| **Start Date / End Date** | Shown only when **Custom** is selected. The end date must not be earlier than the start date. |
| **Export Format** | CSV, Excel, or JSON — limited to the formats enabled in Interface settings. |
| **Export Mode** | **Separate Files** (one file per entity) or **Combined File** (all entities in one file). See below. |
| **Schedule** | A switch to run this report automatically. Only appears when scheduled reports are enabled globally. See [Scheduling](scheduling.md). |
| **Frequency** | How often the report runs, when scheduling is on. |

### Report Actions

Existing reports have a **Report Actions** menu with **Generate Now** and **View Generated**. The read-only sidebar also shows the report's ID, status, format, mode, entity count, last generated time, next scheduled time, and created/updated timestamps.

## Separate vs Combined Exports

**Export Mode** controls how multiple entities are written:

- **Separate Files** — each selected entity produces its own export file. Best when you want one CSV per form or per section.
- **Combined File** — all selected entities are merged into a single file, with a primary column identifying which entity each row came from.

## Date Filtering

Two settings work together:

1. **Filter by date** chooses *which* date column to filter on (the available columns come from the data source).
2. **Date Range** chooses *the window* — a named range like "Last 30 Days", or a custom start/end.

Only records whose chosen date column falls inside the range are exported. The actual range used is recorded on each generated export so you can see exactly what a file covered.

## The Reports List

**Report Manager → Reports** lists every report with sortable columns for Name, Source, Format, Schedule, Last Run, Next Run, and status, plus a **Files** count linking to that report's generated files. Filter by status (enabled/disabled) or format, or search by name.

**Row actions:** Edit, View Generated, Generate Now, Delete.

**Bulk actions:** Enable, Disable, and Delete across selected reports.

## Generating Files

Use **Generate Now** from the report edit screen or the Reports list to queue an export immediately. Reports with scheduling enabled also generate automatically. Either way, the output is recorded under the report's **Generated Files** — see [Exports](exports.md).

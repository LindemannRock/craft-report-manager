# Scheduling

Reports can generate automatically on a recurring schedule, so a fresh export is always waiting without anyone clicking **Generate Now**.

## Two Levels of Control

![The Schedule switch and Frequency in a report's sidebar](images/scheduling-sidebar.webp)

Scheduling is gated by a global switch *and* a per-report switch — both must be on for a report to run automatically:

1. **Global** — **Settings → Scheduling → Enable Scheduled Reports**. The master switch. When off, no report runs on a schedule, regardless of its own setting. (Manual generation and export cleanup are unaffected.)
2. **Per report** — the **Schedule** switch and **Frequency** in the report's sidebar. Only shown when the global switch is on.

The **Default Schedule** setting determines the frequency pre-selected for new reports.

## Frequencies

| Value | Runs |
|-------|------|
| `disabled` | Not scheduled |
| `every6hours` | Every 6 hours |
| `every12hours` | Every 12 hours |
| `daily` | Once a day |
| `daily2am` | Daily at 2:00 AM |
| `weekly` | Once a week |
| `monthly` | Once a month |
| `every2months` | Every 2 months |
| `quarterly` | Every 3 months |
| `every6months` | Every 6 months |
| `yearly` | Once a year |

## How It Runs

Report Manager queues one job per enabled, scheduled report. When a report's job fires it generates the export(s), records the run, recalculates the report's **next scheduled** time, and re-queues itself for that next run. You can see a report's **Last Run** and **Next Run** in the Reports list and in the report sidebar.

> [!TIP]
> Scheduled jobs run on Craft's queue. A queue worker must be running for scheduled reports to fire on time. The exports they produce follow the same queue, status, and retention flow as manual exports — see [Exports](exports.md).

Turning the global switch off removes the queued scheduled-report jobs; turning it back on re-queues them.

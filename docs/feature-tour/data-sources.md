# Data Sources

A **data source** is where a report's data comes from. Each source defines its own *entities* (the things you select in a report), the *records* exported from them, which *date fields* you can filter on, and what *fields* are available to include.

Report Manager ships with three data sources:

| Source | Handle | Entities | Records | Available |
|--------|--------|----------|---------|-----------|
| **Craft Entries** | `entries` | Sections | Entries | Always |
| **Craft Categories** | `categories` | Category groups | Categories | Always |
| **Formie** | `formie` | Forms | Submissions | When Formie is installed and enabled |

When you pick a source in a report, its entity list, date-field options, and labels load automatically.

![Selecting a data source and its entities on the report edit screen](images/data-sources-selection.webp)

## Craft Entries

> *Generate reports from Craft entries.*

- **Entities:** Craft sections (with entry counts).
- **Records:** entries in the selected sections (all statuses).
- **Filter by date:** Post Date *(default)*, Date Created, Date Updated.
- **Exported system fields:** Entry ID, Title, Slug, URI, Status, Site ID, Site Handle, Site Name, Date Created, Date Updated, Post Date, Expiry Date — plus the custom fields from the section's entry types.

Site ID, Site Handle, and Site Name are included on every row, which makes exports useful as Feed Me-style migration source files.

## Craft Categories

> *Generate reports from Craft categories.*

- **Entities:** category groups (with category counts).
- **Records:** categories in the selected groups (all statuses).
- **Filter by date:** Date Created *(default)*, Date Updated.
- **Exported system fields:** ID, UID, Title, Slug, URI, Status, Site ID, Site Handle, Site Name, Level, Parent — plus the group's custom fields.

## Formie

> *Generate reports from Formie form submissions.*

The Formie source appears only when the [Formie](https://verbb.io/craft-plugins/formie) plugin is installed and enabled.

- **Entities:** Formie forms.
- **Records:** submissions (excluding incomplete and spam submissions).
- **Filter by date:** Submission Date *(default)*, Date Updated.
- **Exported system fields:** ID, Date Created, Status, IP Address — plus every field value on the form.
- **Analytics:** submission counts with period-over-period change, totals (submissions, spam, incomplete), and average per day.

## Shared Capabilities

All three built-in sources support the same feature set:

| Capability | Meaning |
|------------|---------|
| **Fields** | Choose which fields to include in the export |
| **Date ranges** | Filter records by a named or custom date range |
| **Analytics** | Period stats and trend data |
| **Combined export** | Merge multiple entities into one file |
| **Site filtering** | Limit a report to specific sites (multi-site) |
| **Scheduling** | Run on a recurring schedule |

## Adding Your Own

Data sources are an extension point. Other plugins or a custom module can register additional sources — Craft users, assets, Commerce orders, or anything else — by implementing `DataSourceInterface` and listening to the `registerDataSources` event. See [Custom Data Sources](../developers/data-sources.md).

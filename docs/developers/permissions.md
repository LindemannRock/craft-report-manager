# Permissions

Report Manager registers granular permissions that can be assigned to user groups via **Settings → Users → User Groups → [Group Name] → Report Manager**.

## Permission Structure

### Dashboard

| Permission | Description |
|------------|-------------|
| `reportManager:viewDashboard` | View the dashboard (the export overview across all reports) |

### Reports

| Permission | Description |
|------------|-------------|
| **`reportManager:manageReports`** | Access the Reports section |
| └─ `reportManager:createReports` | Create new reports |
| └─ `reportManager:editReports` | Edit and reorder existing reports |
| └─ `reportManager:deleteReports` | Delete reports |

### Exports

| Permission | Description |
|------------|-------------|
| **`reportManager:manageExports`** | View generated exports |
| └─ `reportManager:createExports` | Generate exports (manually run a report) |
| └─ `reportManager:downloadExports` | Download generated export files |
| └─ `reportManager:deleteExports` | Delete generated exports |

### Logs

| Permission | Description |
|------------|-------------|
| **`reportManager:viewLogs`** | View Logs (parent) |
| └─ **`reportManager:viewSystemLogs`** | View plugin system logs in the Control Panel |
| &nbsp;&nbsp;&nbsp;└─ `reportManager:downloadSystemLogs` | Download system log files |

Log permissions require the [Logging Library](https://github.com/LindemannRock/craft-logging-library) to be installed and enabled.

### Settings

| Permission | Description |
|------------|-------------|
| `reportManager:manageSettings` | Manage plugin settings |

## Checking Permissions

In Twig:

```twig
{% if currentUser.can('reportManager:manageReports') %}
    {# User can access reports #}
{% endif %}
```

In PHP:

```php
if (Craft::$app->getUser()->checkPermission('reportManager:createExports')) {
    // User can generate exports
}

// In a controller
$this->requirePermission('reportManager:manageReports');
```

## Nested Permission Pattern

Craft's nested permissions are a UI convenience — **granting a parent does not automatically grant its children**. Each operation is gated independently.

- **`manageReports`** / **`manageExports`** are the parents that grant access to each section.
- The nested write permissions (`create`, `edit`, `delete`, `download`) each gate a specific operation.

For example, to let an editor run reports and download the results but not change report definitions, grant `manageReports` + `manageExports` + `createExports` + `downloadExports`, and leave `editReports` / `deleteReports` unchecked.

# Twig Globals

Report Manager provides the following global variables in your Twig templates.

## `reportHelper`

*Provided by `lindemannrock/base`*

| Property | Description |
|----------|-------------|
| `reportHelper.displayName` | Display name (singular, without "Manager") |
| `reportHelper.pluralDisplayName` | Plural display name (without "Manager") |
| `reportHelper.fullName` | Full plugin name (as configured) |
| `reportHelper.lowerDisplayName` | Lowercase display name (singular) |
| `reportHelper.pluralLowerDisplayName` | Lowercase plural display name |

### Examples

```twig
{{ reportHelper.displayName }}
{{ reportHelper.pluralDisplayName }}
{{ reportHelper.fullName }}
{{ reportHelper.lowerDisplayName }}
{{ reportHelper.pluralLowerDisplayName }}
```

---


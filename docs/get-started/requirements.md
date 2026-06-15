# Requirements

## System Requirements

| Requirement | Version |
|-------------|---------|
| [Craft CMS](https://craftcms.com/) | 5.0+ |
| [PHP](https://php.net/) | 8.2+ |

## Dependencies

Composer pulls these packages automatically. Craft plugin dependencies also need to be installed in the Control Panel.

| Package | Version | Purpose |
|---------|---------|---------|
| [lindemannrock/craft-plugin-base](https://github.com/LindemannRock/craft-plugin-base) | 5.0+ | Shared base plugin utilities (helpers, traits, layouts) |
| [lindemannrock/craft-logging-library](https://github.com/LindemannRock/craft-logging-library) | 5.0+ | Optional — install in CP for log viewing |

## Optional Integrations

| Package | Version | Purpose |
|---------|---------|---------|
| [verbb/formie](https://verbb.io/craft-plugins/formie) | 3.0+ | Enables the Formie form-submissions data source |

The Formie data source only appears when Formie is installed and enabled. The built-in Craft Entries and Craft Categories data sources work on every Craft install without any optional packages.

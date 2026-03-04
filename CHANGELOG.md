# Changelog

## [5.1.8](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.7...v5.1.8) (2026-03-04)


### Bug Fixes

* **jobs:** implement RetryableJobInterface and canRetry method ([98be7ce](https://github.com/LindemannRock/craft-report-manager/commit/98be7cea0b87a372583f768da403a7877489e476))
* **settings:** enhance settings validation and error handling ([9fd615e](https://github.com/LindemannRock/craft-report-manager/commit/9fd615ecdd9f81343a17ab413d06a27e1837b0fb))

## [5.1.7](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.6...v5.1.7) (2026-02-23)


### Bug Fixes

* **permissions:** update report permissions for manage access ([59cb5c1](https://github.com/LindemannRock/craft-report-manager/commit/59cb5c19909e47abc8c9506586b7e567ee2ac5f3))
* **settings:** validate and sanitize settings section parameter ([458d193](https://github.com/LindemannRock/craft-report-manager/commit/458d1932d85c88fac1586775a5db184a30e86002))

## [5.1.6](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.5...v5.1.6) (2026-02-22)


### Miscellaneous Chores

* add .gitattributes with export-ignore for Packagist distribution ([5dd1da9](https://github.com/LindemannRock/craft-report-manager/commit/5dd1da99730164481586bbf75d4e3f5af1a9b1dd))
* switch to Craft License for commercial release ([a1a6562](https://github.com/LindemannRock/craft-report-manager/commit/a1a6562d9ae04d3d1562649ef72a1b1ff111b9c2))

## [5.1.5](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.4...v5.1.5) (2026-02-05)


### Bug Fixes

* **ReportManager:** update [@since](https://github.com/since) version for getCpSections method to 5.2.0 ([8b1729d](https://github.com/LindemannRock/craft-report-manager/commit/8b1729dd14528e492526a0af222081e5b9bb484e))

## [5.1.4](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.3...v5.1.4) (2026-01-28)


### Code Refactoring

* **ReportManager:** enhance logging bootstrap with color configurations ([6f37191](https://github.com/LindemannRock/craft-report-manager/commit/6f3719145a73837116a2e927bed825f1a113ea97))

## [5.1.3](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.2...v5.1.3) (2026-01-26)


### Bug Fixes

* **jobs:** prevent duplicate scheduling of ProcessScheduledReportsJob ([947be57](https://github.com/LindemannRock/craft-report-manager/commit/947be57cb6abed3dbe791b24b8725bff1c622dc9))
* permission-based settings, dynamic data source names, and scheduled report improvements ([5f5ad94](https://github.com/LindemannRock/craft-report-manager/commit/5f5ad94b0ee478d80ad0bc541893d36e2030c73f))

## [5.1.2](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.1...v5.1.2) (2026-01-24)


### Bug Fixes

* update settings permissions to use 'reportManager:manageSettings' ([665357d](https://github.com/LindemannRock/craft-report-manager/commit/665357d2185477377326cd9b71a29fde7c00954b))

## [5.1.1](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.0...v5.1.1) (2026-01-24)


### Bug Fixes

* add triggeredBy parameter for scheduled report exports ([04eb7c4](https://github.com/LindemannRock/craft-report-manager/commit/04eb7c4a69ad94b230e5955f4553c6c3d384c3c6))

## [5.1.0](https://github.com/LindemannRock/craft-report-manager/compare/v5.0.0...v5.1.0) (2026-01-24)


### Features

* add download link for completed report exports in generated files table ([12a150c](https://github.com/LindemannRock/craft-report-manager/commit/12a150c0725f193ae48fa9d02187f1423ff0fa7a))
* enhance scheduling logic for report exports with fixed time slots ([713a64c](https://github.com/LindemannRock/craft-report-manager/commit/713a64c266dbdaf044d5f1cd4d115319589447b0))

## 5.0.0 (2026-01-24)


### Features

* initial Report Manager plugin implementation ([4a71a31](https://github.com/LindemannRock/craft-report-manager/commit/4a71a3122bbb476d4379852899f2efcafb3b515f))

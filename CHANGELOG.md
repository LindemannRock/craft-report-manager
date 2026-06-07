# Changelog

## [5.4.0](https://github.com/LindemannRock/craft-report-manager/compare/v5.3.0...v5.4.0) (2026-06-07)


### Added

* add additional options for default date range in config ([faf3e7d](https://github.com/LindemannRock/craft-report-manager/commit/faf3e7d11c20f2f73a5865764a12a6e33f6ab292))
* add date filtering options and info box to report edit form ([58c9674](https://github.com/LindemannRock/craft-report-manager/commit/58c96747276c37cd5866bb48e90bba524f85ef08))
* add date filtering options for categories, entries, and form submissions ([9e66b91](https://github.com/LindemannRock/craft-report-manager/commit/9e66b9100e78f128af88cbf7b34ef01e73b8518b))
* add dateField property and validation to report records ([6866bb0](https://github.com/LindemannRock/craft-report-manager/commit/6866bb0c8a331ac5c5bd23c162bf33b29c015f15))
* add dateFieldUsed property to export records ([8d9f273](https://github.com/LindemannRock/craft-report-manager/commit/8d9f2730b10106fcc760964e37cebf78efe6de9b))
* add export format and mode display to report details ([02500df](https://github.com/LindemannRock/craft-report-manager/commit/02500df94ec898f1c95a1343ea48b269898576f7))
* add handle column to reports table with hideable option ([ecefe5b](https://github.com/LindemannRock/craft-report-manager/commit/ecefe5b42459820799aee57174707d1621b56051))
* add handle uniqueness check for new report records ([8f958af](https://github.com/LindemannRock/craft-report-manager/commit/8f958af05fa815d771e00279d77efaf17189dc58))
* add plugin credit component to export and edit templates ([033f201](https://github.com/LindemannRock/craft-report-manager/commit/033f201f3fc5c2d358825ccd696a393952962314))
* add static analysis script for CI workflow ([a7123c3](https://github.com/LindemannRock/craft-report-manager/commit/a7123c355972a76401a5fa97c3dd43b977b8f529))
* add unique handle validation for report records ([bb1eacf](https://github.com/LindemannRock/craft-report-manager/commit/bb1eacf628907beb70c6777c2aeb0f21f2b9c9ad))
* add unique handle validation method to report records ([349ae8a](https://github.com/LindemannRock/craft-report-manager/commit/349ae8ab13aee44576576e9e55537ba520d813c5))
* **controllers:** add date field options and default date field to reports ([0d59bfd](https://github.com/LindemannRock/craft-report-manager/commit/0d59bfd86290b95d4aeb0450c06c51d5f1384747))
* **controllers:** add permissions for report creation and access to generated exports ([1b0a888](https://github.com/LindemannRock/craft-report-manager/commit/1b0a888628302b143866221d02e034e129a809a5))
* **i18n:** add new translation keys for report creation and updates ([2c66263](https://github.com/LindemannRock/craft-report-manager/commit/2c662639ea5226cc6e88f8e455c4fda6aeca289e))
* **i18n:** add new validation and date filter messages in multiple locales ([fc8dd99](https://github.com/LindemannRock/craft-report-manager/commit/fc8dd9987ba58d3275f6e1a1b59f5116b68801f8))
* **i18n:** add unique handle validation message in multiple languages ([7d3a58d](https://github.com/LindemannRock/craft-report-manager/commit/7d3a58dc513e7a7359aa1d5f68a6fbe5f1adc5e6))
* **i18n:** add validation message for end date requirement across translations ([1f31365](https://github.com/LindemannRock/craft-report-manager/commit/1f31365889458ae4c9d0689d399145f17880859a))
* **i18n:** update translations for site and category terms ([03b3cff](https://github.com/LindemannRock/craft-report-manager/commit/03b3cff87d3c7f45725cb3266a32308146a09b42))
* **jobs:** add scheduleNextExportCleanupJob for automated cleanup ([97e625f](https://github.com/LindemannRock/craft-report-manager/commit/97e625f98faf137cb3632e07549a505f7f24b31b))
* **migrations:** add dateField and dateFieldUsed to report and export tables ([fd1e0c2](https://github.com/LindemannRock/craft-report-manager/commit/fd1e0c20e3dc3441a0f0598ad72eccf7715ff78c))
* **queue:** add pending export cleanup job check and refactor logic ([ace1f0e](https://github.com/LindemannRock/craft-report-manager/commit/ace1f0eb87a02d32c02c7d5f0913df36f7ebbc8e))
* **schedule:** add schedule options for report generation ([01bd189](https://github.com/LindemannRock/craft-report-manager/commit/01bd1896ade847f399db4a57c74b53347f9f6423))
* **settings:** add attribute labels for report generation settings ([d025969](https://github.com/LindemannRock/craft-report-manager/commit/d0259697954eb5cba3bb721d45d5041de37022e4))
* **settings:** add schedule options for report generation ([30d6ee7](https://github.com/LindemannRock/craft-report-manager/commit/30d6ee7e608d4e17b0a0d226d50866e3ca7af07f))
* **settings:** add storage path and volume validation for exports ([6cdc42d](https://github.com/LindemannRock/craft-report-manager/commit/6cdc42d2b15e33e8e7484fee4c5be59bec6e60ee))
* **settings:** replace settings handling with SettingsPostHelper ([ce39577](https://github.com/LindemannRock/craft-report-manager/commit/ce39577a033f74c5ffab5988b97fb64fd464b193))
* **tests:** add date field filter tests for entries and categories ([097adde](https://github.com/LindemannRock/craft-report-manager/commit/097adde40ea5d6c9b5a5a16ea8a7f0b41f439aa1))
* **tests:** add SchedulerPatternTest for export cleanup functionality ([3c45267](https://github.com/LindemannRock/craft-report-manager/commit/3c452676101cfd116ef6b28e09ae0b1c561b84b9))


### Fixed

* correct date formatting for created timestamps in reports ([fb26d18](https://github.com/LindemannRock/craft-report-manager/commit/fb26d187d0a2569906d1a65b0c5f3306d613c446))
* correct permission error message for export access ([a7942d7](https://github.com/LindemannRock/craft-report-manager/commit/a7942d783a13e93a8c79d6ea402b68737f845ce1))
* **i18n:** correct Portuguese translation for export processing message ([eb966da](https://github.com/LindemannRock/craft-report-manager/commit/eb966da6e2f797ce8fa63fc4002c125a406eb1fa))
* **i18n:** correct Portuguese translation for report deletion confirmation ([505bbf8](https://github.com/LindemannRock/craft-report-manager/commit/505bbf8e66be975abcbf61131ec2fe466ce76f1f))
* **i18n:** correct Portuguese translations for logs and records ([e0d4a11](https://github.com/LindemannRock/craft-report-manager/commit/e0d4a11b3462108505c225528cdfeee038f29f4d))
* **i18n:** correct punctuation in Japanese translation strings ([c0c94e4](https://github.com/LindemannRock/craft-report-manager/commit/c0c94e44db523222622661c38bc19e7a55765c29))
* **i18n:** correct translation keys for report timestamps ([ab47f7e](https://github.com/LindemannRock/craft-report-manager/commit/ab47f7e739e25c99780c543acb7c1d2a821b8968))
* **i18n:** correct translations ([0b7bf93](https://github.com/LindemannRock/craft-report-manager/commit/0b7bf93350e0701d3894b032280318f489dbb130))

## [5.3.0](https://github.com/LindemannRock/craft-report-manager/compare/v5.2.1...v5.3.0) - 2026-05-22


### Added

* add issue templates for bug reports, feature requests, and questions ([a0deb51](https://github.com/LindemannRock/craft-report-manager/commit/a0deb51c40bc05a8400d2a5db708e2845a6934f0))
* add pre-commit hook for ECS and PHPStan code quality checks ([6a2d2bd](https://github.com/LindemannRock/craft-report-manager/commit/6a2d2bd60faef5aa73389ae3eeb16afa7abc89d8))
* **config:** add detailed export and interface settings documentation ([181eabe](https://github.com/LindemannRock/craft-report-manager/commit/181eabe0fc756f5780510364bf063ad519492fd5))
* **dashboard:** add details link for export items in dashboard menu ([c85dcc7](https://github.com/LindemannRock/craft-report-manager/commit/c85dcc7fd1bb8033919a97a06361b4f00541a730))
* **dashboard:** add file availability checks for exports and reports ([dd3d3a9](https://github.com/LindemannRock/craft-report-manager/commit/dd3d3a97d37d65367f85c1a5ca48319dc9825e61))
* **data-sources:** add craft-native export sources ([74d97dc](https://github.com/LindemannRock/craft-report-manager/commit/74d97dc663c9460766852e98f845e07c17e41b06))
* **data-sources:** generalize source contract ([ce67629](https://github.com/LindemannRock/craft-report-manager/commit/ce676291669af50f85f151d86fa700477590daf2))
* **export:** add combinedEntityIds property for combined exports support ([6d309dd](https://github.com/LindemannRock/craft-report-manager/commit/6d309dd3f00dc23729a6c6fdc412d353439d004e))
* **export:** implement queued export functionality with provider support ([52ef31a](https://github.com/LindemannRock/craft-report-manager/commit/52ef31ab365d498c9d17a56e265e2cdedc8f1431))
* **i18n:** add 'Unknown' translation key ([5945d12](https://github.com/LindemannRock/craft-report-manager/commit/5945d127cdb6c1441748e6f365eb247e76492930))
* **i18n:** add error message for invalid date input ([2a85b79](https://github.com/LindemannRock/craft-report-manager/commit/2a85b79b28473e7a23cde32ebf3d319d6e48772a))
* **i18n:** add message for displaying latest generated files count ([be6b7da](https://github.com/LindemannRock/craft-report-manager/commit/be6b7da77ea76c14cff4fd5dec3e93f395447226))
* **i18n:** add new keys for item and record translations in multiple languages ([2f49fb7](https://github.com/LindemannRock/craft-report-manager/commit/2f49fb732c9507939b45df1a7d78fc4e20f25b37))
* **i18n:** add new keys for report deletion and export messages in multiple languages ([0a4ca7e](https://github.com/LindemannRock/craft-report-manager/commit/0a4ca7e099ab7f93f4205f746ca6894db268426e))
* **i18n:** add new translation keys and update existing translations for multiple languages ([09edb2b](https://github.com/LindemannRock/craft-report-manager/commit/09edb2bdd8269235a418f3d675b2103807836695))
* **i18n:** add translation issue template for reporting localization problems ([eeaccab](https://github.com/LindemannRock/craft-report-manager/commit/eeaccab0863e4c45ae17ba818b15355f8a7d9492))
* **i18n:** add translations for 11 languages ([fe29169](https://github.com/LindemannRock/craft-report-manager/commit/fe29169fe7e66b7491cdbec1f5175a2edf1d9a8f))
* **queue:** implement scheduled report and export cleanup jobs ([bd3fe08](https://github.com/LindemannRock/craft-report-manager/commit/bd3fe08b3965a62cb020118572175c5284870ab6))
* **reports:** add generated files section and export details to report view ([cbc84e6](https://github.com/LindemannRock/craft-report-manager/commit/cbc84e674c4afd982afa8fff94c79e96d804d1b9))
* **ReportsController:** add data source labels to generated report template ([4824205](https://github.com/LindemannRock/craft-report-manager/commit/482420547330826c424216d3a81f6871fa2de0e3))
* **reports:** enhance report management with bulk actions and improved UI ([6e29519](https://github.com/LindemannRock/craft-report-manager/commit/6e295198b86156ef14af89b6cd17a843ec88c470))
* **settings:** add interface and scheduling settings pages ([1d93726](https://github.com/LindemannRock/craft-report-manager/commit/1d937266c0fcf5ee4ed31a792a58b4ab9b9c142a))
* **settings:** add new configurable options for date and export formats ([049dd49](https://github.com/LindemannRock/craft-report-manager/commit/049dd499c97d7e6588ea5ece52e64b43f10fb233))
* **tests:** add integration tests for queued export functionality ([abd6d12](https://github.com/LindemannRock/craft-report-manager/commit/abd6d12cc73b6a0291a0075820d97a6e5c3a35e5))
* **translations:** add new installation experience messages ([0880cd7](https://github.com/LindemannRock/craft-report-manager/commit/0880cd75684f3a82d8336622cdfdd826035cd6da))
* **translations:** update export retention and cleanup instructions ([ddbd624](https://github.com/LindemannRock/craft-report-manager/commit/ddbd62441f035226ce15ff6c0a08d4d13f47038a))


### Fixed

* correct error message display for export generation failure ([d668434](https://github.com/LindemannRock/craft-report-manager/commit/d6684342a6a91108352a7d1d9678c11981507e52))
* correct schema version to match plugin release ([78f3d2f](https://github.com/LindemannRock/craft-report-manager/commit/78f3d2f5a4a98717a1e5179e4614e2fe0b4f5630))
* **i18n:** correct translation for export entity name display ([58dbb37](https://github.com/LindemannRock/craft-report-manager/commit/58dbb37612613f6edeef3f97dea83b7d962c71f9))
* **i18n:** remove obsolete translation keys from multiple locales ([16e5377](https://github.com/LindemannRock/craft-report-manager/commit/16e53776284b8061a012a7763ac68cea8a3ad39e))
* **i18n:** remove processed report messages from translations ([0b5666c](https://github.com/LindemannRock/craft-report-manager/commit/0b5666ccb8a31865c3ba1ff6984e9347b423250b))
* **settings:** correct error message for saving settings ([1268101](https://github.com/LindemannRock/craft-report-manager/commit/1268101148f0406d4a14c16ac18c82fcbf871139))

## [5.2.1](https://github.com/LindemannRock/craft-report-manager/compare/v5.2.0...v5.2.1) - 2026-05-06


### Bug Fixes

* apply config overrides through shared settings helper ([c5e7676](https://github.com/LindemannRock/craft-report-manager/commit/c5e767691d99c63cebf5a8c2741987c3ee57ae3c))
* clarify plugin name description in settings model ([67cb6e0](https://github.com/LindemannRock/craft-report-manager/commit/67cb6e0254cab2e5e13b9cafb2d99411fb59a8ff))
* **config:** clarify default date range setting in config file ([c1c47b1](https://github.com/LindemannRock/craft-report-manager/commit/c1c47b14b64eaca897b0ed83c0933cd7d7e0bfea))
* drop PAT requirement for release-please — use built-in GITHUB_TOKEN ([a0e2cdb](https://github.com/LindemannRock/craft-report-manager/commit/a0e2cdbbe285f77028cd6d95624a7fcdea940024))
* update version in config file to 5.1.5 ([c9345ea](https://github.com/LindemannRock/craft-report-manager/commit/c9345ea6fac40e809e784de7c3bcda226e141a5f))

## [5.2.0](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.8...v5.2.0) - 2026-04-05


### Features

* **icon:** replace old icon with new SVG design ([4e91d7d](https://github.com/LindemannRock/craft-report-manager/commit/4e91d7d7993faaab0757645ead924d64d426650f))


### Bug Fixes

* **ReportManager:** settings response methods and add read-only support ([f4bbdb2](https://github.com/LindemannRock/craft-report-manager/commit/f4bbdb265fc3d0d4839345200631e7191a399c27))
* **settings:** remove submit button from export and general settings forms ([c7eebee](https://github.com/LindemannRock/craft-report-manager/commit/c7eebee6a104a0ea014b9801c63b47c5de52e098))

## [5.1.8](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.7...v5.1.8) - 2026-03-04


### Bug Fixes

* **jobs:** implement RetryableJobInterface and canRetry method ([98be7ce](https://github.com/LindemannRock/craft-report-manager/commit/98be7cea0b87a372583f768da403a7877489e476))
* **settings:** enhance settings validation and error handling ([9fd615e](https://github.com/LindemannRock/craft-report-manager/commit/9fd615ecdd9f81343a17ab413d06a27e1837b0fb))

## [5.1.7](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.6...v5.1.7) - 2026-02-23


### Bug Fixes

* **permissions:** update report permissions for manage access ([59cb5c1](https://github.com/LindemannRock/craft-report-manager/commit/59cb5c19909e47abc8c9506586b7e567ee2ac5f3))
* **settings:** validate and sanitize settings section parameter ([458d193](https://github.com/LindemannRock/craft-report-manager/commit/458d1932d85c88fac1586775a5db184a30e86002))

## [5.1.6](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.5...v5.1.6) - 2026-02-22


### Miscellaneous Chores

* add .gitattributes with export-ignore for Packagist distribution ([5dd1da9](https://github.com/LindemannRock/craft-report-manager/commit/5dd1da99730164481586bbf75d4e3f5af1a9b1dd))
* switch to Craft License for commercial release ([a1a6562](https://github.com/LindemannRock/craft-report-manager/commit/a1a6562d9ae04d3d1562649ef72a1b1ff111b9c2))

## [5.1.5](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.4...v5.1.5) - 2026-02-05


### Bug Fixes

* **ReportManager:** update [@since](https://github.com/since) version for getCpSections method to 5.2.0 ([8b1729d](https://github.com/LindemannRock/craft-report-manager/commit/8b1729dd14528e492526a0af222081e5b9bb484e))

## [5.1.4](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.3...v5.1.4) - 2026-01-28


### Code Refactoring

* **ReportManager:** enhance logging bootstrap with color configurations ([6f37191](https://github.com/LindemannRock/craft-report-manager/commit/6f3719145a73837116a2e927bed825f1a113ea97))

## [5.1.3](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.2...v5.1.3) - 2026-01-26


### Bug Fixes

* **jobs:** prevent duplicate scheduling of ProcessScheduledReportsJob ([947be57](https://github.com/LindemannRock/craft-report-manager/commit/947be57cb6abed3dbe791b24b8725bff1c622dc9))
* permission-based settings, dynamic data source names, and scheduled report improvements ([5f5ad94](https://github.com/LindemannRock/craft-report-manager/commit/5f5ad94b0ee478d80ad0bc541893d36e2030c73f))

## [5.1.2](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.1...v5.1.2) - 2026-01-24


### Bug Fixes

* update settings permissions to use 'reportManager:manageSettings' ([665357d](https://github.com/LindemannRock/craft-report-manager/commit/665357d2185477377326cd9b71a29fde7c00954b))

## [5.1.1](https://github.com/LindemannRock/craft-report-manager/compare/v5.1.0...v5.1.1) - 2026-01-24


### Bug Fixes

* add triggeredBy parameter for scheduled report exports ([04eb7c4](https://github.com/LindemannRock/craft-report-manager/commit/04eb7c4a69ad94b230e5955f4553c6c3d384c3c6))

## [5.1.0](https://github.com/LindemannRock/craft-report-manager/compare/v5.0.0...v5.1.0) - 2026-01-24


### Features

* add download link for completed report exports in generated files table ([12a150c](https://github.com/LindemannRock/craft-report-manager/commit/12a150c0725f193ae48fa9d02187f1423ff0fa7a))
* enhance scheduling logic for report exports with fixed time slots ([713a64c](https://github.com/LindemannRock/craft-report-manager/commit/713a64c266dbdaf044d5f1cd4d115319589447b0))

## 5.0.0 - 2026-01-24


### Features

* initial Report Manager plugin implementation ([4a71a31](https://github.com/LindemannRock/craft-report-manager/commit/4a71a3122bbb476d4379852899f2efcafb3b515f))

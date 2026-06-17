<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use lindemannrock\base\helpers\ColorHelper;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\base\helpers\RecurringQueueHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\reportmanager\jobs\CleanupExportsJob;
use lindemannrock\reportmanager\models\Settings;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\services\ExportService;
use lindemannrock\reportmanager\services\QueuedExportProvidersService;
use lindemannrock\reportmanager\services\ReportsService;
use yii\base\Event;

/**
 * Report Manager Plugin
 *
 * Report generation and analytics manager for Craft CMS.
 * Supports extensible data sources starting with Formie.
 *
 * @property DataSourcesService $dataSources
 * @property ReportsService $reports
 * @property ExportService $exports
 * @property QueuedExportProvidersService $queuedExportProviders
 * @property Settings $settings
 * @method Settings getSettings()
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class ReportManager extends Plugin
{
    use LoggingTrait;

    /**
     * @var ReportManager|null Plugin instance
     */
    public static ?ReportManager $plugin = null;

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool Whether the plugin settings page is accessible when allowAdminChanges is false
     */
    public bool $hasReadOnlyCpSettings = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'dataSources' => DataSourcesService::class,
                'reports' => ReportsService::class,
                'exports' => ExportService::class,
                'queuedExportProviders' => QueuedExportProvidersService::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Set logging handle
        $this->setLoggingHandle($this->id);

        // Bootstrap with base plugin helper (logging + Twig extension + colors)
        PluginHelper::bootstrap(
            $this,
            'reportHelper',
            ['reportManager:viewSystemLogs'],
            ['reportManager:downloadSystemLogs'],
            [
                'colorSets' => [
                    'exportStatus' => [
                        'completed' => ColorHelper::getPaletteColor('green'),
                        'processing' => ColorHelper::getPaletteColor('blue'),
                        'pending' => ColorHelper::getPaletteColor('amber'),
                        'failed' => ColorHelper::getPaletteColor('red'),
                        'missing' => ColorHelper::getPaletteColor('amber'),
                    ],
                    'triggerType' => [
                        'manual' => ColorHelper::getPaletteColor('indigo'),
                        'scheduled' => ColorHelper::getPaletteColor('teal'),
                        'api' => ColorHelper::getPaletteColor('purple'),
                    ],
                ],
                'installExperience' => [
                    'headline' => Craft::t('report-manager', 'Report Manager'),
                    'body' => Craft::t('report-manager', 'Create reports, review exports, and manage scheduled reporting from one control panel workspace.'),
                    'ctaLabel' => Craft::t('report-manager', 'Open Report Manager'),
                    'ctaUrl' => 'report-manager',
                    'redirectUri' => 'report-manager',
                    'confettiPreset' => 'surprise',
                ],
            ]
        );

        // Apply plugin name from config file
        PluginHelper::applyPluginNameFromConfig($this);

        // Register CP routes
        $this->registerCpRoutes();

        // Register permissions
        $this->registerPermissions();

        // Register CP nav items
        $this->registerCpNavItems();

        // Schedule recurring jobs (only on non-console requests to avoid running during migrations)
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->scheduleReportJobs();
            $this->scheduleExportCleanupJob();
        }

        Craft::info(
            Craft::t('report-manager', '{name} plugin loaded', ['name' => $this->name]),
            __METHOD__
        );
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $navItem = parent::getCpNavItem();

        if ($navItem === null) {
            return null;
        }

        $settings = $this->getSettings();
        $navItem['label'] = $settings->getFullName();

        $user = Craft::$app->getUser();
        $sections = $this->getCpSections($settings);
        $navItem['subnav'] = CpNavHelper::buildSubnav($user, $settings, $sections);

        // Add logs section using the logging library
        if (PluginHelper::isPluginEnabled('logging-library')) {
            $navItem = LoggingLibrary::addLogsNav($navItem, $this->handle, [
                'reportManager:viewSystemLogs',
            ]);
        }

        // Hide from nav if no accessible subnav items
        if (empty($navItem['subnav'])) {
            return null;
        }

        return $navItem;
    }

    /**
     * Get CP sections for nav + default route resolution
     *
     * @param Settings $settings
     * @param bool $includeDashboard
     * @param bool $includeLogs
     * @return array
     * @since 5.2.0
     */
    public function getCpSections(Settings $settings, bool $includeDashboard = true, bool $includeLogs = false): array
    {
        $sections = [];

        if ($includeDashboard) {
            $sections[] = [
                'key' => 'dashboard',
                'label' => Craft::t('report-manager', 'Dashboard'),
                'url' => 'report-manager',
                'permissionsAll' => ['reportManager:viewDashboard'],
            ];
        }

        $sections[] = [
            'key' => 'reports',
            'label' => Craft::t('report-manager', 'Reports'),
            'url' => 'report-manager/reports',
            'permissionsAll' => ['reportManager:manageReports'],
        ];

        // Exports - hidden for now (accessible via Reports > View Generated)

        if ($includeLogs) {
            $sections[] = [
                'key' => 'logs',
                'label' => Craft::t('report-manager', 'Logs'),
                'url' => 'report-manager/logs',
                'permissionsAll' => ['reportManager:viewSystemLogs'],
                'when' => fn() => PluginHelper::isPluginEnabled('logging-library'),
            ];
        }

        $sections[] = [
            'key' => 'settings',
            'label' => Craft::t('report-manager', 'Settings'),
            'url' => 'report-manager/settings',
            'permissionsAll' => ['reportManager:manageSettings'],
        ];

        return $sections;
    }

    /**
     * @inheritdoc
     */
    public function setSettings(array|Model $settings): void
    {
        // No-op: settings come from loadFromDatabase() in createSettingsModel()
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        try {
            return PluginHelper::applyConfigOverridesToSettings(Settings::loadFromDatabase(), 'report-manager');
        } catch (\Exception $e) {
            Craft::error('Could not load settings from database: ' . $e->getMessage(), __METHOD__);
            return new Settings();
        }
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect('report-manager/settings');
    }

    /**
     * @inheritdoc
     */
    public function getReadOnlySettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect('report-manager/settings');
    }

    /**
     * Register CP routes
     */
    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Dashboard
                $event->rules['report-manager'] = 'report-manager/dashboard/index';
                $event->rules['report-manager/dashboard'] = 'report-manager/dashboard/index';

                // Reports
                $event->rules['report-manager/reports'] = 'report-manager/reports/index';
                $event->rules['report-manager/reports/new'] = 'report-manager/reports/edit';
                $event->rules['report-manager/reports/<reportId:\d+>'] = 'report-manager/reports/edit';
                $event->rules['report-manager/reports/<reportId:\d+>/generated'] = 'report-manager/reports/generated';

                // Exports
                $event->rules['report-manager/exports'] = 'report-manager/exports/index';
                $event->rules['report-manager/exports/new'] = 'report-manager/exports/new';
                $event->rules['report-manager/exports/status'] = 'report-manager/exports/status';
                $event->rules['report-manager/exports/<exportId:\d+>'] = 'report-manager/exports/view';
                $event->rules['report-manager/exports/download/<id:\d+>'] = 'report-manager/exports/download';

                // Settings
                $event->rules['report-manager/settings'] = 'report-manager/settings/index';
                $event->rules['report-manager/settings/general'] = 'report-manager/settings/general';
                $event->rules['report-manager/settings/interface'] = 'report-manager/settings/interface';
                $event->rules['report-manager/settings/scheduling'] = 'report-manager/settings/scheduling';
                $event->rules['report-manager/settings/export'] = 'report-manager/settings/export';

                // API endpoints
                $event->rules['report-manager/api/entities/<dataSource:{handle}>'] = 'report-manager/api/entities';
                $event->rules['report-manager/api/fields/<dataSource:{handle}>/<entityId:\d+>'] = 'report-manager/api/fields';
                $event->rules['report-manager/api/analytics/<dataSource:{handle}>/<entityId:\d+>'] = 'report-manager/api/analytics';
            }
        );
    }

    /**
     * Register permissions
     */
    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $settings = $this->getSettings();

                $event->permissions[] = [
                    'heading' => $settings->getFullName(),
                    'permissions' => [
                        'reportManager:viewDashboard' => [
                            'label' => Craft::t('report-manager', 'View Dashboard'),
                        ],
                        'reportManager:manageReports' => [
                            'label' => Craft::t('report-manager', 'Manage Reports'),
                            'nested' => [
                                'reportManager:createReports' => [
                                    'label' => Craft::t('report-manager', 'Create Reports'),
                                ],
                                'reportManager:editReports' => [
                                    'label' => Craft::t('report-manager', 'Edit Reports'),
                                ],
                                'reportManager:deleteReports' => [
                                    'label' => Craft::t('report-manager', 'Delete Reports'),
                                ],
                            ],
                        ],
                        'reportManager:manageExports' => [
                            'label' => Craft::t('report-manager', 'Manage Exports'),
                            'nested' => [
                                'reportManager:createExports' => [
                                    'label' => Craft::t('report-manager', 'Create Exports'),
                                ],
                                'reportManager:downloadExports' => [
                                    'label' => Craft::t('report-manager', 'Download Exports'),
                                ],
                                'reportManager:deleteExports' => [
                                    'label' => Craft::t('report-manager', 'Delete Exports'),
                                ],
                            ],
                        ],
                        'reportManager:viewLogs' => [
                            'label' => Craft::t('report-manager', 'View Logs'),
                            'nested' => [
                                'reportManager:viewSystemLogs' => [
                                    'label' => Craft::t('report-manager', 'View System Logs'),
                                    'nested' => [
                                        'reportManager:downloadSystemLogs' => [
                                            'label' => Craft::t('report-manager', 'Download System Logs'),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'reportManager:manageSettings' => [
                            'label' => Craft::t('report-manager', 'Manage Settings'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Register CP nav items (sidebar modifications)
     */
    private function registerCpNavItems(): void
    {
        // This event is useful for modifying the nav after it's built
        // Currently we use getCpNavItem() for subnav, but this is here for future use
    }

    /**
     * Get plugin instance
     *
     * @return ReportManager|null
     */
    public static function getInstance(): ?ReportManager
    {
        return self::$plugin;
    }

    /**
     * Ensure per-report scheduled jobs are queued.
     */
    private function scheduleReportJobs(): void
    {
        $settings = $this->getSettings();

        if (!$settings->enableScheduledReports) {
            return;
        }

        $legacyDeleted = $this->reports->deleteLegacyScheduledReportJobs();
        $queued = $this->reports->queueAllScheduledReportJobs();

        if ($legacyDeleted > 0 || $queued > 0) {
            $this->logInfo('Queued scheduled report jobs', [
                'legacy_deleted' => $legacyDeleted,
                'queued' => $queued,
            ]);
        }
    }

    /**
     * Ensure generated export cleanup is queued.
     *
     * @param \DateTime|null $nextRun Target run time. Null schedules the initial bootstrap row.
     * @param bool $checkExisting Whether to guard against an existing queued cleanup row.
     */
    public function scheduleExportCleanupJob(?\DateTime $nextRun = null, bool $checkExisting = true): void
    {
        $settings = $this->getSettings();

        if (!$settings->autoCleanupExports || $settings->exportRetention <= 0) {
            return;
        }

        $nextRun ??= ScheduleHelper::calculateNext('daily');

        if ($nextRun === null) {
            return;
        }

        $delay = max(0, $nextRun->getTimestamp() - DateFormatHelper::now()->getTimestamp());

        if ($delay <= 0) {
            return;
        }

        $nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
            $nextRun,
            $settings,
            null,
            false,
            pluginHandle: 'report-manager',
        );

        $jobFactory = static fn(): CleanupExportsJob => new CleanupExportsJob([
            'reschedule' => true,
            'nextRunTime' => $nextRunTime,
        ]);

        if ($checkExisting) {
            $result = RecurringQueueHelper::ensurePending(
                pluginToken: 'reportmanager',
                jobClass: CleanupExportsJob::class,
                delay: $delay,
                jobFactory: $jobFactory,
            );

            if ($result->wasCreated()) {
                $this->logInfo('Scheduled export cleanup job', [
                    'delay_seconds' => $delay,
                    'next_run' => $nextRunTime,
                ]);
            }

            if ($result->duplicatesDeleted > 0) {
                $this->logInfo('Collapsed duplicate export cleanup jobs', [
                    'duplicates_deleted' => $result->duplicatesDeleted,
                ]);
            }
        } else {
            Craft::$app->getQueue()->delay($delay)->push($jobFactory());

            $this->logInfo('Scheduled export cleanup job', [
                'delay_seconds' => $delay,
                'next_run' => $nextRunTime,
            ]);
        }
    }

    /**
     * Queue the next generated export cleanup run.
     *
     * @since 5.4.0
     */
    public function scheduleNextExportCleanupJob(): void
    {
        $this->scheduleExportCleanupJob(ScheduleHelper::calculateNext('daily'), false);
    }

    /**
     * Delete queued generated export cleanup jobs.
     *
     * @return int Number of deleted queue rows
     */
    public function deleteExportCleanupJobs(): int
    {
        return RecurringQueueHelper::deletePending('reportmanager', CleanupExportsJob::class);
    }
}

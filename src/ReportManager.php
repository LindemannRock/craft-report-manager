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
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\reportmanager\jobs\ProcessScheduledReportsJob;
use lindemannrock\reportmanager\models\Settings;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\services\ExportService;
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
    public string $schemaVersion = '5.0.0';

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

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
        $this->setLoggingHandle('report-manager');

        // Bootstrap with base plugin helper (logging + Twig extension + colors)
        PluginHelper::bootstrap(
            $this,
            'reportHelper',
            ['reportManager:viewLogs'],
            ['reportManager:downloadLogs'],
            [
                'colorSets' => [
                    'exportStatus' => [
                        'completed' => ColorHelper::getPaletteColor('green'),
                        'processing' => ColorHelper::getPaletteColor('blue'),
                        'pending' => ColorHelper::getPaletteColor('amber'),
                        'failed' => ColorHelper::getPaletteColor('red'),
                    ],
                    'triggerType' => [
                        'manual' => ColorHelper::getPaletteColor('indigo'),
                        'scheduled' => ColorHelper::getPaletteColor('teal'),
                        'api' => ColorHelper::getPaletteColor('purple'),
                    ],
                    'exportFormat' => [
                        'xlsx' => ColorHelper::getPaletteColor('green'),
                        'csv' => ColorHelper::getPaletteColor('blue'),
                        'json' => ColorHelper::getPaletteColor('amber'),
                    ],
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

        // Schedule reports processing job (only on non-console requests to avoid running during migrations)
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->scheduleReportsJob();
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
        $navItem['label'] = $settings->pluginName;
        $navItem['icon'] = '@appicons/chart-bar.svg';

        // Check permissions
        /** @var \craft\elements\User|null $currentUser */
        $currentUser = Craft::$app->getUser()->getIdentity();

        if (!$currentUser) {
            return null;
        }

        $navItem['subnav'] = [];

        // Dashboard
        if ($currentUser->can('reportManager:viewDashboard')) {
            $navItem['subnav']['dashboard'] = [
                'label' => Craft::t('report-manager', 'Dashboard'),
                'url' => 'report-manager',
            ];
        }

        // Reports
        if ($currentUser->can('reportManager:viewReports')) {
            $navItem['subnav']['reports'] = [
                'label' => Craft::t('report-manager', 'Reports'),
                'url' => 'report-manager/reports',
            ];
        }

        // Exports - hidden for now (accessible via Reports > View Generated)
        // if ($currentUser->can('reportManager:viewExports')) {
        //     $navItem['subnav']['exports'] = [
        //         'label' => Craft::t('report-manager', 'Exports'),
        //         'url' => 'report-manager/exports',
        //     ];
        // }

        // Add logs section using the logging library
        if (Craft::$app->getPlugins()->isPluginInstalled('logging-library') &&
            Craft::$app->getPlugins()->isPluginEnabled('logging-library')) {
            $navItem = LoggingLibrary::addLogsNav($navItem, $this->handle, [
                'reportManager:viewLogs',
            ]);
        }

        // Settings
        if ($currentUser->can('reportManager:manageSettings')) {
            $navItem['subnav']['settings'] = [
                'label' => Craft::t('report-manager', 'Settings'),
                'url' => 'report-manager/settings',
            ];
        }

        return $navItem;
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        try {
            return Settings::loadFromDatabase();
        } catch (\Exception $e) {
            Craft::error('Could not load settings from database: ' . $e->getMessage(), __METHOD__);
            return new Settings();
        }
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'report-manager/settings/index',
            ['settings' => $this->getSettings()]
        );
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
                $event->rules['report-manager/settings/export'] = 'report-manager/settings/export';

                // API endpoints
                $event->rules['report-manager/api/entities/<dataSource:{handle}>'] = 'report-manager/api/entities';
                $event->rules['report-manager/api/fields/<dataSource:{handle}>/<entityId:\d+>'] = 'report-manager/api/fields';
                $event->rules['report-manager/api/analytics/<dataSource:{handle}>/<entityId:\d+>'] = 'report-manager/api/analytics';

                // Logging routes
                $event->rules['report-manager/logs'] = 'logging-library/logs/index';
                $event->rules['report-manager/logs/download'] = 'logging-library/logs/download';
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
                $pluginName = $settings->pluginName;

                $event->permissions[] = [
                    'heading' => $pluginName,
                    'permissions' => [
                        'reportManager:viewDashboard' => [
                            'label' => Craft::t('report-manager', 'View Dashboard'),
                        ],
                        'reportManager:viewReports' => [
                            'label' => Craft::t('report-manager', 'View Reports'),
                            'nested' => [
                                'reportManager:manageReports' => [
                                    'label' => Craft::t('report-manager', 'Manage Reports'),
                                ],
                            ],
                        ],
                        'reportManager:viewExports' => [
                            'label' => Craft::t('report-manager', 'View Exports'),
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
                                'reportManager:downloadLogs' => [
                                    'label' => Craft::t('report-manager', 'Download Logs'),
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
     * @since 5.0.0
     */
    public static function getInstance(): ?ReportManager
    {
        return self::$plugin;
    }

    /**
     * Schedule reports processing job
     * Called on every plugin init to ensure job is always in queue
     */
    private function scheduleReportsJob(): void
    {
        $settings = $this->getSettings();

        // Only schedule if scheduled reports are enabled
        if (!$settings->enableScheduledReports) {
            return;
        }

        // Check if a reports processing job is already scheduled
        $existingJob = (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'reportmanager'])
            ->andWhere(['like', 'job', 'ProcessScheduledReportsJob'])
            ->exists();

        if (!$existingJob) {
            $job = new ProcessScheduledReportsJob([
                'reschedule' => true,
            ]);

            // Add to queue with a small initial delay (5 minutes)
            Craft::$app->getQueue()->delay(5 * 60)->push($job);

            $this->logInfo('Scheduled initial reports processing job', [
                'delay' => '5 minutes',
                'schedule' => $settings->defaultSchedule,
            ]);
        }
    }
}

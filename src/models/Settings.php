<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\base\traits\SettingsConfigTrait;
use lindemannrock\base\traits\SettingsDisplayNameTrait;
use lindemannrock\base\traits\SettingsPersistenceTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Settings Model
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class Settings extends Model
{
    use LoggingTrait;
    use SettingsDisplayNameTrait;
    use SettingsPersistenceTrait;
    use SettingsConfigTrait;

    // =========================================================================
    // PLUGIN SETTINGS
    // =========================================================================

    /**
     * @var string The public-facing name of the plugin
     */
    public string $pluginName = 'Report Manager';

    // =========================================================================
    // REPORT GENERATION SETTINGS
    // =========================================================================

    /**
     * @var bool Enable scheduled report generation
     */
    public bool $enableScheduledReports = true;

    /**
     * @var string Default schedule for report generation
     * Options: disabled, every6hours, every12hours, daily, daily2am, weekly
     */
    public string $defaultSchedule = 'daily2am';

    /**
     * @var int Maximum records per export batch (for large datasets)
     */
    public int $maxExportBatchSize = 10000;

    /**
     * @var int Number of days to retain generated exports
     */
    public int $exportRetention = 30;

    /**
     * @var bool Auto-cleanup old exports
     */
    public bool $autoCleanupExports = true;

    // =========================================================================
    // EXPORT SETTINGS
    // =========================================================================

    /**
     * @var string|null Asset volume UID for export storage (null = use exportPath)
     */
    public ?string $exportVolumeUid = null;

    /**
     * @var string The path where exports should be stored
     */
    public string $exportPath = '@storage/report-manager/exports';

    /**
     * @var string Default export format (csv, json)
     */
    public string $defaultExportFormat = 'csv';

    /**
     * @var string CSV delimiter character
     */
    public string $csvDelimiter = ',';

    /**
     * @var string CSV enclosure character
     */
    public string $csvEnclosure = '"';

    /**
     * @var bool Include BOM in CSV exports (for Excel compatibility)
     */
    public bool $csvIncludeBom = true;

    // =========================================================================
    // ANALYTICS SETTINGS
    // =========================================================================

    /**
     * @var bool Enable analytics dashboard
     */
    public bool $enableAnalytics = true;

    /**
     * @var string Default date range for analytics
     */
    public string $defaultDateRange = 'last30days';

    /**
     * @var int Dashboard refresh interval in seconds (0 = disabled)
     */
    public int $dashboardRefreshInterval = 0;

    // =========================================================================
    // INTERFACE SETTINGS
    // =========================================================================

    /**
     * @var int Items per page in list views
     */
    public int $itemsPerPage = 50;

    // =========================================================================
    // LOGGING LIBRARY SETTINGS
    // =========================================================================

    /**
     * @var string Log level for the logging library
     */
    public string $logLevel = 'error';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('report-manager');
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['exportPath'],
            ],
        ];
    }

    // =========================================================================
    // TRAIT CONFIGURATION
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected static function tableName(): string
    {
        return 'reportmanager_settings';
    }

    /**
     * @inheritdoc
     */
    protected static function pluginHandle(): string
    {
        return 'report-manager';
    }

    /**
     * @inheritdoc
     */
    protected static function booleanFields(): array
    {
        return [
            'enableScheduledReports',
            'autoCleanupExports',
            'csvIncludeBom',
            'enableAnalytics',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function integerFields(): array
    {
        return [
            'maxExportBatchSize',
            'exportRetention',
            'dashboardRefreshInterval',
            'itemsPerPage',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function jsonFields(): array
    {
        return [];
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            ['pluginName', 'string'],
            ['pluginName', 'default', 'value' => 'Report Manager'],
            [
                [
                    'enableScheduledReports',
                    'autoCleanupExports',
                    'csvIncludeBom',
                    'enableAnalytics',
                ],
                'boolean',
            ],
            [
                'defaultSchedule',
                'in',
                'range' => ['disabled', 'every6hours', 'every12hours', 'daily', 'daily2am', 'weekly'],
            ],
            ['defaultSchedule', 'default', 'value' => 'daily2am'],
            ['maxExportBatchSize', 'integer', 'min' => 100, 'max' => 100000],
            ['maxExportBatchSize', 'default', 'value' => 10000],
            ['exportRetention', 'integer', 'min' => 0],
            ['exportRetention', 'default', 'value' => 30],
            ['defaultExportFormat', 'in', 'range' => ['csv', 'xlsx', 'json']],
            ['defaultExportFormat', 'default', 'value' => 'csv'],
            ['csvDelimiter', 'string', 'length' => 1],
            ['csvDelimiter', 'default', 'value' => ','],
            ['csvEnclosure', 'string', 'length' => 1],
            ['csvEnclosure', 'default', 'value' => '"'],
            ['defaultDateRange', 'in', 'range' => ['today', 'yesterday', 'last7days', 'last30days', 'last90days', 'last365days', 'all']],
            ['defaultDateRange', 'default', 'value' => 'last30days'],
            ['dashboardRefreshInterval', 'integer', 'min' => 0],
            ['dashboardRefreshInterval', 'default', 'value' => 0],
            ['itemsPerPage', 'integer', 'min' => 10, 'max' => 500],
            ['itemsPerPage', 'default', 'value' => 50],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'warning', 'error']],
            [['logLevel'], 'validateLogLevel'],
            [['exportVolumeUid'], 'string'],
            [['exportPath'], 'validateExportPath'],
        ];
    }

    /**
     * Validate log level - debug requires devMode
     *
     * @since 5.0.0
     */
    public function validateLogLevel(string $attribute): void
    {
        $logLevel = $this->$attribute;

        if (Craft::$app->getConfig()->getGeneral()->devMode && !Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->getSession()->remove('reportmanager_debug_config_warning');
        }

        if ($logLevel === 'debug' && !Craft::$app->getConfig()->getGeneral()->devMode) {
            $this->$attribute = 'info';

            if ($this->isOverriddenByConfig('logLevel')) {
                if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
                    if (Craft::$app->getSession()->get('reportmanager_debug_config_warning') === null) {
                        $this->logWarning('Log level "debug" from config file changed to "info" because devMode is disabled', [
                            'configFile' => 'config/report-manager.php',
                        ]);
                        Craft::$app->getSession()->set('reportmanager_debug_config_warning', true);
                    }
                } else {
                    $this->logWarning('Log level "debug" from config file changed to "info" because devMode is disabled', [
                        'configFile' => 'config/report-manager.php',
                    ]);
                }
            } else {
                $this->logWarning('Log level automatically changed from "debug" to "info" because devMode is disabled');
                $this->saveToDatabase();
            }
        }
    }

    /**
     * Validates the export path to prevent directory traversal attacks
     *
     * @param string $attribute
     * @since 5.0.0
     */
    public function validateExportPath(string $attribute): void
    {
        // Skip validation if empty (will be caught by required rule)
        if (empty($this->$attribute)) {
            return;
        }

        $path = $this->$attribute;

        // Check for directory traversal attempts
        if (strpos($path, '..') !== false) {
            $this->addError($attribute, Craft::t('report-manager', 'Export path cannot contain directory traversal sequences (..)'));
            return;
        }

        // Check if path is already resolved (doesn't start with @)
        if (strpos($path, '@') !== 0) {
            // Path is resolved - validate against resolved allowed paths
            $allowedResolvedPaths = [
                Craft::getAlias('@root'),
                Craft::getAlias('@storage'),
            ];

            $isValid = false;
            foreach ($allowedResolvedPaths as $allowedPath) {
                if ($allowedPath && ($path === $allowedPath || strpos($path, $allowedPath . '/') === 0)) {
                    $isValid = true;
                    break;
                }
            }
        } else {
            // Path is unresolved - validate against aliases
            $allowedAliases = ['@root', '@storage'];

            $isValid = false;
            foreach ($allowedAliases as $allowedAlias) {
                if (strpos($path, $allowedAlias) === 0) {
                    $isValid = true;
                    break;
                }
            }
        }

        if (!$isValid) {
            $this->addError($attribute, Craft::t('report-manager', 'Export path must start with @root or @storage (secure locations only)'));
            return;
        }

        // Resolve the alias to check actual path
        try {
            $resolvedPath = Craft::getAlias($path);
            $webroot = Craft::getAlias('@webroot');

            // Prevent exports in web-accessible directory
            if (str_starts_with($resolvedPath, $webroot)) {
                $this->addError(
                    $attribute,
                    Craft::t('report-manager', 'Export path cannot be in a web-accessible directory (@webroot)')
                );
            }
        } catch (\Exception $e) {
            $this->addError($attribute, Craft::t('report-manager', 'Invalid export path: {error}', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Returns the full export path (for display purposes)
     *
     * @return string
     * @since 5.0.0
     */
    public function getExportPath(): string
    {
        // If a volume is selected, display volume info
        if ($this->exportVolumeUid) {
            $volume = Craft::$app->getVolumes()->getVolumeByUid($this->exportVolumeUid);
            if ($volume) {
                return "Volume: {$volume->name}/report-manager/exports";
            }
        }

        // No volume selected - use regular export path and properly resolve it
        $rawPath = $this->exportPath;
        $path = Craft::getAlias($rawPath);

        // If path is null or empty, use default
        if (empty($path)) {
            $defaultPath = '@storage/report-manager/exports';
            $path = Craft::getAlias($defaultPath);
        }

        // Additional safety checks
        if (strpos($path, '..') !== false) {
            return '@storage/report-manager/exports (invalid path)';
        }

        // Prevent exports from being saved in the root directory
        $rootPath = Craft::getAlias('@root');
        if ($path === $rootPath || $path === '/' || $path === '') {
            $path = Craft::getAlias('@storage/report-manager/exports');
        }

        return $path;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get schedule options for dropdown
     *
     * @return array
     * @since 5.0.0
     */
    public function getScheduleOptions(): array
    {
        return [
            ['value' => 'disabled', 'label' => Craft::t('report-manager', 'Disabled')],
            ['value' => 'every6hours', 'label' => Craft::t('report-manager', 'Every 6 Hours')],
            ['value' => 'every12hours', 'label' => Craft::t('report-manager', 'Every 12 Hours')],
            ['value' => 'daily', 'label' => Craft::t('report-manager', 'Daily')],
            ['value' => 'daily2am', 'label' => Craft::t('report-manager', 'Daily at 2:00 AM')],
            ['value' => 'weekly', 'label' => Craft::t('report-manager', 'Weekly')],
        ];
    }

    /**
     * Get export format options for dropdown
     *
     * Returns only formats enabled in the global lindemannrock-base config.
     *
     * @return array
     * @since 5.0.0
     */
    public function getExportFormatOptions(): array
    {
        return ExportHelper::getFormatOptions();
    }

    /**
     * Get date range options for dropdown
     *
     * @return array
     * @since 5.0.0
     */
    public function getDateRangeOptions(): array
    {
        return [
            ['value' => 'today', 'label' => Craft::t('report-manager', 'Today')],
            ['value' => 'yesterday', 'label' => Craft::t('report-manager', 'Yesterday')],
            ['value' => 'last7days', 'label' => Craft::t('report-manager', 'Last 7 Days')],
            ['value' => 'last30days', 'label' => Craft::t('report-manager', 'Last 30 Days')],
            ['value' => 'last90days', 'label' => Craft::t('report-manager', 'Last 90 Days')],
            ['value' => 'last365days', 'label' => Craft::t('report-manager', 'Last Year')],
            ['value' => 'all', 'label' => Craft::t('report-manager', 'All Time')],
        ];
    }
}

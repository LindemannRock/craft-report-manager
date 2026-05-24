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
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\base\traits\DateFormatSettingsTrait;
use lindemannrock\base\traits\DateRangeSettingsTrait;
use lindemannrock\base\traits\ExportFormatSettingsTrait;
use lindemannrock\base\traits\ItemsPerPageSettingsTrait;
use lindemannrock\base\traits\LogLevelSettingsTrait;
use lindemannrock\base\traits\PluginNameSettingsTrait;
use lindemannrock\base\traits\SettingsConfigTrait;
use lindemannrock\base\traits\SettingsDisplayNameTrait;
use lindemannrock\base\traits\SettingsPersistenceTrait;
use lindemannrock\base\validators\StoragePathValidator;
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
    use DateFormatSettingsTrait;
    use DateRangeSettingsTrait;
    use ExportFormatSettingsTrait;
    use ItemsPerPageSettingsTrait;
    use LogLevelSettingsTrait;
    use PluginNameSettingsTrait;

    // =========================================================================
    // PLUGIN SETTINGS
    // =========================================================================

    /**
     * @var string The name of the plugin as it appears in the Control Panel menu
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
     * Options: disabled, every6hours, every12hours, daily, daily2am, weekly,
     * monthly, every2months, quarterly, every6months, yearly
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
    // LEGACY DASHBOARD SETTINGS
    // =========================================================================

    /**
     * @var bool Legacy analytics dashboard flag, retained for existing settings rows
     */
    public bool $enableAnalytics = true;

    /**
     * @var int Legacy dashboard refresh interval, retained for existing settings rows
     */
    public int $dashboardRefreshInterval = 0;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(static::pluginHandle());
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        return [];
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
            'showSeconds',
            'exportsCsv',
            'exportsJson',
            'exportsExcel',
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
        return array_merge([
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
                'range' => [
                    'disabled',
                    'every6hours',
                    'every12hours',
                    'daily',
                    'daily2am',
                    'weekly',
                    'monthly',
                    'every2months',
                    'quarterly',
                    'every6months',
                    'yearly',
                ],
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
            ['dashboardRefreshInterval', 'integer', 'min' => 0, 'max' => 3600],
            ['dashboardRefreshInterval', 'default', 'value' => 0],
            [['exportVolumeUid'], 'string'],
            [
                ['exportPath'],
                StoragePathValidator::class,
                'translationCategory' => static::pluginHandle(),
                'allowedAliases' => ['@storage', '@root'],
                'preventWebroot' => true,
                'requireAlias' => true,
            ],
        ], $this->pluginNameSettingsRules(), $this->logLevelSettingsRules(), $this->itemsPerPageSettingsRules(), $this->dateFormatSettingsRules(), $this->dateRangeSettingsRules(), $this->exportFormatSettingsRules());
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return array_merge([
            'enableScheduledReports' => Craft::t('report-manager', 'Enable Scheduled Reports'),
            'defaultSchedule' => Craft::t('report-manager', 'Default Schedule'),
            'maxExportBatchSize' => Craft::t('report-manager', 'Maximum Export Batch Size'),
            'exportRetention' => Craft::t('report-manager', 'Export Retention (Days)'),
            'autoCleanupExports' => Craft::t('report-manager', 'Auto Cleanup Exports'),
            'exportVolumeUid' => Craft::t('report-manager', 'Export Storage Volume'),
            'exportPath' => Craft::t('report-manager', 'Custom Export Path'),
            'defaultExportFormat' => Craft::t('report-manager', 'Default Export Format'),
            'csvDelimiter' => Craft::t('report-manager', 'CSV Delimiter'),
            'csvEnclosure' => Craft::t('report-manager', 'CSV Enclosure'),
            'csvIncludeBom' => Craft::t('report-manager', 'Include BOM'),
        ],
            $this->pluginNameSettingsLabel(),
            $this->logLevelSettingsLabel(),
            $this->itemsPerPageSettingsLabel(),
            $this->dateFormatSettingsLabels(),
            $this->dateRangeSettingsLabel(),
            $this->exportFormatSettingsLabels(),
        );
    }

    /**
     * Returns the full export path (for display purposes)
     *
     * @return string
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

        // If alias resolution failed or returned empty, log and fall back to default
        if (empty($path)) {
            $this->logWarning('Configured exportPath could not be resolved; falling back to default.', [
                'configuredPath' => $rawPath,
                'fallback' => '@storage/report-manager/exports',
            ]);
            $path = Craft::getAlias('@storage/report-manager/exports');
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
            ['value' => 'monthly', 'label' => Craft::t('report-manager', 'Monthly')],
            ['value' => 'every2months', 'label' => Craft::t('report-manager', 'Every 2 Months')],
            ['value' => 'quarterly', 'label' => Craft::t('report-manager', 'Quarterly')],
            ['value' => 'every6months', 'label' => Craft::t('report-manager', 'Every 6 Months')],
            ['value' => 'yearly', 'label' => Craft::t('report-manager', 'Yearly')],
        ];
    }

    /**
     * Get export format options for dropdown
     *
     * Returns only formats enabled in the global lindemannrock-base config.
     *
     * @return array
     */
    public function getExportFormatOptions(): array
    {
        return ExportHelper::getFormatOptions();
    }

    /**
     * Get date range options for dropdown
     *
     * @return array
     */
    public function getDateRangeOptions(bool $includeCustom = false): array
    {
        return DateRangeHelper::getOptions('array', $includeCustom);
    }
}

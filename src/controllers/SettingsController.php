<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\reportmanager\ReportManager;
use yii\web\Response;

/**
 * Settings Controller
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Require manage settings permission
        $this->requirePermission('reportManager:manageSettings');

        return true;
    }

    /**
     * Settings index action
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        return $this->actionGeneral();
    }

    /**
     * General settings action
     *
     * @return Response
     */
    public function actionGeneral(): Response
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('report-manager/settings/general', [
            'settings' => $settings,
            'selectedTab' => 'general',
        ]);
    }

    /**
     * Interface settings action
     *
     * @return Response
     */
    public function actionInterface(): Response
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('report-manager/settings/interface', [
            'settings' => $settings,
            'selectedTab' => 'interface',
        ]);
    }

    /**
     * Scheduling settings action
     *
     * @return Response
     */
    public function actionScheduling(): Response
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('report-manager/settings/scheduling', [
            'settings' => $settings,
            'selectedTab' => 'scheduling',
        ]);
    }

    /**
     * Export settings action
     *
     * @return Response
     */
    public function actionExport(): Response
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('report-manager/settings/export', [
            'settings' => $settings,
            'selectedTab' => 'export',
        ]);
    }

    /**
     * Save settings action
     *
     * @return Response|null
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();
        $postedSettings = Craft::$app->getRequest()->getBodyParam('settings', []);
        $section = $this->_validSettingsSection(
            Craft::$app->getRequest()->getBodyParam('section', 'general'),
        );
        $scheduledReportsWereEnabled = $settings->enableScheduledReports;
        $exportCleanupWasEnabled = $settings->autoCleanupExports && $settings->exportRetention > 0;

        // Fields that should be cast to int
        $intFields = ['maxExportBatchSize', 'exportRetention', 'itemsPerPage'];

        // Fields that should be cast to bool
        $boolFields = ['enableScheduledReports', 'autoCleanupExports', 'csvIncludeBom'];

        // Fields that should be nullable strings (empty string becomes null)
        $nullableStringFields = ['exportVolumeUid'];

        // Update settings with posted values
        foreach ($postedSettings as $key => $value) {
            if (property_exists($settings, $key) && !$settings->isOverriddenByConfig($key)) {
                // Cast to appropriate type
                if (in_array($key, $intFields, true)) {
                    $settings->$key = (int)$value;
                } elseif (in_array($key, $boolFields, true)) {
                    $settings->$key = (bool)$value;
                } elseif (in_array($key, $nullableStringFields, true)) {
                    $settings->$key = $value !== '' && $value !== null ? $value : null;
                } else {
                    // Multi-state selects (e.g. "Use global default" = '') need '' → null
                    // so nullable properties hold null, not a coerced false / 0.
                    if ($value === '') {
                        $type = (new \ReflectionProperty($settings, $key))->getType();
                        if ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
                            $value = null;
                        }
                    }
                    $settings->$key = $value;
                }
            }
        }

        $attributesToValidate = $this->_validationAttributesForSection($section);
        $attributesToValidate = array_values(array_filter(
            $attributesToValidate,
            fn(string $attribute): bool => !$settings->isOverriddenByConfig($attribute),
        ));

        // Validate
        if (!$settings->validate($attributesToValidate)) {
            Craft::$app->getSession()->setError(Craft::t('report-manager', 'Could not save settings.'));

            return $this->renderTemplate('report-manager/settings/' . $section, [
                'settings' => $settings,
                'selectedTab' => $section,
            ]);
        }

        // Save to database
        if (!$settings->saveToDatabase($attributesToValidate)) {
            Craft::$app->getSession()->setError(Craft::t('report-manager', 'Could not save settings.'));

            return $this->renderTemplate('report-manager/settings/' . $section, [
                'settings' => $settings,
                'selectedTab' => $section,
            ]);
        }

        if ($section === 'scheduling') {
            if ($scheduledReportsWereEnabled && !$settings->enableScheduledReports) {
                $plugin->reports->deleteScheduledReportJobs();
            } elseif ($settings->enableScheduledReports) {
                $plugin->reports->queueAllScheduledReportJobs();
            }
        }

        if ($section === 'export') {
            $exportCleanupIsEnabled = $settings->autoCleanupExports && $settings->exportRetention > 0;

            if ($exportCleanupWasEnabled && !$exportCleanupIsEnabled) {
                $plugin->deleteExportCleanupJobs();
            } elseif ($exportCleanupIsEnabled) {
                $plugin->scheduleExportCleanupJob();
            }
        }

        Craft::$app->getSession()->setNotice(Craft::t('report-manager', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Validate and sanitize the settings section parameter
     *
     * @param string $section The section from POST data
     * @return string A validated section name
     */
    private function _validSettingsSection(string $section): string
    {
        $allowed = ['general', 'interface', 'scheduling', 'export'];

        return in_array($section, $allowed, true) ? $section : 'general';
    }

    /**
     * Get settings attributes that belong to a section.
     *
     * @param string $section
     * @return array<int, string>
     */
    private function _validationAttributesForSection(string $section): array
    {
        return match ($section) {
            'general' => [
                'pluginName',
                'logLevel',
            ],
            'interface' => [
                'itemsPerPage',
                'timeFormat',
                'monthFormat',
                'dateOrder',
                'dateSeparator',
                'showSeconds',
                'defaultDateRange',
                'exportsCsv',
                'exportsJson',
                'exportsExcel',
            ],
            'scheduling' => [
                'enableScheduledReports',
                'defaultSchedule',
            ],
            'export' => [
                'exportVolumeUid',
                'exportPath',
                'defaultExportFormat',
                'maxExportBatchSize',
                'csvDelimiter',
                'csvEnclosure',
                'csvIncludeBom',
                'exportRetention',
                'autoCleanupExports',
            ],
            default => [],
        };
    }
}

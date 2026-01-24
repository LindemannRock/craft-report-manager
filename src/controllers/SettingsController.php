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
     * @since 5.0.0
     */
    public function actionIndex(): Response
    {
        return $this->actionGeneral();
    }

    /**
     * General settings action
     *
     * @return Response
     * @since 5.0.0
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
     * Export settings action
     *
     * @return Response
     * @since 5.0.0
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
     * @since 5.0.0
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $settings = ReportManager::getInstance()->getSettings();
        $postedSettings = Craft::$app->getRequest()->getBodyParam('settings', []);
        $section = Craft::$app->getRequest()->getBodyParam('section', 'general');

        // Fields that should be cast to int
        $intFields = ['maxExportBatchSize', 'exportRetention', 'dashboardRefreshInterval', 'itemsPerPage'];

        // Fields that should be cast to bool
        $boolFields = ['enableScheduledReports', 'autoCleanupExports', 'csvIncludeBom', 'enableAnalytics'];

        // Fields that should be nullable strings (empty string becomes null)
        $nullableStringFields = ['exportVolumeUid'];

        // Update settings with posted values
        foreach ($postedSettings as $key => $value) {
            if (property_exists($settings, $key)) {
                // Cast to appropriate type
                if (in_array($key, $intFields, true)) {
                    $settings->$key = (int)$value;
                } elseif (in_array($key, $boolFields, true)) {
                    $settings->$key = (bool)$value;
                } elseif (in_array($key, $nullableStringFields, true)) {
                    $settings->$key = $value !== '' && $value !== null ? $value : null;
                } else {
                    $settings->$key = $value;
                }
            }
        }

        // Validate
        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('report-manager', 'Couldn\'t save settings.'));

            return $this->renderTemplate('report-manager/settings/' . $section, [
                'settings' => $settings,
            ]);
        }

        // Save to database
        if (!$settings->saveToDatabase()) {
            Craft::$app->getSession()->setError(Craft::t('report-manager', 'Couldn\'t save settings.'));

            return $this->renderTemplate('report-manager/settings/' . $section, [
                'settings' => $settings,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('report-manager', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}

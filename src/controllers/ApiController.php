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
 * API Controller
 *
 * Provides AJAX endpoints for the plugin's JavaScript.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class ApiController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAcceptsJson();
        $this->requirePermission('reportManager:viewReports');

        return true;
    }

    /**
     * Get entities for a data source
     *
     * @param string $dataSource Data source handle
     * @return Response
     * @since 5.0.0
     */
    public function actionEntities(string $dataSource): Response
    {
        $plugin = ReportManager::getInstance();
        $dataSourceInstance = $plugin->dataSources->getDataSource($dataSource);

        if (!$dataSourceInstance) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('report-manager', 'Data source not found'),
            ]);
        }

        $entities = $dataSourceInstance->getAvailableEntities();

        return $this->asJson([
            'success' => true,
            'entities' => $entities,
        ]);
    }

    /**
     * Get fields for an entity
     *
     * @param string $dataSource Data source handle
     * @param int $entityId Entity ID
     * @return Response
     * @since 5.0.0
     */
    public function actionFields(string $dataSource, int $entityId): Response
    {
        $plugin = ReportManager::getInstance();
        $dataSourceInstance = $plugin->dataSources->getDataSource($dataSource);

        if (!$dataSourceInstance) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('report-manager', 'Data source not found'),
            ]);
        }

        $fields = $dataSourceInstance->getEntityFields($entityId);

        return $this->asJson([
            'success' => true,
            'fields' => $fields,
        ]);
    }

    /**
     * Get analytics for an entity
     *
     * @param string $dataSource Data source handle
     * @param int $entityId Entity ID
     * @return Response
     * @since 5.0.0
     */
    public function actionAnalytics(string $dataSource, int $entityId): Response
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();
        $dataSourceInstance = $plugin->dataSources->getDataSource($dataSource);

        if (!$dataSourceInstance) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('report-manager', 'Data source not found'),
            ]);
        }

        $dateRange = Craft::$app->getRequest()->getParam('dateRange', $settings->defaultDateRange);
        $analytics = $dataSourceInstance->getAnalytics($entityId, $dateRange);
        $trendData = $dataSourceInstance->getTrendData($entityId, $dateRange);

        return $this->asJson([
            'success' => true,
            'analytics' => $analytics,
            'trendData' => $trendData,
        ]);
    }

    /**
     * Get submission count for an entity
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionSubmissionCount(): Response
    {
        $request = Craft::$app->getRequest();
        $dataSource = $request->getRequiredParam('dataSource');
        $entityId = $request->getRequiredParam('entityId');
        $dateRange = $request->getParam('dateRange');
        $dateStart = $request->getParam('dateStart');
        $dateEnd = $request->getParam('dateEnd');

        $plugin = ReportManager::getInstance();
        $dataSourceInstance = $plugin->dataSources->getDataSource($dataSource);

        if (!$dataSourceInstance) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('report-manager', 'Data source not found'),
            ]);
        }

        $options = [];

        if ($dateRange) {
            $options['dateRange'] = $dateRange;
        }

        if ($dateStart) {
            $options['dateStart'] = $dateStart;
        }

        if ($dateEnd) {
            $options['dateEnd'] = $dateEnd;
        }

        $count = $dataSourceInstance->getSubmissionCount($entityId, $options);

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }
}

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
use DateTime;
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\reportmanager\jobs\GenerateExportJob;
use lindemannrock\reportmanager\ReportManager;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Exports Controller
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class ExportsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('reportManager:viewExports');

        // Additional permissions for specific actions
        if (in_array($action->id, ['new', 'quick-export', 'create'], true)) {
            $this->requirePermission('reportManager:createExports');
        }

        if ($action->id === 'download') {
            $this->requirePermission('reportManager:downloadExports');
        }

        if (in_array($action->id, ['delete', 'bulk-delete'], true)) {
            $this->requirePermission('reportManager:deleteExports');
        }

        return true;
    }

    /**
     * Exports index action
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionIndex(): Response
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();
        $request = Craft::$app->getRequest();

        // Get filter/sort parameters
        $search = $request->getParam('search', '');
        $statusFilter = $request->getParam('status', 'all');
        $formatFilter = $request->getParam('format', 'all');
        $sort = $request->getParam('sort', 'dateCreated');
        $dir = $request->getParam('dir', 'desc');
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = $settings->itemsPerPage ?? 20;

        // Get filtered and paginated exports
        $result = $plugin->exports->getFilteredExports([
            'search' => $search,
            'status' => $statusFilter !== 'all' ? $statusFilter : null,
            'format' => $formatFilter !== 'all' ? $formatFilter : null,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
        ]);

        $exportStats = $plugin->exports->getExportStats();

        return $this->renderTemplate('report-manager/exports/index', [
            'settings' => $settings,
            'exports' => $result['exports'],
            'exportStats' => $exportStats,
            'page' => $page,
            'limit' => $limit,
            'offset' => $result['offset'],
            'totalCount' => $result['totalCount'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    /**
     * View export action
     *
     * @param int $exportId Export ID
     * @return Response
     * @since 5.0.0
     */
    public function actionView(int $exportId): Response
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();

        $export = $plugin->exports->getExportById($exportId);

        if (!$export) {
            throw new NotFoundHttpException(Craft::t('report-manager', 'Export not found'));
        }

        $dataSources = $plugin->dataSources->getAvailableDataSources();

        return $this->renderTemplate('report-manager/exports/view', [
            'settings' => $settings,
            'export' => $export,
            'dataSources' => $dataSources,
        ]);
    }

    /**
     * New export action (quick export form)
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionNew(): Response
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();

        $dataSources = $plugin->dataSources->getAvailableDataSources();
        $dataSourceOptions = $plugin->dataSources->getDataSourceOptions();
        $allEntities = $plugin->dataSources->getAllEntities();

        return $this->renderTemplate('report-manager/exports/new', [
            'settings' => $settings,
            'dataSources' => $dataSources,
            'dataSourceOptions' => $dataSourceOptions,
            'allEntities' => $allEntities,
            'dateRangeOptions' => $settings->getDateRangeOptions(),
            'exportFormatOptions' => $settings->getExportFormatOptions(),
        ]);
    }

    /**
     * Quick export action (create exports for multiple forms)
     *
     * @return Response|null
     * @since 5.0.0
     */
    public function actionQuickExport(): ?Response
    {
        $this->requirePostRequest();

        $plugin = ReportManager::getInstance();
        $request = Craft::$app->getRequest();
        $settings = $plugin->getSettings();

        $dataSource = $request->getRequiredBodyParam('dataSource');
        $entityIds = $request->getBodyParam('entityIds', []);
        $format = $request->getBodyParam('format', $settings->defaultExportFormat);
        $dateRange = $request->getBodyParam('dateRange', $settings->defaultDateRange);
        $exportMode = $request->getBodyParam('exportMode', 'separate');
        $siteId = $request->getBodyParam('siteId', '');

        // Validate export format is enabled
        // Map xlsx to excel for config check
        $configFormat = $format === 'xlsx' ? 'excel' : $format;
        if (!ExportHelper::isFormatEnabled($configFormat)) {
            throw new BadRequestHttpException(Craft::t('report-manager', 'Export format "{format}" is not enabled.', [
                'format' => $format,
            ]));
        }

        // Convert siteId to array format (empty = all sites, otherwise single site)
        $siteIds = !empty($siteId) ? [(int) $siteId] : [];

        // Validate at least one form selected
        if (empty($entityIds) || !is_array($entityIds)) {
            Craft::$app->getSession()->setError(Craft::t('report-manager', 'Please select at least one form to export.'));

            return $this->redirect('report-manager/exports/new');
        }

        // Handle custom date range
        $customDateStart = $request->getBodyParam('customDateStart');
        $customDateEnd = $request->getBodyParam('customDateEnd');

        $dateStart = null;
        $dateEnd = null;

        if (!empty($customDateStart['date'])) {
            $dateStart = new DateTime($customDateStart['date']);
        }

        if (!empty($customDateEnd['date'])) {
            $dateEnd = new DateTime($customDateEnd['date']);
        }

        // Check if we should process immediately or queue
        $processImmediately = (bool) $request->getBodyParam('processImmediately', false);

        // Combined mode: single export with all forms
        if ($exportMode === 'combined') {
            $export = $plugin->exports->createCombinedExport(
                $dataSource,
                array_map('intval', $entityIds),
                $format,
                [
                    'dateRange' => $dateRange,
                    'dateStart' => $dateStart,
                    'dateEnd' => $dateEnd,
                    'siteIds' => $siteIds,
                ]
            );

            if ($processImmediately) {
                $plugin->exports->generateCombinedExport($export);

                if ($export->isCompleted()) {
                    Craft::$app->getSession()->setNotice(Craft::t('report-manager', 'Combined export generated successfully.'));
                } else {
                    Craft::$app->getSession()->setError(Craft::t('report-manager', 'Export generation failed: {error}', [
                        'error' => $export->errorMessage,
                    ]));
                }
            } else {
                Craft::$app->getQueue()->push(new GenerateExportJob([
                    'exportId' => $export->id,
                    'combined' => true,
                ]));

                Craft::$app->getSession()->setNotice(Craft::t('report-manager', 'Combined export queued for generation.'));
            }

            return $this->redirect('report-manager/exports/' . $export->id);
        }

        // Separate mode: one export per form
        $exports = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($entityIds as $entityId) {
            $export = $plugin->exports->createExport(
                $dataSource,
                (int) $entityId,
                $format,
                [
                    'dateRange' => $dateRange,
                    'dateStart' => $dateStart,
                    'dateEnd' => $dateEnd,
                    'fieldHandles' => [],
                    'siteIds' => $siteIds,
                ]
            );

            $exports[] = $export;

            if ($processImmediately) {
                $plugin->exports->generateExport($export);

                if ($export->isCompleted()) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            } else {
                Craft::$app->getQueue()->push(new GenerateExportJob([
                    'exportId' => $export->id,
                ]));
            }
        }

        $exportCount = count($exports);

        if ($processImmediately) {
            if ($failCount === 0) {
                Craft::$app->getSession()->setNotice(Craft::t('report-manager', '{count} export(s) generated successfully.', [
                    'count' => $successCount,
                ]));
            } else {
                Craft::$app->getSession()->setError(Craft::t('report-manager', '{success} export(s) succeeded, {fail} failed.', [
                    'success' => $successCount,
                    'fail' => $failCount,
                ]));
            }
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('report-manager', '{count} export(s) queued for generation.', [
                'count' => $exportCount,
            ]));
        }

        return $this->redirect('report-manager/exports');
    }

    /**
     * Download export action
     *
     * @param int $id Export ID
     * @return Response
     * @since 5.0.0
     */
    public function actionDownload(int $id): Response
    {
        $plugin = ReportManager::getInstance();

        $export = $plugin->exports->getExportById($id);

        if (!$export) {
            throw new NotFoundHttpException(Craft::t('report-manager', 'Export not found'));
        }

        if (!$export->isCompleted()) {
            throw new NotFoundHttpException(Craft::t('report-manager', 'Export is not ready for download'));
        }

        // Check if file exists (supports both local and volume storage)
        if (!$plugin->exports->fileExists($export)) {
            throw new NotFoundHttpException(Craft::t('report-manager', 'Export file not found'));
        }

        // Determine content type
        $contentType = match ($export->format) {
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };

        // For volume storage, get file content and send as data
        if ($plugin->exports->isUsingVolume()) {
            $content = $plugin->exports->getFileContent($export);
            if ($content === null) {
                throw new NotFoundHttpException(Craft::t('report-manager', 'Export file not found'));
            }

            return Craft::$app->getResponse()->sendContentAsFile(
                $content,
                $export->filename,
                ['mimeType' => $contentType]
            );
        }

        // For local storage, send file directly
        return Craft::$app->getResponse()->sendFile(
            $export->filePath,
            $export->filename,
            ['mimeType' => $contentType]
        );
    }

    /**
     * Delete export action
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $exportId = Craft::$app->getRequest()->getRequiredBodyParam('id');

        $plugin = ReportManager::getInstance();

        if (!$plugin->exports->deleteExport($exportId)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('report-manager', 'Could not delete export.'),
            ]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Get export status (AJAX)
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        $exportId = Craft::$app->getRequest()->getRequiredParam('id');

        $plugin = ReportManager::getInstance();
        $export = $plugin->exports->getExportById($exportId);

        if (!$export) {
            return $this->asJson([
                'success' => false,
                'error' => 'Export not found',
            ]);
        }

        return $this->asJson([
            'success' => true,
            'status' => $export->status,
            'progress' => $export->progress,
            'errorMessage' => $export->errorMessage,
            'isCompleted' => $export->isCompleted(),
            'downloadUrl' => $export->isCompleted() ? $plugin->exports->getDownloadUrl($export) : null,
        ]);
    }

    /**
     * Bulk delete exports action
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $exportIds = Craft::$app->getRequest()->getRequiredBodyParam('exportIds');

        if (!is_array($exportIds) || empty($exportIds)) {
            return $this->asJson([
                'success' => false,
                'error' => 'No exports selected',
            ]);
        }

        $plugin = ReportManager::getInstance();
        $deleted = 0;

        foreach ($exportIds as $exportId) {
            if ($plugin->exports->deleteExport((int) $exportId)) {
                $deleted++;
            }
        }

        return $this->asJson([
            'success' => true,
            'deleted' => $deleted,
        ]);
    }
}

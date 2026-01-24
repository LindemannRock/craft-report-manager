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
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\ReportManager;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Reports Controller
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class ReportsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('reportManager:viewReports');

        // Require manage permission for edit/delete actions
        if (in_array($action->id, ['edit', 'save', 'delete', 'reorder', 'bulk-enable', 'bulk-disable', 'bulk-delete'], true)) {
            $this->requirePermission('reportManager:manageReports');
        }

        // Require export permissions for generate action
        if ($action->id === 'generate') {
            $this->requirePermission('reportManager:createExports');
        }

        // Require download permission for generated view
        if ($action->id === 'generated') {
            $this->requirePermission('reportManager:viewExports');
        }

        return true;
    }

    /**
     * Reports index action
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

        // Get filtered and paginated reports
        $result = $plugin->reports->getFilteredReports([
            'search' => $search,
            'enabled' => $statusFilter === 'enabled' ? true : ($statusFilter === 'disabled' ? false : null),
            'format' => $formatFilter !== 'all' ? $formatFilter : null,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
        ]);

        $dataSources = $plugin->dataSources->getAvailableDataSources();

        return $this->renderTemplate('report-manager/reports/index', [
            'settings' => $settings,
            'reports' => $result['reports'],
            'dataSources' => $dataSources,
            'page' => $page,
            'limit' => $limit,
            'offset' => $result['offset'],
            'totalCount' => $result['totalCount'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    /**
     * Edit report action
     *
     * @param int|null $reportId Report ID (null for new)
     * @param ReportRecord|null $report Report record (for validation errors)
     * @return Response
     * @since 5.0.0
     */
    public function actionEdit(?int $reportId = null, ?ReportRecord $report = null): Response
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();

        $isNew = $reportId === null;

        if ($report === null) {
            if ($isNew) {
                $report = new ReportRecord();
                $report->dateRange = $settings->defaultDateRange;
                $report->exportFormat = $settings->defaultExportFormat;
                $report->exportMode = 'separate';
            } else {
                $report = $plugin->reports->getReportById($reportId);

                if (!$report) {
                    throw new NotFoundHttpException(Craft::t('report-manager', 'Report not found'));
                }
            }
        }

        $dataSources = $plugin->dataSources->getAvailableDataSources();
        $dataSourceOptions = $plugin->dataSources->getDataSourceOptions();

        // Get all entities for the current data source
        $allEntities = $plugin->dataSources->getAllEntities();

        $currentDataSource = $report->dataSource;

        // For new reports, default to first available data source
        if (empty($currentDataSource) && !empty($dataSourceOptions)) {
            $currentDataSource = $dataSourceOptions[0]['value'] ?? null;
        }

        // Get entities for the current data source
        $entities = $allEntities[$currentDataSource]['entities'] ?? [];

        // Build site options
        $siteOptions = [];
        if (Craft::$app->getIsMultiSite()) {
            $siteOptions[] = ['value' => '', 'label' => Craft::t('report-manager', 'All Sites')];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $siteOptions[] = ['value' => $site->id, 'label' => $site->name];
            }
        }

        return $this->renderTemplate('report-manager/reports/edit', [
            'settings' => $settings,
            'report' => $report,
            'isNew' => $isNew,
            'dataSources' => $dataSources,
            'dataSourceOptions' => $dataSourceOptions,
            'allEntities' => $allEntities,
            'entities' => $entities,
            'siteOptions' => $siteOptions,
            'dateRangeOptions' => $settings->getDateRangeOptions(),
            'exportFormatOptions' => $settings->getExportFormatOptions(),
            'scheduleOptions' => $settings->getScheduleOptions(),
        ]);
    }

    /**
     * Save report action
     *
     * @return Response|null
     * @since 5.0.0
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = ReportManager::getInstance();
        $request = Craft::$app->getRequest();

        $reportId = $request->getBodyParam('reportId');
        $isNew = !$reportId;

        // Track if scheduling was previously disabled (to trigger initial run)
        $wasScheduleDisabled = true;

        if ($isNew) {
            $report = new ReportRecord();
        } else {
            $report = $plugin->reports->getReportById($reportId);

            if (!$report) {
                throw new NotFoundHttpException(Craft::t('report-manager', 'Report not found'));
            }

            // Remember previous state
            $wasScheduleDisabled = !$report->enableSchedule;
        }

        // Populate from POST
        $report->name = $request->getBodyParam('name');
        $report->handle = $request->getBodyParam('handle');
        $report->description = $request->getBodyParam('description');
        $report->dataSource = $request->getBodyParam('dataSource');
        $report->dateRange = $request->getBodyParam('dateRange');
        $report->exportFormat = $request->getBodyParam('exportFormat');

        // Validate export format is enabled
        $configFormat = $report->exportFormat === 'xlsx' ? 'excel' : $report->exportFormat;
        if (!ExportHelper::isFormatEnabled($configFormat)) {
            throw new BadRequestHttpException(Craft::t('report-manager', 'Export format "{format}" is not enabled.', [
                'format' => $report->exportFormat,
            ]));
        }

        $report->exportMode = $request->getBodyParam('exportMode', 'separate');
        $report->enableSchedule = (bool) $request->getBodyParam('enableSchedule');
        $report->schedule = $request->getBodyParam('schedule');
        $report->enabled = (bool) $request->getBodyParam('enabled', true);

        // Handle entity IDs (multiple forms)
        $entityIds = $request->getBodyParam('entityIds', []);
        $report->setEntityIdsArray(is_array($entityIds) ? array_map('intval', $entityIds) : []);

        // Handle site ID (null = all sites)
        $siteId = $request->getBodyParam('siteId');
        $report->siteId = !empty($siteId) ? (int) $siteId : null;

        // Handle custom date range
        $customDateStart = $request->getBodyParam('customDateStart');
        $customDateEnd = $request->getBodyParam('customDateEnd');

        $report->customDateStart = !empty($customDateStart['date'])
            ? new DateTime($customDateStart['date'])
            : null;
        $report->customDateEnd = !empty($customDateEnd['date'])
            ? new DateTime($customDateEnd['date'])
            : null;

        // Handle field handles
        $fieldHandles = $request->getBodyParam('fieldHandles');
        $report->setFieldHandlesArray(is_array($fieldHandles) ? $fieldHandles : []);

        if (!$plugin->reports->saveReport($report)) {
            Craft::$app->getSession()->setError(Craft::t('report-manager', 'Could not save report.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'report' => $report,
            ]);

            return null;
        }

        // If scheduling was just enabled, run initial export immediately
        $schedulingJustEnabled = $report->enableSchedule && $wasScheduleDisabled;
        if ($schedulingJustEnabled && $report->enabled) {
            $this->queueInitialScheduledExport($report);
        }

        Craft::$app->getSession()->setNotice(Craft::t('report-manager', 'Report saved.'));

        return $this->redirectToPostedUrl($report);
    }

    /**
     * Queue initial export when scheduling is first enabled
     *
     * @param ReportRecord $report
     * @since 5.0.0
     */
    private function queueInitialScheduledExport(ReportRecord $report): void
    {
        $plugin = ReportManager::getInstance();
        $entityIds = $report->getEntityIdsArray();
        $siteIds = $report->siteId ? [$report->siteId] : [];

        // Combined mode: single export with all forms
        if ($report->isCombined()) {
            $export = $plugin->exports->createCombinedExport(
                $report->dataSource,
                $entityIds,
                $report->exportFormat,
                [
                    'reportId' => $report->id,
                    'dateRange' => $report->dateRange,
                    'dateStart' => $report->customDateStart,
                    'dateEnd' => $report->customDateEnd,
                    'fieldHandles' => $report->getFieldHandlesArray(),
                    'siteIds' => $siteIds,
                    'triggeredBy' => \lindemannrock\reportmanager\records\ExportRecord::TRIGGER_SCHEDULED,
                ]
            );

            Craft::$app->getQueue()->push(new GenerateExportJob([
                'exportId' => $export->id,
                'combined' => true,
            ]));
        } else {
            // Separate mode: one export per form
            foreach ($entityIds as $entityId) {
                $export = $plugin->exports->createExport(
                    $report->dataSource,
                    $entityId,
                    $report->exportFormat,
                    [
                        'reportId' => $report->id,
                        'dateRange' => $report->dateRange,
                        'dateStart' => $report->customDateStart,
                        'dateEnd' => $report->customDateEnd,
                        'fieldHandles' => $report->getFieldHandlesArray(),
                        'siteIds' => $siteIds,
                        'triggeredBy' => \lindemannrock\reportmanager\records\ExportRecord::TRIGGER_SCHEDULED,
                    ]
                );

                Craft::$app->getQueue()->push(new GenerateExportJob([
                    'exportId' => $export->id,
                ]));
            }
        }

        // Update last generated timestamp
        $plugin->reports->updateLastGenerated($report);
    }

    /**
     * Delete report action
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $reportId = $request->getBodyParam('reportId') ?? $request->getBodyParam('id');

        $plugin = ReportManager::getInstance();

        if (!$plugin->reports->deleteReport($reportId)) {
            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('report-manager', 'Could not delete report.'),
                ]);
            }

            Craft::$app->getSession()->setError(Craft::t('report-manager', 'Could not delete report.'));

            return $this->redirect('report-manager/reports');
        }

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('report-manager', 'Report deleted.'));

        return $this->redirect('report-manager/reports');
    }

    /**
     * Reorder reports action
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $reportIds = Craft::$app->getRequest()->getRequiredBodyParam('ids');

        $plugin = ReportManager::getInstance();

        if (!$plugin->reports->reorderReports($reportIds)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('report-manager', 'Could not reorder reports.'),
            ]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Generate report export action
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionGenerate(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $reportId = $request->getRequiredBodyParam('reportId');

        $plugin = ReportManager::getInstance();
        $report = $plugin->reports->getReportById($reportId);

        if (!$report) {
            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('report-manager', 'Report not found'),
                ]);
            }
            throw new NotFoundHttpException(Craft::t('report-manager', 'Report not found'));
        }

        // Validate export format is still enabled
        $configFormat = $report->exportFormat === 'xlsx' ? 'excel' : $report->exportFormat;
        if (!ExportHelper::isFormatEnabled($configFormat)) {
            $errorMessage = Craft::t('report-manager', 'Export format "{format}" is not enabled. Please update the report settings.', [
                'format' => $report->exportFormat,
            ]);
            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'error' => $errorMessage,
                ]);
            }
            throw new BadRequestHttpException($errorMessage);
        }

        $entityIds = $report->getEntityIdsArray();
        $siteIds = $report->siteId ? [$report->siteId] : [];

        // Combined mode: single export with all forms
        if ($report->isCombined()) {
            $export = $plugin->exports->createCombinedExport(
                $report->dataSource,
                $entityIds,
                $report->exportFormat,
                [
                    'reportId' => $report->id,
                    'dateRange' => $report->dateRange,
                    'dateStart' => $report->customDateStart,
                    'dateEnd' => $report->customDateEnd,
                    'fieldHandles' => $report->getFieldHandlesArray(),
                    'siteIds' => $siteIds,
                ]
            );

            Craft::$app->getQueue()->push(new GenerateExportJob([
                'exportId' => $export->id,
                'combined' => true,
            ]));

            // Update last generated (manual run - don't affect schedule)
            $plugin->reports->updateLastGenerated($report);

            if ($request->getAcceptsJson()) {
                return $this->asJson(['success' => true]);
            }

            Craft::$app->getSession()->setNotice(Craft::t('report-manager', 'Export queued for generation.'));

            return $this->redirect('report-manager/reports/' . $report->id . '/generated');
        }

        // Separate mode: one export per form
        foreach ($entityIds as $entityId) {
            $export = $plugin->exports->createExport(
                $report->dataSource,
                $entityId,
                $report->exportFormat,
                [
                    'reportId' => $report->id,
                    'dateRange' => $report->dateRange,
                    'dateStart' => $report->customDateStart,
                    'dateEnd' => $report->customDateEnd,
                    'fieldHandles' => $report->getFieldHandlesArray(),
                    'siteIds' => $siteIds,
                ]
            );

            Craft::$app->getQueue()->push(new GenerateExportJob([
                'exportId' => $export->id,
            ]));
        }

        // Update last generated (manual run - don't affect schedule)
        $plugin->reports->updateLastGenerated($report);

        $count = count($entityIds);

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'count' => $count]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('report-manager', '{count} export(s) queued for generation.', [
            'count' => $count,
        ]));

        return $this->redirect('report-manager/reports/' . $report->id . '/generated');
    }

    /**
     * View generated exports for a report
     *
     * @param int $reportId Report ID
     * @return Response
     * @since 5.0.0
     */
    public function actionGenerated(int $reportId): Response
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();
        $request = Craft::$app->getRequest();

        $report = $plugin->reports->getReportById($reportId);

        if (!$report) {
            throw new NotFoundHttpException(Craft::t('report-manager', 'Report not found'));
        }

        // Pagination
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = $settings->itemsPerPage ?? 20;

        // Get exports for this report
        $result = $plugin->exports->getExportsForReport($reportId, [
            'page' => $page,
            'limit' => $limit,
        ]);

        return $this->renderTemplate('report-manager/reports/generated', [
            'settings' => $settings,
            'report' => $report,
            'exports' => $result['exports'],
            'page' => $page,
            'limit' => $limit,
            'offset' => $result['offset'],
            'totalCount' => $result['totalCount'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    /**
     * Bulk enable reports action
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $reportIds = Craft::$app->getRequest()->getRequiredBodyParam('reportIds');

        if (!is_array($reportIds) || empty($reportIds)) {
            return $this->asJson([
                'success' => false,
                'error' => 'No reports selected',
            ]);
        }

        $plugin = ReportManager::getInstance();
        $updated = 0;

        foreach ($reportIds as $reportId) {
            $report = $plugin->reports->getReportById((int) $reportId);

            if ($report && !$report->enabled) {
                $report->enabled = true;

                if ($report->save()) {
                    $updated++;
                }
            }
        }

        return $this->asJson([
            'success' => true,
            'count' => $updated,
        ]);
    }

    /**
     * Bulk disable reports action
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $reportIds = Craft::$app->getRequest()->getRequiredBodyParam('reportIds');

        if (!is_array($reportIds) || empty($reportIds)) {
            return $this->asJson([
                'success' => false,
                'error' => 'No reports selected',
            ]);
        }

        $plugin = ReportManager::getInstance();
        $updated = 0;

        foreach ($reportIds as $reportId) {
            $report = $plugin->reports->getReportById((int) $reportId);

            if ($report && $report->enabled) {
                $report->enabled = false;

                if ($report->save()) {
                    $updated++;
                }
            }
        }

        return $this->asJson([
            'success' => true,
            'count' => $updated,
        ]);
    }

    /**
     * Bulk delete reports action
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $reportIds = Craft::$app->getRequest()->getRequiredBodyParam('reportIds');

        if (!is_array($reportIds) || empty($reportIds)) {
            return $this->asJson([
                'success' => false,
                'error' => 'No reports selected',
            ]);
        }

        $plugin = ReportManager::getInstance();
        $deleted = 0;

        foreach ($reportIds as $reportId) {
            if ($plugin->reports->deleteReport((int) $reportId)) {
                $deleted++;
            }
        }

        return $this->asJson([
            'success' => true,
            'count' => $deleted,
        ]);
    }
}

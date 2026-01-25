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
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\ReportManager;
use yii\web\Response;

/**
 * Dashboard Controller
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class DashboardController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Permission check is done in actionIndex to allow redirect logic

        return true;
    }

    /**
     * Dashboard index action - lists all generated exports
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser();

        // If user doesn't have viewDashboard permission, redirect to first accessible section
        if (!$user->checkPermission('reportManager:viewDashboard')) {
            if ($user->checkPermission('reportManager:viewReports')) {
                return $this->redirect('report-manager/reports');
            }
            if ($user->checkPermission('reportManager:viewLogs')) {
                return $this->redirect('report-manager/logs');
            }
            if ($user->checkPermission('reportManager:manageSettings')) {
                return $this->redirect('report-manager/settings');
            }

            // No access at all - require permission (will show 403)
            $this->requirePermission('reportManager:viewDashboard');
        }

        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();
        $request = Craft::$app->getRequest();

        // Get query parameters
        $statusFilter = $request->getParam('status', 'all');
        $typeFilter = $request->getParam('type', 'all');
        $formatFilter = $request->getParam('format', 'all');
        $search = trim((string) $request->getParam('search', ''));
        $sort = $request->getParam('sort', 'dateCreated');
        $dir = $request->getParam('dir', 'desc');
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = $settings->itemsPerPage;
        $offset = ($page - 1) * $limit;

        // Build query
        $query = ExportRecord::find();

        // Apply status filter
        if ($statusFilter !== 'all') {
            $query->andWhere(['status' => $statusFilter]);
        }

        // Apply type filter (triggeredBy)
        if ($typeFilter !== 'all') {
            $query->andWhere(['triggeredBy' => $typeFilter]);
        }

        // Apply format filter
        if ($formatFilter !== 'all') {
            $query->andWhere(['format' => $formatFilter]);
        }

        // Apply search filter
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'entityName', $search],
                ['like', 'filename', $search],
            ]);
        }

        // Apply sorting
        $allowedSortFields = ['entityName', 'format', 'status', 'recordCount', 'fileSize', 'triggeredBy', 'dateCreated'];
        if (in_array($sort, $allowedSortFields, true)) {
            $query->orderBy([$sort => $dir === 'asc' ? SORT_ASC : SORT_DESC]);
        } else {
            $query->orderBy(['dateCreated' => SORT_DESC]);
        }

        // Get total count for pagination
        $totalCount = (clone $query)->count();
        $totalPages = max(1, (int) ceil($totalCount / $limit));

        // Apply pagination
        $query->offset($offset)->limit($limit);

        // Get exports
        $exports = $query->all();

        return $this->renderTemplate('report-manager/dashboard/index', [
            'settings' => $settings,
            'exports' => $exports,
            'page' => $page,
            'totalPages' => $totalPages,
            'offset' => $offset,
            'limit' => $limit,
            'totalCount' => $totalCount,
        ]);
    }
}

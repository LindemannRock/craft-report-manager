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
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\base\helpers\ExportHelper;
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
     */
    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser();
        $settings = ReportManager::getInstance()->getSettings();

        // If user doesn't have viewDashboard permission, redirect to first accessible section
        if (!$user->checkPermission('reportManager:viewDashboard')) {
            $sections = ReportManager::getInstance()->getCpSections($settings, false, true);
            $route = CpNavHelper::firstAccessibleRoute($user, $settings, $sections);
            if ($route) {
                return $this->redirect($route);
            }

            // No access at all - require permission (will show 403)
            $this->requirePermission('reportManager:viewDashboard');
        }

        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();
        $request = Craft::$app->getRequest();

        // ---- Param parsing + allowlist validation -------------------------
        $statusFilter = (string) $request->getParam('status', 'all');
        $validStatuses = ['all', 'pending', 'processing', 'completed', 'failed'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        $typeFilter = (string) $request->getParam('type', 'all');
        $validTypes = ['all', 'manual', 'scheduled', 'api'];
        if (!in_array($typeFilter, $validTypes, true)) {
            $typeFilter = 'all';
        }

        $formatFilter = (string) $request->getParam('format', 'all');
        $validFormats = array_merge(
            ['all'],
            array_column(ExportHelper::getFormatOptions(), 'value')
        );
        if (!in_array($formatFilter, $validFormats, true)) {
            $formatFilter = 'all';
        }

        $search = trim((string) $request->getParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $sort = (string) $request->getParam('sort', 'dateCreated');
        $dir = strtolower((string) $request->getParam('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);

        // ---- Fetch filtered + paginated exports ---------------------------
        $result = $plugin->exports->getFilteredExports([
            'search' => $search,
            'status' => $statusFilter !== 'all' ? $statusFilter : null,
            'format' => $formatFilter !== 'all' ? $formatFilter : null,
            'triggeredBy' => $typeFilter !== 'all' ? $typeFilter : null,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
        ]);

        $exports = $result['exports'];
        $totalCount = $result['totalCount'];
        $totalPages = $result['totalPages'];
        $offset = $result['offset'];
        $exportFileExists = $plugin->exports->getFileAvailabilityMap($exports);

        $userComponent = Craft::$app->getUser();

        return $this->renderTemplate('report-manager/dashboard/index', [
            'settings' => $settings,
            'exports' => $exports,
            'exportFileExists' => $exportFileExists,
            'statusFilter' => $statusFilter,
            'typeFilter' => $typeFilter,
            'formatFilter' => $formatFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'totalPages' => $totalPages,
            'offset' => $offset,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'canDownload' => $userComponent->checkPermission('reportManager:downloadExports'),
            'canDelete' => $userComponent->checkPermission('reportManager:deleteExports'),
            'canManageReports' => $userComponent->checkPermission('reportManager:manageReports'),
        ]);
    }
}

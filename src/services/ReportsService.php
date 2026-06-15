<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\RecurringQueueHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\reportmanager\jobs\GenerateExportJob;
use lindemannrock\reportmanager\jobs\ProcessScheduledReportJob;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\ReportManager;

/**
 * Reports Service
 *
 * Manages saved report configurations.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class ReportsService extends Component
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(ReportManager::$plugin->id);
    }

    /**
     * Get all reports
     *
     * @param bool $enabledOnly Only return enabled reports
     * @return ReportRecord[]
     */
    public function getAllReports(bool $enabledOnly = false): array
    {
        $query = ReportRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC]);

        if ($enabledOnly) {
            $query->where(['enabled' => true]);
        }

        /** @var ReportRecord[] */
        return $query->all();
    }

    /**
     * Get filtered and paginated reports
     *
     * @param array $params Filter parameters
     * @return array{reports: ReportRecord[], totalCount: int, totalPages: int, offset: int}
     */
    public function getFilteredReports(array $params = []): array
    {
        $search = $params['search'] ?? '';
        $enabled = $params['enabled'] ?? null;
        $format = $params['format'] ?? null;
        $sort = $params['sort'] ?? 'dateCreated';
        $dir = $params['dir'] ?? 'desc';
        $page = max(1, $params['page'] ?? 1);
        $limit = $params['limit'] ?? 20;

        // Build query
        $query = ReportRecord::find();

        // Apply filters
        if ($enabled !== null) {
            $query->andWhere(['enabled' => $enabled]);
        }

        if ($format !== null) {
            $query->andWhere(['exportFormat' => $format]);
        }

        if (!empty($search)) {
            $query->andWhere([
                'or',
                ['like', 'name', $search],
                ['like', 'handle', $search],
                ['like', 'dataSource', $search],
            ]);
        }

        // Get total count before pagination
        $totalCount = (int) $query->count();

        // Apply sorting
        $validSortFields = [
            'name',
            'handle',
            'dataSource',
            'dateRange',
            'exportFormat',
            'lastGeneratedAt',
            'nextScheduledAt',
            'enabled',
            'dateCreated',
            'sortOrder',
        ];

        if (in_array($sort, $validSortFields, true)) {
            $sortDirection = strtolower($dir) === 'asc' ? SORT_ASC : SORT_DESC;
            $query->orderBy([$sort => $sortDirection]);
        } else {
            $query->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC]);
        }

        // Calculate pagination
        $offset = ($page - 1) * $limit;
        $totalPages = max(1, (int) ceil($totalCount / $limit));

        // Apply pagination
        $query->offset($offset)->limit($limit);

        /** @var ReportRecord[] $reports */
        $reports = $query->all();

        return [
            'reports' => $reports,
            'totalCount' => $totalCount,
            'totalPages' => $totalPages,
            'offset' => $offset,
        ];
    }

    /**
     * Get reports by data source
     *
     * @param string $dataSource Data source handle
     * @param bool $enabledOnly Only return enabled reports
     * @return ReportRecord[]
     */
    public function getReportsByDataSource(string $dataSource, bool $enabledOnly = false): array
    {
        $query = ReportRecord::find()
            ->where(['dataSource' => $dataSource])
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC]);

        if ($enabledOnly) {
            $query->andWhere(['enabled' => true]);
        }

        /** @var ReportRecord[] */
        return $query->all();
    }

    /**
     * Get a report by ID
     *
     * @param int $id Report ID
     * @return ReportRecord|null
     */
    public function getReportById(int $id): ?ReportRecord
    {
        return ReportRecord::findOne($id);
    }

    /**
     * Get a report by handle
     *
     * @param string $handle Report handle
     * @return ReportRecord|null
     */
    public function getReportByHandle(string $handle): ?ReportRecord
    {
        return ReportRecord::findOne(['handle' => $handle]);
    }

    /**
     * Save a report
     *
     * @param ReportRecord $report Report record to save
     * @return bool
     */
    public function saveReport(ReportRecord $report): bool
    {
        $isNew = $report->getIsNewRecord();

        $report->handle = SlugHandleHelper::normalizeSlug($report->handle, (string)$report->name);

        if ($isNew && $report->handle !== '') {
            $report->handle = SlugHandleHelper::makeUnique(
                ReportRecord::tableName(),
                'handle',
                $report->handle,
            );
        }

        if (!ReportManager::getInstance()->getSettings()->enableScheduledReports) {
            $report->enableSchedule = false;
            $report->nextScheduledAt = null;
        }

        // Calculate next scheduled time if schedule is enabled
        if ($report->enableSchedule && !empty($report->schedule)) {
            $report->nextScheduledAt = $this->calculateNextScheduledTime($report->schedule);
        } else {
            $report->nextScheduledAt = null;
        }

        // Set sort order for new reports
        if ($isNew && $report->sortOrder === 0) {
            $maxSortOrder = ReportRecord::find()
                ->max('sortOrder');
            $report->sortOrder = ($maxSortOrder ?? 0) + 1;
        }

        if (!$report->save()) {
            $this->logError('Failed to save report', [
                'errors' => $report->getErrors(),
                'name' => $report->name,
            ]);
            return false;
        }

        $this->logInfo($isNew ? 'Report created' : 'Report updated', [
            'id' => $report->id,
            'name' => $report->name,
            'handle' => $report->handle,
        ]);

        if ($report->enabled && $report->enableSchedule) {
            $this->queueScheduledReportJob($report);
        } else {
            $this->deleteScheduledReportJobs((int) $report->id);
        }

        return true;
    }

    /**
     * Delete a report
     *
     * @param int $id Report ID
     * @return bool
     */
    public function deleteReport(int $id): bool
    {
        $report = $this->getReportById($id);

        if (!$report) {
            return false;
        }

        $name = $report->name;
        $this->deleteScheduledReportJobs((int) $report->id);

        if (!$report->delete()) {
            $this->logError('Failed to delete report', [
                'id' => $id,
                'errors' => $report->getErrors(),
            ]);
            return false;
        }

        $this->logInfo('Report deleted', [
            'id' => $id,
            'name' => $name,
        ]);

        return true;
    }

    /**
     * Get scheduled reports that are due to run
     *
     * @return ReportRecord[]
     */
    public function getScheduledReportsDue(): array
    {
        $now = new DateTime();

        /** @var ReportRecord[] */
        return ReportRecord::find()
            ->where(['enabled' => true])
            ->andWhere(['enableSchedule' => true])
            ->andWhere(['<=', 'nextScheduledAt', Db::prepareDateForDb($now)])
            ->all();
    }

    /**
     * Queue scheduled report jobs for all active scheduled reports.
     *
     * @return int Number of queued reports
     */
    public function queueAllScheduledReportJobs(): int
    {
        if (!ReportManager::getInstance()->getSettings()->enableScheduledReports) {
            return 0;
        }

        /** @var ReportRecord[] $reports */
        $reports = ReportRecord::find()
            ->where(['enabled' => true])
            ->andWhere(['enableSchedule' => true])
            ->andWhere(['not', ['nextScheduledAt' => null]])
            ->all();

        $queued = 0;

        foreach ($reports as $report) {
            if ($this->queueScheduledReportJob($report, false)) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Queue the next scheduled run for one report.
     *
     * @param ReportRecord $report
     * @param bool $replaceExisting
     * @return bool
     */
    public function queueScheduledReportJob(ReportRecord $report, bool $replaceExisting = true): bool
    {
        if (!ReportManager::getInstance()->getSettings()->enableScheduledReports) {
            return false;
        }

        $nextScheduledAt = $this->normalizeDateTime($report->nextScheduledAt);

        if (!$report->id || !$report->enabled || !$report->enableSchedule || !$nextScheduledAt) {
            return false;
        }

        if ($replaceExisting) {
            $this->deleteScheduledReportJobs((int) $report->id);
        }

        $delay = max(0, $nextScheduledAt->getTimestamp() - DateFormatHelper::now()->getTimestamp());
        $includeYear = $nextScheduledAt->format('Y') !== DateFormatHelper::now()->format('Y');
        $runAtTime = DateFormatHelper::formatCompactDatetimeFromSettings(
            $nextScheduledAt,
            ReportManager::getInstance()->getSettings(),
            false,
            false,
            $includeYear,
        );

        $queueDelay = max(1, $delay);

        RecurringQueueHelper::ensurePending(
            pluginToken: 'reportmanager',
            jobClass: ProcessScheduledReportJob::class,
            delay: $queueDelay,
            jobFactory: static fn(): ProcessScheduledReportJob => new ProcessScheduledReportJob([
                'reportId' => (int) $report->id,
                'runAtTime' => $runAtTime,
            ]),
            extraLikeTokens: [$this->scheduledReportQueueToken((int) $report->id)],
        );

        $this->logInfo('Queued scheduled report job', [
            'reportId' => $report->id,
            'delay_seconds' => $queueDelay,
            'run_at' => $runAtTime,
        ]);

        return true;
    }

    /**
     * Delete queued scheduled-report jobs.
     *
     * @param int|null $reportId Limit deletion to a report ID
     * @return int Number of deleted queue rows
     */
    public function deleteScheduledReportJobs(?int $reportId = null): int
    {
        $condition = [
            'and',
            ['like', 'job', 'reportmanager'],
            [
                'or',
                ['like', 'job', 'ProcessScheduledReportJob'],
                ['like', 'job', 'ProcessScheduledReportsJob'],
            ],
        ];

        if ($reportId !== null) {
            $condition[] = ['like', 'job', $this->scheduledReportQueueToken($reportId)];
        }

        return (int) Craft::$app->getDb()->createCommand()->delete('{{%queue}}', $condition)->execute();
    }

    /**
     * Delete queued legacy global scheduled-report processor jobs.
     *
     * @return int Number of deleted queue rows
     */
    public function deleteLegacyScheduledReportJobs(): int
    {
        return (int) Craft::$app->getDb()->createCommand()->delete('{{%queue}}', [
            'and',
            ['like', 'job', 'reportmanager'],
            ['like', 'job', 'ProcessScheduledReportsJob'],
        ])->execute();
    }

    /**
     * Create scheduled export records for a report and queue generation jobs.
     *
     * @param ReportRecord $report
     * @return int Number of generation jobs queued
     */
    public function queueScheduledReportExports(ReportRecord $report): int
    {
        $plugin = ReportManager::getInstance();
        $entityIds = $report->getEntityIdsArray();
        $siteIds = $report->getSiteIdsArray();
        $queued = 0;

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
                    'dateField' => $report->dateField,
                    'fieldHandles' => $report->getFieldHandlesArray(),
                    'siteIds' => $siteIds,
                    'triggeredBy' => ExportRecord::TRIGGER_SCHEDULED,
                    'triggeredByUserId' => null,
                ]
            );

            Craft::$app->getQueue()->push(new GenerateExportJob([
                'exportId' => $export->id,
                'combined' => true,
            ]));

            return 1;
        }

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
                    'dateField' => $report->dateField,
                    'fieldHandles' => $report->getFieldHandlesArray(),
                    'siteIds' => $siteIds,
                    'triggeredBy' => ExportRecord::TRIGGER_SCHEDULED,
                    'triggeredByUserId' => null,
                ]
            );

            Craft::$app->getQueue()->push(new GenerateExportJob([
                'exportId' => $export->id,
            ]));

            $queued++;
        }

        return $queued;
    }

    /**
     * Mark report as generated by scheduled job and update schedule
     *
     * Updates lastGeneratedAt and calculates next fixed schedule time.
     * Only call this from scheduled jobs, not manual runs.
     *
     * @param ReportRecord $report Report record
     * @return bool
     */
    public function markReportGenerated(ReportRecord $report): bool
    {
        $report->lastGeneratedAt = new DateTime();

        if ($report->enableSchedule && !empty($report->schedule)) {
            $report->nextScheduledAt = $this->calculateNextScheduledTime($report->schedule);
        }

        return $report->save();
    }

    /**
     * Get the serialized queue token for a scheduled report ID.
     */
    private function scheduledReportQueueToken(int $reportId): string
    {
        return 's:8:"reportId";i:' . $reportId . ';';
    }

    /**
     * Normalize database date values to DateTime.
     *
     * @param mixed $value
     * @return DateTime|null
     */
    private function normalizeDateTime(mixed $value): ?DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return new DateTime($value, new DateTimeZone('UTC'));
        }

        return null;
    }

    /**
     * Update last generated timestamp for manual runs
     *
     * Only updates lastGeneratedAt without affecting nextScheduledAt.
     * Use this for manual report generation.
     *
     * @param ReportRecord $report Report record
     * @return bool
     */
    public function updateLastGenerated(ReportRecord $report): bool
    {
        $report->lastGeneratedAt = new DateTime();

        return $report->save();
    }

    /**
     * Reorder reports
     *
     * @param array $reportIds Ordered array of report IDs
     * @return bool
     */
    public function reorderReports(array $reportIds): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($reportIds as $sortOrder => $id) {
                Craft::$app->getDb()->createCommand()
                    ->update(
                        ReportRecord::tableName(),
                        ['sortOrder' => $sortOrder + 1],
                        ['id' => $id]
                    )
                    ->execute();
            }

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->logError('Failed to reorder reports', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Calculate next scheduled time using fixed intervals
     *
     * Uses fixed time slots to prevent drift:
     * - every6hours: 00:00, 06:00, 12:00, 18:00
     * - every12hours: 00:00, 12:00
     * - daily: 00:00
     * - daily2am: 02:00
     * - weekly: configured Craft week start day at 00:00
     * - monthly/every2months/quarterly/every6months/yearly: based on the day/time the report is scheduled
     *
     * @param string $schedule Schedule identifier
     * @return DateTime
     */
    private function calculateNextScheduledTime(string $schedule): DateTime
    {
        $next = ScheduleHelper::calculateNext($schedule) ?? ScheduleHelper::calculateNext('daily');
        if ($next === null) {
            throw new \RuntimeException('Unable to calculate the next scheduled report run.');
        }

        return $next;
    }

    /**
     * Get report count by data source
     *
     * @return array<string, int>
     */
    public function getReportCountByDataSource(): array
    {
        $counts = [];

        $results = ReportRecord::find()
            ->select(['dataSource', 'COUNT(*) as count'])
            ->groupBy(['dataSource'])
            ->asArray()
            ->all();

        foreach ($results as $result) {
            $counts[$result['dataSource']] = (int) $result['count'];
        }

        return $counts;
    }
}

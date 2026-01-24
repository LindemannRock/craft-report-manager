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
use craft\helpers\StringHelper;
use DateTime;
use lindemannrock\logginglibrary\traits\LoggingTrait;
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
        $this->setLoggingHandle('report-manager');
    }

    /**
     * Get all reports
     *
     * @param bool $enabledOnly Only return enabled reports
     * @return ReportRecord[]
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
     */
    public function saveReport(ReportRecord $report): bool
    {
        $isNew = $report->getIsNewRecord();

        // Ensure handle is set and unique
        if (empty($report->handle)) {
            $report->handle = $this->generateHandle($report->name);
        }

        // Ensure handle is unique
        $existingReport = ReportRecord::findOne(['handle' => $report->handle]);
        if ($existingReport && (!$report->id || $existingReport->id !== $report->id)) {
            $report->handle = $this->generateUniqueHandle($report->handle);
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

        return true;
    }

    /**
     * Delete a report
     *
     * @param int $id Report ID
     * @return bool
     * @since 5.0.0
     */
    public function deleteReport(int $id): bool
    {
        $report = $this->getReportById($id);

        if (!$report) {
            return false;
        }

        $name = $report->name;

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
     * @since 5.0.0
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
     * Mark report as generated by scheduled job and update schedule
     *
     * Updates lastGeneratedAt and calculates next fixed schedule time.
     * Only call this from scheduled jobs, not manual runs.
     *
     * @param ReportRecord $report Report record
     * @return bool
     * @since 5.0.0
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
     * Update last generated timestamp for manual runs
     *
     * Only updates lastGeneratedAt without affecting nextScheduledAt.
     * Use this for manual report generation.
     *
     * @param ReportRecord $report Report record
     * @return bool
     * @since 5.0.0
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
     * @since 5.0.0
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
     * Generate a handle from a name
     *
     * @param string $name Report name
     * @return string
     */
    private function generateHandle(string $name): string
    {
        return StringHelper::toKebabCase($name);
    }

    /**
     * Generate a unique handle
     *
     * @param string $handle Base handle
     * @return string
     */
    private function generateUniqueHandle(string $handle): string
    {
        $baseHandle = $handle;
        $counter = 1;

        while (ReportRecord::findOne(['handle' => $handle]) !== null) {
            $handle = $baseHandle . '-' . $counter;
            $counter++;
        }

        return $handle;
    }

    /**
     * Calculate next scheduled time using fixed intervals
     *
     * Uses fixed time slots to prevent drift:
     * - every6hours: 00:00, 06:00, 12:00, 18:00
     * - every12hours: 00:00, 12:00
     * - daily: 00:00
     * - daily2am: 02:00
     * - weekly: Monday 00:00
     *
     * @param string $schedule Schedule identifier
     * @return DateTime
     */
    private function calculateNextScheduledTime(string $schedule): DateTime
    {
        $now = new DateTime();

        return match ($schedule) {
            'every6hours' => $this->getNextFixedHour($now, [0, 6, 12, 18]),
            'every12hours' => $this->getNextFixedHour($now, [0, 12]),
            'daily' => $this->getNextFixedHour($now, [0]),
            'daily2am' => $this->getNextFixedHour($now, [2]),
            'weekly' => $this->getNextWeekday($now, 1), // Monday
            default => $this->getNextFixedHour($now, [0]),
        };
    }

    /**
     * Get next occurrence of a fixed hour
     *
     * @param DateTime $from Starting point
     * @param int[] $hours Valid hours (0-23)
     * @return DateTime
     */
    private function getNextFixedHour(DateTime $from, array $hours): DateTime
    {
        $next = (clone $from)->setTime((int) $from->format('H'), 0, 0);
        $currentHour = (int) $from->format('G');

        // Find the next valid hour today
        foreach ($hours as $hour) {
            if ($hour > $currentHour || ($hour === $currentHour && (int) $from->format('i') === 0 && (int) $from->format('s') === 0)) {
                // If we're exactly at this hour, skip to next slot
                if ($hour === $currentHour) {
                    continue;
                }
                return (clone $from)->setTime($hour, 0, 0);
            }
        }

        // No valid hour today, use first hour tomorrow
        return (clone $from)->modify('+1 day')->setTime($hours[0], 0, 0);
    }

    /**
     * Get next occurrence of a weekday
     *
     * @param DateTime $from Starting point
     * @param int $weekday Day of week (1=Monday, 7=Sunday)
     * @return DateTime
     */
    private function getNextWeekday(DateTime $from, int $weekday): DateTime
    {
        $next = (clone $from)->setTime(0, 0, 0);
        $currentWeekday = (int) $from->format('N');

        if ($currentWeekday === $weekday && $from->format('H:i:s') === '00:00:00') {
            // Exactly at this weekday midnight, skip to next week
            $next->modify('+1 week');
        } elseif ($currentWeekday >= $weekday) {
            // Already past this weekday, go to next week
            $daysUntil = 7 - $currentWeekday + $weekday;
            $next->modify("+{$daysUntil} days");
        } else {
            // This weekday is coming up
            $daysUntil = $weekday - $currentWeekday;
            $next->modify("+{$daysUntil} days");
        }

        return $next;
    }

    /**
     * Get report count by data source
     *
     * @return array<string, int>
     * @since 5.0.0
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

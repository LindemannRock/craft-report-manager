<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\ReportManager;

/**
 * Process Scheduled Reports Job
 *
 * Queue job for processing scheduled reports.
 * This job checks for due reports and generates exports for them.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class ProcessScheduledReportsJob extends BaseJob
{
    use LoggingTrait;

    /**
     * @var bool Whether to reschedule this job after completion
     */
    public bool $reschedule = true;

    /**
     * @var string|null Next run time display string for queued jobs
     */
    public ?string $nextRunTime = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('report-manager');

        // Calculate and set next run time if not already set
        if ($this->reschedule && !$this->nextRunTime) {
            $delay = $this->calculateNextRunDelay();
            if ($delay > 0) {
                $this->nextRunTime = date('M j, g:ia', time() + $delay);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enableScheduledReports) {
            $this->setProgress($queue, 1, 'Scheduled reports disabled');
            return;
        }

        // Get reports that are due
        $dueReports = $plugin->reports->getScheduledReportsDue();

        if (empty($dueReports)) {
            $this->setProgress($queue, 1, 'No scheduled reports due');
            $this->rescheduleIfNeeded();
            return;
        }

        $total = count($dueReports);
        $processed = 0;

        foreach ($dueReports as $report) {
            $this->setProgress(
                $queue,
                $processed / $total,
                Craft::t('report-manager', 'Processing report: {name}', ['name' => $report->name])
            );

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
                        'triggeredBy' => ExportRecord::TRIGGER_SCHEDULED,
                        'triggeredByUserId' => null,
                    ]
                );

                $plugin->exports->generateCombinedExport($export);
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
                            'triggeredBy' => ExportRecord::TRIGGER_SCHEDULED,
                            'triggeredByUserId' => null,
                        ]
                    );

                    $plugin->exports->generateExport($export);
                }
            }

            // Update report schedule
            $plugin->reports->markReportGenerated($report);

            $processed++;
        }

        $this->setProgress($queue, 1, Craft::t('report-manager', 'Processed {count} reports', ['count' => $processed]));

        // Cleanup old exports
        $plugin->exports->cleanupOldExports();

        // Reschedule this job
        $this->rescheduleIfNeeded();
    }

    /**
     * Reschedule this job if needed
     */
    private function rescheduleIfNeeded(): void
    {
        if (!$this->reschedule) {
            return;
        }

        $settings = ReportManager::getInstance()->getSettings();

        if (!$settings->enableScheduledReports) {
            return;
        }

        $delay = $this->calculateNextRunDelay();

        if ($delay > 0) {
            $nextRunTime = date('M j, g:ia', time() + $delay);

            $job = new self([
                'reschedule' => true,
                'nextRunTime' => $nextRunTime,
            ]);

            Craft::$app->getQueue()->delay($delay)->push($job);

            $this->logInfo('Scheduled next reports processing job', [
                'delay_seconds' => $delay,
                'next_run' => $nextRunTime,
            ]);
        }
    }

    /**
     * Calculate the delay in seconds for the next run
     *
     * Uses fixed time slots to prevent drift:
     * - every6hours: 00:00, 06:00, 12:00, 18:00
     * - every12hours: 00:00, 12:00
     * - daily: 00:00
     * - daily2am: 02:00
     * - weekly: Monday 00:00
     */
    private function calculateNextRunDelay(): int
    {
        $settings = ReportManager::getInstance()->getSettings();
        $now = new \DateTime();

        $nextRun = match ($settings->defaultSchedule) {
            'every6hours' => $this->getNextFixedHour($now, [0, 6, 12, 18]),
            'every12hours' => $this->getNextFixedHour($now, [0, 12]),
            'daily' => $this->getNextFixedHour($now, [0]),
            'daily2am' => $this->getNextFixedHour($now, [2]),
            'weekly' => $this->getNextWeekday($now, 1),
            default => $this->getNextFixedHour($now, [0]),
        };

        return max(60, $nextRun->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Get next occurrence of a fixed hour
     *
     * @param \DateTime $from Starting point
     * @param int[] $hours Valid hours (0-23)
     * @return \DateTime
     */
    private function getNextFixedHour(\DateTime $from, array $hours): \DateTime
    {
        $currentHour = (int) $from->format('G');
        $currentMinute = (int) $from->format('i');

        // Find the next valid hour today
        foreach ($hours as $hour) {
            if ($hour > $currentHour || ($hour === $currentHour && $currentMinute === 0)) {
                if ($hour === $currentHour && $currentMinute === 0) {
                    continue; // Skip current slot
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
     * @param \DateTime $from Starting point
     * @param int $weekday Day of week (1=Monday, 7=Sunday)
     * @return \DateTime
     */
    private function getNextWeekday(\DateTime $from, int $weekday): \DateTime
    {
        $next = (clone $from)->setTime(0, 0, 0);
        $currentWeekday = (int) $from->format('N');

        if ($currentWeekday === $weekday && $from->format('H:i:s') === '00:00:00') {
            $next->modify('+1 week');
        } elseif ($currentWeekday >= $weekday) {
            $daysUntil = 7 - $currentWeekday + $weekday;
            $next->modify("+{$daysUntil} days");
        } else {
            $daysUntil = $weekday - $currentWeekday;
            $next->modify("+{$daysUntil} days");
        }

        return $next;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $settings = ReportManager::getInstance()->getSettings();
        $description = Craft::t('report-manager', '{pluginName}: Processing scheduled reports', [
            'pluginName' => $settings->getDisplayName(),
        ]);

        if ($this->nextRunTime) {
            $description .= " ({$this->nextRunTime})";
        }

        return $description;
    }
}

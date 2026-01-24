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
     */
    private function calculateNextRunDelay(): int
    {
        $settings = ReportManager::getInstance()->getSettings();

        return match ($settings->defaultSchedule) {
            'every6hours' => 6 * 60 * 60,
            'every12hours' => 12 * 60 * 60,
            'daily', 'daily2am' => 24 * 60 * 60,
            'weekly' => 7 * 24 * 60 * 60,
            default => 24 * 60 * 60,
        };
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

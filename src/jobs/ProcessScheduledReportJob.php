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
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\reportmanager\ReportManager;
use yii\queue\RetryableJobInterface;

/**
 * Process Scheduled Report Job
 *
 * Queue job for one scheduled report run.
 */
class ProcessScheduledReportJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
    use LoggingTrait;

    /**
     * @var int Report record ID
     */
    public int $reportId;

    /**
     * @var string|null Scheduled run time display string for queued jobs
     */
    public ?string $runAtTime = null;

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(ReportManager::$plugin->id);
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = ReportManager::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enableScheduledReports) {
            $this->setProgress($queue, 1, Craft::t('report-manager', 'Scheduled reports disabled'));
            return;
        }

        $report = $plugin->reports->getReportById($this->reportId);

        if (!$report || !$report->enabled || !$report->enableSchedule || empty($report->schedule)) {
            $this->setProgress($queue, 1, Craft::t('report-manager', 'Scheduled report no longer active'));
            return;
        }

        $rawNextScheduledAt = $report->getAttribute('nextScheduledAt');
        $nextScheduledAt = $rawNextScheduledAt instanceof \DateTime
            ? $rawNextScheduledAt
            : ($rawNextScheduledAt ? new \DateTime((string) $rawNextScheduledAt, new \DateTimeZone('UTC')) : null);

        if ($nextScheduledAt && $nextScheduledAt > new \DateTime()) {
            $this->setProgress($queue, 1, Craft::t('report-manager', 'Scheduled report is not due yet'));
            $plugin->reports->queueScheduledReportJob($report);
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('report-manager', 'Queueing report exports'));

        $queuedCount = $plugin->reports->queueScheduledReportExports($report);
        $plugin->reports->markReportGenerated($report);
        $plugin->reports->queueScheduledReportJob($report);

        $this->setProgress($queue, 1, Craft::t('report-manager', 'Queued {count} export(s)', [
            'count' => $queuedCount,
        ]));
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $settings = ReportManager::getInstance()->getSettings();
        $report = ReportManager::getInstance()->reports->getReportById($this->reportId);
        $name = $report?->name ?? Craft::t('report-manager', 'Report #{id}', ['id' => $this->reportId]);

        $description = Craft::t('report-manager', '{pluginName}: Scheduled report - {name}', [
            'pluginName' => $settings->getDisplayName(),
            'name' => $name,
        ]);

        if ($this->runAtTime) {
            $description .= " ({$this->runAtTime})";
        }

        return $description;
    }
}

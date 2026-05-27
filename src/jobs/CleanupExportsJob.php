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
 * Cleanup Exports Job
 *
 * Queue job for generated export retention cleanup.
 */
class CleanupExportsJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
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

        if (!$settings->autoCleanupExports || $settings->exportRetention <= 0) {
            $this->setProgress($queue, 1, Craft::t('report-manager', 'Export cleanup disabled'));
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('report-manager', 'Cleaning up old exports'));
        $deletedCount = $plugin->exports->cleanupOldExports();
        $this->setProgress($queue, 1, Craft::t('report-manager', 'Cleaned up {count} export(s)', [
            'count' => $deletedCount,
        ]));

        $plugin->scheduleNextExportCleanupJob();
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $settings = ReportManager::getInstance()->getSettings();
        $description = Craft::t('report-manager', '{pluginName}: Cleaning up old exports', [
            'pluginName' => $settings->getDisplayName(),
        ]);

        if ($this->nextRunTime) {
            $description .= " ({$this->nextRunTime})";
        }

        return $description;
    }
}

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
use lindemannrock\reportmanager\export\ExportContinuation;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\ReportManager;
use yii\queue\RetryableJobInterface;

/**
 * Generate Export Job
 *
 * Queue job for generating export files asynchronously.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class GenerateExportJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;

    /**
     * @var int Export record ID
     */
    public int $exportId;

    /**
     * @var bool Whether this is a combined export (multiple entities)
     */
    public bool $combined = false;

    /**
     * Durable step identity; default preserves already-queued payloads.
     *
     * @internal
     * @since 5.6.1
     */
    public int $sequence = 0;

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        $export = ExportRecord::findOne($this->exportId);
        if ($export === null || (!ExportContinuation::supports($export)
            && !isset($export->getMetadataArray()[ExportContinuation::STATE_KEY]))) {
            return false;
        }
        if ($attempt < 3) {
            return true;
        }
        ExportContinuation::fail($this->exportId, $error, $this->sequence);

        return false;
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $export = ExportRecord::findOne($this->exportId);

        if (!$export) {
            Craft::warning("Export #{$this->exportId} not found", 'report-manager');
            return;
        }

        if (ExportContinuation::supports($export)
            || isset($export->getMetadataArray()[ExportContinuation::STATE_KEY])) {
            try {
                ReportManager::getInstance()->exports->continueQueuedExport(
                    $this->exportId,
                    $this->sequence,
                    $queue,
                    function(int $progress, string $label) use ($queue): void {
                        $this->setProgress($queue, $progress / 100, $label);
                    },
                );
            } catch (\Throwable $error) {
                Craft::error("Export #{$this->exportId}, step {$this->sequence}: {$error->getMessage()}", 'report-manager');
                if ($error instanceof \lindemannrock\reportmanager\exceptions\ExportStorageUnavailableException) {
                    throw $error;
                }
                throw new \RuntimeException(ExportContinuation::failureMessage(), previous: $error);
            }
            $fresh = ExportRecord::findOne($this->exportId);
            if ($fresh !== null) {
                $this->setProgress($queue, $fresh->progress / 100, $fresh->getStatusLabel());
            }
            return;
        }

        // Check if export is still pending
        if (!$export->isPending()) {
            Craft::warning("Export #{$this->exportId} is not in pending status", 'report-manager');
            return;
        }

        // Generate the export
        $this->setProgress($queue, 0.1, Craft::t('report-manager', 'Starting export generation...'));
        $progressCallback = function(int $progress) use ($queue, $export): void {
            $queueProgress = 0.1 + (max(1, min(99, $progress)) / 100 * 0.89);
            $this->setProgress($queue, min(0.99, $queueProgress), $export->getStatusLabel());
        };

        $exportService = ReportManager::getInstance()->exports;

        // Use provider, combined, or standard generation based on export type
        if ($export->isProviderExport()) {
            $success = $exportService->generateQueuedExport($export, $progressCallback);
        } elseif ($this->combined || $export->isCombinedExport()) {
            $success = $exportService->generateCombinedExport($export, $progressCallback);
        } else {
            $success = $exportService->generateExport($export, $progressCallback);
        }

        if ($success) {
            $this->setProgress($queue, 1, Craft::t('report-manager', 'Export completed'));
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $settings = ReportManager::getInstance()->getSettings();
        $export = ExportRecord::findOne($this->exportId);

        if ($export) {
            return Craft::t('report-manager', '{pluginName}: Generating export - {name}', [
                'pluginName' => $settings->getDisplayName(),
                'name' => $export->entityName ?? "Export #{$this->exportId}",
            ]);
        }

        return Craft::t('report-manager', '{pluginName}: Generating export #{id}', [
            'pluginName' => $settings->getDisplayName(),
            'id' => $this->exportId,
        ]);
    }
}

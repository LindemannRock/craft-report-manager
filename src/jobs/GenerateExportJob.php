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
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\ReportManager;

/**
 * Generate Export Job
 *
 * Queue job for generating export files asynchronously.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class GenerateExportJob extends BaseJob
{
    /**
     * @var int Export record ID
     */
    public int $exportId;

    /**
     * @var bool Whether this is a combined export (multiple forms)
     */
    public bool $combined = false;

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

        // Check if export is still pending
        if (!$export->isPending()) {
            Craft::warning("Export #{$this->exportId} is not in pending status", 'report-manager');
            return;
        }

        // Generate the export
        $this->setProgress($queue, 0.1, 'Starting export generation...');

        $exportService = ReportManager::getInstance()->exports;

        // Use combined or standard generation based on export type
        if ($this->combined || $export->isCombinedExport()) {
            $success = $exportService->generateCombinedExport($export);
        } else {
            $success = $exportService->generateExport($export);
        }

        if ($success) {
            $this->setProgress($queue, 1, 'Export completed');
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $export = ExportRecord::findOne($this->exportId);

        if ($export) {
            return Craft::t('report-manager', 'Generating export: {name}', [
                'name' => $export->entityName ?? "Export #{$this->exportId}",
            ]);
        }

        return Craft::t('report-manager', 'Generating export #{id}', ['id' => $this->exportId]);
    }
}

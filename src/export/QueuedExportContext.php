<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\export;

use Closure;
use lindemannrock\reportmanager\records\ExportRecord;

/**
 * Queued Export Context
 *
 * Runtime context passed to queued export providers.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.3.0
 */
class QueuedExportContext
{
    /**
     * @var ExportRecord Export record being generated
     */
    private ExportRecord $export;

    /**
     * @var Closure|null Progress callback
     */
    private ?Closure $progressCallback;

    /**
     * @param ExportRecord $export Export record being generated
     * @param callable|null $progressCallback Progress callback receiving percent and optional message
     */
    public function __construct(ExportRecord $export, ?callable $progressCallback = null)
    {
        $this->export = $export;
        $this->progressCallback = $progressCallback !== null ? Closure::fromCallable($progressCallback) : null;
    }

    /**
     * Get the export record.
     *
     * @return ExportRecord
     */
    public function getExport(): ExportRecord
    {
        return $this->export;
    }

    /**
     * Get the requested output format.
     *
     * @return string
     */
    public function getFormat(): string
    {
        return $this->export->format;
    }

    /**
     * Update queued export progress.
     *
     * @param int $progress Progress percentage from 0 to 100
     * @param string|null $message Optional provider progress message
     */
    public function updateProgress(int $progress, ?string $message = null): void
    {
        if ($this->progressCallback === null) {
            return;
        }

        ($this->progressCallback)($progress, $message);
    }
}

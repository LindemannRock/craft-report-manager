<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\tests\Support;

use lindemannrock\reportmanager\export\ExportContinuation;
use lindemannrock\reportmanager\services\ExportService;

/**
 * Creates a fresh continuation worker for each tested queue execution.
 *
 * @since 5.7.0
 */
class ControlledExportService extends ExportService
{
    protected function createContinuation(): ExportContinuation
    {
        return new ControlledExportContinuation();
    }
}

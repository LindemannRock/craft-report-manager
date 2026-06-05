<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use lindemannrock\reportmanager\controllers\SettingsController;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.4.0
 */
#[CoversClass(SettingsController::class)]
final class SettingsControllerSectionScopeTest extends TestCase
{
    public function testSettingsSectionsMatchRenderedFormScopes(): void
    {
        $controller = new SettingsController('settings', ReportManager::$plugin);
        $method = new \ReflectionMethod($controller, '_validationAttributesForSection');

        $expected = [
            'general' => [
                'pluginName',
                'logLevel',
            ],
            'interface' => [
                'itemsPerPage',
                'timeFormat',
                'monthFormat',
                'dateOrder',
                'dateSeparator',
                'showSeconds',
                'defaultDateRange',
                'exportsCsv',
                'exportsJson',
                'exportsExcel',
            ],
            'scheduling' => [
                'enableScheduledReports',
                'defaultSchedule',
            ],
            'export' => [
                'exportVolumeUid',
                'exportPath',
                'defaultExportFormat',
                'maxExportBatchSize',
                'csvDelimiter',
                'csvEnclosure',
                'csvIncludeBom',
                'exportRetention',
                'autoCleanupExports',
            ],
        ];

        foreach ($expected as $section => $attributes) {
            self::assertSame($attributes, $method->invoke($controller, $section), "Unexpected {$section} settings scope.");
        }
    }
}

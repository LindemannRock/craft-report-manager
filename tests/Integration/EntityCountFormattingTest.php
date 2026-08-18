<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use lindemannrock\reportmanager\tests\TestCase;

/**
 * Entity counts use the active Craft locale in every selection interface.
 *
 * @since 5.6.0
 */
final class EntityCountFormattingTest extends TestCase
{
    public function testReportEditorFormatsAjaxEntityCounts(): void
    {
        $contents = $this->readTemplate('reports/edit.twig');

        self::assertStringContainsString('recordCount|number', $contents);
        self::assertStringContainsString('Craft.formatNumber(recordCount)', $contents);
        self::assertStringNotContainsString("' + recordCount + ' ' + recordLabel", $contents);
    }

    public function testQuickExportFormatsInitialAndAjaxEntityCounts(): void
    {
        $contents = $this->readTemplate('exports/new.twig');

        self::assertStringContainsString('recordCount|number', $contents);
        self::assertStringContainsString('Craft.formatNumber(recordCount)', $contents);
        self::assertStringNotContainsString("' + recordCount + ' ' + recordLabel", $contents);
    }

    private function readTemplate(string $relativePath): string
    {
        $templatePath = dirname(__DIR__, 2) . '/src/templates/' . $relativePath;

        return (string)file_get_contents($templatePath);
    }
}

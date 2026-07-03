<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use lindemannrock\reportmanager\tests\TestCase;

/**
 * @since 5.6.0
 */
final class TemplateEscapingTest extends TestCase
{
    public function testExportPathInfoBoxEscapesDynamicPath(): void
    {
        $templatePath = dirname(__DIR__, 2) . '/src/templates/settings/export.twig';
        $contents = (string)file_get_contents($templatePath);

        self::assertStringContainsString('exportPath|e', $contents);
        self::assertStringNotContainsString('~ settings.getExportPath() ~', $contents);
    }
}

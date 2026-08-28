<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use Craft;
use lindemannrock\reportmanager\models\Settings;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Fresh-install settings and saved-report persistence contracts.
 *
 * @since 5.6.0
 */
final class FreshInstallationSettingsAndReportsTest extends TestCase
{
    public function testFreshSettingsSchemaUsesSupportedExportWindowDefaultAndCurrentFields(): void
    {
        $schema = Craft::$app->getDb()->getTableSchema('{{%reportmanager_settings}}', true);

        self::assertNotNull($schema);
        self::assertSame(1000, (int)$schema->columns['maxExportBatchSize']->defaultValue);
        self::assertArrayNotHasKey('enableAnalytics', $schema->columns);
        self::assertArrayNotHasKey('dashboardRefreshInterval', $schema->columns);
        self::assertSame(1000, (new Settings())->maxExportBatchSize);
    }

    public function testExportWindowValidationAcceptsOnlyThePublishedRange(): void
    {
        foreach ([100, 1000] as $batchSize) {
            $settings = new Settings(['maxExportBatchSize' => $batchSize]);
            self::assertTrue($settings->validate(['maxExportBatchSize']), (string)json_encode($settings->getErrors()));
        }

        foreach ([99, 1001] as $batchSize) {
            $settings = new Settings(['maxExportBatchSize' => $batchSize]);
            self::assertFalse($settings->validate(['maxExportBatchSize']));
            self::assertNotEmpty($settings->getErrors('maxExportBatchSize'));
        }

        $template = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/export.twig');
        self::assertIsString($template);
        self::assertStringContainsString('min: 100,', $template);
        self::assertStringContainsString('max: 1000,', $template);
    }

    public function testSelectedSitesRoundTripThroughTheOnlyReportSiteColumn(): void
    {
        $schema = Craft::$app->getDb()->getTableSchema(ReportRecord::tableName(), true);
        self::assertNotNull($schema);
        self::assertArrayHasKey('siteIds', $schema->columns);
        self::assertArrayNotHasKey('siteId', $schema->columns);

        $siteIds = array_slice(array_map('intval', Craft::$app->getSites()->getAllSiteIds()), 0, 2);
        self::assertNotEmpty($siteIds);
        $report = new ReportRecord([
            'name' => self::MARKER . ' Site selection',
            'handle' => self::MARKER . 'site-selection',
            'dataSource' => self::MARKER . 'source',
            'dateRange' => 'last30days',
            'exportFormat' => 'csv',
            'exportMode' => 'separate',
            'enableSchedule' => false,
            'enabled' => true,
            'sortOrder' => 0,
        ]);
        $report->setEntityIdsArray([1]);
        $report->setSiteIdsArray($siteIds);

        try {
            self::assertTrue($report->save(false), (string)json_encode($report->getErrors()));
            $fresh = ReportRecord::findOne($report->id);
            self::assertNotNull($fresh);
            self::assertSame($siteIds, $fresh->getSiteIdsArray());
        } finally {
            if ($report->id !== null) {
                Craft::$app->getDb()->createCommand()
                    ->delete(ReportRecord::tableName(), ['id' => $report->id])
                    ->execute();
            }
        }
    }
}

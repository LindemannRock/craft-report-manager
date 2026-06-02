<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Pins report handle normalization and duplicate handling.
 *
 * @since 5.4.0
 */
final class ReportHandleUniquenessTest extends TestCase
{
    private const HANDLE_PREFIX = 'rm-test-';

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteMarkerReports();
    }

    protected function tearDown(): void
    {
        $this->deleteMarkerReports();
        parent::tearDown();
    }

    public function testNewDuplicateReportHandleAutoSuffixes(): void
    {
        $this->saveSeedReport('Existing Report', self::HANDLE_PREFIX . 'existing-report');

        $report = $this->makeReport('Existing Report', self::HANDLE_PREFIX . 'existing-report');

        self::assertTrue($this->reports->saveReport($report), implode(', ', $report->getFirstErrors()));
        self::assertSame(self::HANDLE_PREFIX . 'existing-report-1', $report->handle);
    }

    public function testExistingReportDuplicateHandleRejects(): void
    {
        $this->saveSeedReport('First Report', self::HANDLE_PREFIX . 'first-report');
        $report = $this->saveSeedReport('Second Report', self::HANDLE_PREFIX . 'second-report');

        $report->handle = self::HANDLE_PREFIX . 'first-report';

        self::assertFalse($this->reports->saveReport($report));
        self::assertSame('Handle must be unique.', $report->getFirstError('handle'));
    }

    public function testReportHandleNormalizesToKebabSlug(): void
    {
        $report = $this->makeReport('TEst this thing', 'RM Test Mixed Case');

        self::assertTrue($this->reports->saveReport($report), implode(', ', $report->getFirstErrors()));
        self::assertSame('rm-test-mixed-case', $report->handle);
    }

    private function makeReport(string $name, ?string $handle = null): ReportRecord
    {
        $report = new ReportRecord([
            'name' => $name,
            'handle' => $handle,
            'dataSource' => self::HANDLE_PREFIX . 'source',
            'dateRange' => 'last30days',
            'exportFormat' => 'csv',
            'exportMode' => 'separate',
            'enableSchedule' => false,
            'enabled' => true,
            'sortOrder' => 0,
        ]);
        $report->setEntityIdsArray([1]);

        return $report;
    }

    private function saveSeedReport(string $name, string $handle): ReportRecord
    {
        $report = $this->makeReport($name, $handle);

        self::assertTrue($report->save(false));

        return $report;
    }

    private function deleteMarkerReports(): void
    {
        \Craft::$app->getDb()->createCommand()
            ->delete(ReportRecord::tableName(), ['like', 'handle', self::HANDLE_PREFIX])
            ->execute();
    }
}

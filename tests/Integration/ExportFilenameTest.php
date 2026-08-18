<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use DateTime;
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\tests\Stubs\StubLargeExportDataSource;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Descriptive filename behavior for every standard export shape.
 *
 * @since 5.5.2
 */
final class ExportFilenameTest extends TestCase
{
    private ReportRecord $report;

    protected function setUp(): void
    {
        parent::setUp();

        $service = new DataSourcesService();
        $service->on(
            DataSourcesService::EVENT_REGISTER_DATA_SOURCES,
            static function(RegisterDataSourcesEvent $event): void {
                $event->register(
                    StubLargeExportDataSource::handle(),
                    StubLargeExportDataSource::displayName(),
                    StubLargeExportDataSource::class,
                );
            },
        );
        $this->swapPluginComponent('report-manager', 'dataSources', $service);

        $this->report = new ReportRecord([
            'name' => 'FPL (AR) / Client Name',
            'handle' => '__rm_test_fpl-ar',
            'dataSource' => StubLargeExportDataSource::handle(),
            'dateRange' => 'custom',
            'exportFormat' => 'xlsx',
            'exportMode' => 'combined',
            'enableSchedule' => false,
            'enabled' => true,
            'sortOrder' => 0,
        ]);
        $this->report->setEntityIdsArray([
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            StubLargeExportDataSource::SECONDARY_ENTITY_ID,
        ]);
        self::assertTrue($this->report->save(false));
    }

    /** @return iterable<string, array{string, string}> */
    public static function namedDateRangeProvider(): iterable
    {
        yield 'today' => ['today', 'today'];
        yield 'yesterday' => ['yesterday', 'yesterday'];
        yield 'this week' => ['thisWeek', 'this-week'];
        yield 'last week' => ['lastWeek', 'last-week'];
        yield 'last 7 days' => ['last7days', 'last-7-days'];
        yield 'last 14 days' => ['last14days', 'last-14-days'];
        yield 'last 30 days' => ['last30days', 'last-30-days'];
        yield 'last 90 days' => ['last90days', 'last-90-days'];
        yield 'this month' => ['thisMonth', 'this-month'];
        yield 'last month' => ['lastMonth', 'last-month'];
        yield 'this quarter' => ['thisQuarter', 'this-quarter'];
        yield 'last quarter' => ['lastQuarter', 'last-quarter'];
        yield 'this year' => ['thisYear', 'this-year'];
        yield 'last year' => ['lastYear', 'last-year'];
        yield 'last 12 months' => ['last12months', 'last-12-months'];
        yield 'all time' => ['all', 'all'];
        yield 'legacy alltime alias' => ['alltime', 'all'];
    }

    /** @return iterable<string, array{DateTime|null, DateTime|null, string}> */
    public static function customDateRangeProvider(): iterable
    {
        yield 'both bounds' => [
            new DateTime('2024-07-25 00:00:00'),
            new DateTime('2026-08-18 23:59:59'),
            '2024-07-25-to-2026-08-18',
        ];
        yield 'start only' => [new DateTime('2024-07-25 00:00:00'), null, 'from-2024-07-25'];
        yield 'end only' => [null, new DateTime('2026-08-18 23:59:59'), 'through-2026-08-18'];
        yield 'no bounds' => [null, null, 'custom'];
    }

    /** @return iterable<string, array{string}> */
    public static function exportFormatProvider(): iterable
    {
        yield 'CSV' => ['csv'];
        yield 'JSON' => ['json'];
        yield 'XLSX' => ['xlsx'];
    }

    public function testCombinedReportFilenameUsesHandleAndExactCustomRange(): void
    {
        $export = $this->createCombinedReportExport(
            'custom',
            new DateTime('2024-07-25 00:00:00'),
            new DateTime('2026-08-18 23:59:59'),
        );

        $this->assertTimestampedFilename(
            'rm_test_fpl-ar-combined-2024-07-25-to-2026-08-18',
            'xlsx',
            $export->filename,
        );
        self::assertStringNotContainsString('client-name', $export->filename);
    }

    public function testSeparateReportFilenameUsesHandleEntityAndExactCustomRange(): void
    {
        $export = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'csv',
            [
                'reportId' => $this->report->id,
                'dateRange' => 'custom',
                'dateStart' => new DateTime('2024-07-25 00:00:00'),
                'dateEnd' => new DateTime('2026-08-18 23:59:59'),
            ],
        );

        $this->assertTimestampedFilename(
            'rm_test_fpl-ar-primary-dataset-2024-07-25-to-2026-08-18',
            'csv',
            $export->filename,
        );
    }

    #[DataProvider('namedDateRangeProvider')]
    public function testEveryNamedRangeUsesAReadableStableSegment(string $dateRange, string $expectedPart): void
    {
        $export = $this->createCombinedReportExport($dateRange);

        $this->assertTimestampedFilename(
            "rm_test_fpl-ar-combined-{$expectedPart}",
            'xlsx',
            $export->filename,
        );
    }

    #[DataProvider('customDateRangeProvider')]
    public function testEveryCustomRangeShapeIsRepresented(
        ?DateTime $start,
        ?DateTime $end,
        string $expectedPart,
    ): void {
        $export = $this->createCombinedReportExport('custom', $start, $end);

        $this->assertTimestampedFilename(
            "rm_test_fpl-ar-combined-{$expectedPart}",
            'xlsx',
            $export->filename,
        );
    }

    #[DataProvider('exportFormatProvider')]
    public function testEveryStandardFormatGetsItsMatchingExtension(string $format): void
    {
        $export = $this->exports->createCombinedExport(
            StubLargeExportDataSource::handle(),
            [StubLargeExportDataSource::PRIMARY_ENTITY_ID],
            $format,
            [
                'reportId' => $this->report->id,
                'dateRange' => 'all',
            ],
        );

        $this->assertTimestampedFilename('rm_test_fpl-ar-combined-all', $format, $export->filename);
    }

    public function testAdHocExportFallsBackToDataSourcePrefix(): void
    {
        $export = $this->exports->createCombinedExport(
            StubLargeExportDataSource::handle(),
            [StubLargeExportDataSource::PRIMARY_ENTITY_ID],
            'json',
            [
                'dateRange' => 'custom',
                'dateStart' => new DateTime('2024-07-25 00:00:00'),
                'dateEnd' => new DateTime('2026-08-18 23:59:59'),
            ],
        );

        $this->assertTimestampedFilename(
            'rm_test_large_export-combined-2024-07-25-to-2026-08-18',
            'json',
            $export->filename,
        );
    }

    public function testMissingDateRangeOmitsOnlyTheRangeSegment(): void
    {
        $export = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'csv',
        );

        $this->assertTimestampedFilename(
            'rm_test_large_export-primary-dataset',
            'csv',
            $export->filename,
        );
    }

    public function testScheduledTriggerUsesTheSameDescriptivePattern(): void
    {
        $export = $this->exports->createCombinedExport(
            StubLargeExportDataSource::handle(),
            [StubLargeExportDataSource::PRIMARY_ENTITY_ID],
            'xlsx',
            [
                'reportId' => $this->report->id,
                'dateRange' => 'last30days',
                'triggeredBy' => ExportRecord::TRIGGER_SCHEDULED,
                'triggeredByUserId' => null,
            ],
        );

        self::assertSame(ExportRecord::TRIGGER_SCHEDULED, $export->triggeredBy);
        $this->assertTimestampedFilename(
            'rm_test_fpl-ar-combined-last-30-days',
            'xlsx',
            $export->filename,
        );
    }

    public function testQueuedProviderDefaultFilenameRemainsProviderOwned(): void
    {
        $this->installStubProviderService();
        $export = $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            'csv',
        );

        self::assertMatchesRegularExpression(
            '/^rm_test_provider_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.csv$/',
            $export->filename,
        );
    }

    private function createCombinedReportExport(
        string $dateRange,
        ?DateTime $dateStart = null,
        ?DateTime $dateEnd = null,
    ): ExportRecord {
        return $this->exports->createCombinedExport(
            StubLargeExportDataSource::handle(),
            [StubLargeExportDataSource::PRIMARY_ENTITY_ID, StubLargeExportDataSource::SECONDARY_ENTITY_ID],
            'xlsx',
            [
                'reportId' => $this->report->id,
                'dateRange' => $dateRange,
                'dateStart' => $dateStart,
                'dateEnd' => $dateEnd,
            ],
        );
    }

    private function assertTimestampedFilename(string $prefix, string $extension, string $actual): void
    {
        self::assertMatchesRegularExpression(
            '/^' . preg_quote($prefix, '/') . '-\d{4}-\d{2}-\d{2}-\d{6}\.' . preg_quote($extension, '/') . '$/',
            $actual,
        );
        self::assertLessThanOrEqual(255, strlen($actual));
    }
}

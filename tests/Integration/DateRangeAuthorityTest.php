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
use lindemannrock\reportmanager\datasources\BaseDataSource;
use lindemannrock\reportmanager\datasources\CategoriesDataSource;
use lindemannrock\reportmanager\datasources\EntriesDataSource;
use lindemannrock\reportmanager\datasources\FormieDataSource;
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\services\ExportService;
use lindemannrock\reportmanager\tests\Stubs\StubLargeExportDataSource;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Date-range authority across report and export runtime paths.
 *
 * @since 5.6.0
 */
#[CoversClass(BaseDataSource::class)]
#[CoversClass(ExportService::class)]
final class DateRangeAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installLargeExportDataSource();

        $storagePath = $this->createTrackedTempDirectory('report-date-range-');
        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $storagePath;
    }

    /** @return iterable<string, array{BaseDataSource}> */
    public static function standardSourceProvider(): iterable
    {
        yield 'entries' => [new EntriesDataSource()];
        yield 'categories' => [new CategoriesDataSource()];
        yield 'Formie' => [new FormieDataSource()];
    }

    #[DataProvider('standardSourceProvider')]
    public function testNamedRangesReplaceBothExplicitBoundsAcrossStandardSources(BaseDataSource $source): void
    {
        $staleStart = new DateTime('2001-01-01 00:00:00');
        $staleEnd = new DateTime('2099-12-31 23:59:59');

        self::assertSame(
            [$staleStart, $staleEnd],
            $this->resolve($source, ['dateStart' => $staleStart, 'dateEnd' => $staleEnd]),
        );
        self::assertSame(
            [$staleStart, $staleEnd],
            $this->resolve($source, [
                'dateRange' => 'custom',
                'dateStart' => $staleStart,
                'dateEnd' => $staleEnd,
            ]),
        );
        self::assertSame(
            [null, null],
            $this->resolve($source, [
                'dateRange' => 'all',
                'dateStart' => $staleStart,
                'dateEnd' => $staleEnd,
            ]),
        );

        [$todayStart, $todayEnd] = $this->resolve($source, [
            'dateRange' => 'today',
            'dateStart' => $staleStart,
            'dateEnd' => $staleEnd,
        ]);
        self::assertInstanceOf(DateTime::class, $todayStart);
        self::assertNull($todayEnd, 'A one-sided named range must not inherit the stale opposite bound.');
    }

    public function testSeparateAndCombinedCreationPersistOnlyCustomBounds(): void
    {
        $start = new DateTime('2026-03-30 00:00:00');
        $end = new DateTime('2026-04-30 23:59:59');

        $separate = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'csv',
            ['dateRange' => 'today', 'dateStart' => $start, 'dateEnd' => $end],
        );
        self::assertSame('today', $separate->dateRangeUsed);
        self::assertNull($separate->dateStartUsed);
        self::assertNull($separate->dateEndUsed);

        $combined = $this->exports->createCombinedExport(
            StubLargeExportDataSource::handle(),
            [StubLargeExportDataSource::PRIMARY_ENTITY_ID],
            'csv',
            ['dateRange' => 'all', 'dateStart' => $start, 'dateEnd' => $end],
        );
        self::assertSame('all', $combined->dateRangeUsed);
        self::assertNull($combined->dateStartUsed);
        self::assertNull($combined->dateEndUsed);

        $openEnded = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'csv',
            ['dateRange' => 'custom', 'dateStart' => $start],
        );
        self::assertNotNull($openEnded->dateStartUsed);
        self::assertNull($openEnded->dateEndUsed);

        $equal = $this->exports->createCombinedExport(
            StubLargeExportDataSource::handle(),
            [StubLargeExportDataSource::PRIMARY_ENTITY_ID],
            'csv',
            ['dateRange' => 'custom', 'dateStart' => $start, 'dateEnd' => $start],
        );
        self::assertNotNull($equal->dateStartUsed);
        self::assertSame($equal->dateStartUsed, $equal->dateEndUsed);
    }

    public function testPreviouslyPendingNamedExportsClearStaleBoundsDuringGeneration(): void
    {
        $separate = $this->stalePendingExport(false);
        self::assertTrue($this->exports->generateExport($separate));
        $freshSeparate = ExportRecord::findOne($separate->id);
        self::assertNotNull($freshSeparate);
        self::assertNull($freshSeparate->dateStartUsed);
        self::assertNull($freshSeparate->dateEndUsed);

        $combined = $this->stalePendingExport(true);
        self::assertTrue($this->exports->generateCombinedExport($combined));
        $freshCombined = ExportRecord::findOne($combined->id);
        self::assertNotNull($freshCombined);
        self::assertNull($freshCombined->dateStartUsed);
        self::assertNull($freshCombined->dateEndUsed);
    }

    public function testScheduledSeparateAndCombinedExportsCannotCarrySavedNamedBounds(): void
    {
        foreach (['separate', 'combined'] as $mode) {
            $report = new ReportRecord([
                'name' => 'Scheduled ' . $mode,
                'handle' => self::MARKER . 'scheduled_' . $mode,
                'dataSource' => StubLargeExportDataSource::handle(),
                'dateRange' => 'today',
                'customDateStart' => new DateTime('2001-01-01 00:00:00'),
                'customDateEnd' => new DateTime('2099-12-31 23:59:59'),
                'exportFormat' => 'csv',
                'exportMode' => $mode,
                'enableSchedule' => true,
                'schedule' => 'daily',
                'enabled' => true,
                'sortOrder' => 0,
            ]);
            $report->setEntityIdsArray([StubLargeExportDataSource::PRIMARY_ENTITY_ID]);
            self::assertTrue($report->save(false));

            $beforeIds = ExportRecord::find()->select('id')->column();
            $this->reports->queueScheduledReportExports($report);
            $exports = ExportRecord::find()->where(['not in', 'id', $beforeIds ?: [0]])->all();
            self::assertNotEmpty($exports);
            foreach ($exports as $export) {
                self::assertInstanceOf(ExportRecord::class, $export);
                self::assertSame(ExportRecord::TRIGGER_SCHEDULED, $export->triggeredBy);
                self::assertNull($export->dateStartUsed);
                self::assertNull($export->dateEndUsed);
            }
        }
    }

    /** @return array{0: DateTime|null, 1: DateTime|null} */
    private function resolve(BaseDataSource $source, array $options): array
    {
        $method = new ReflectionMethod($source, 'resolveDateBounds');

        /** @var array{0: DateTime|null, 1: DateTime|null} */
        return $method->invoke($source, $options);
    }

    private function stalePendingExport(bool $combined): ExportRecord
    {
        $export = $combined
            ? $this->exports->createCombinedExport(
                StubLargeExportDataSource::handle(),
                [StubLargeExportDataSource::PRIMARY_ENTITY_ID],
                'csv',
            )
            : $this->exports->createExport(
                StubLargeExportDataSource::handle(),
                StubLargeExportDataSource::PRIMARY_ENTITY_ID,
                'csv',
            );
        $export->dateRangeUsed = 'today';
        $export->dateStartUsed = new DateTime('2001-01-01 00:00:00');
        $export->dateEndUsed = new DateTime('2099-12-31 23:59:59');
        self::assertTrue($export->save(false));

        return $export;
    }

    private function installLargeExportDataSource(): void
    {
        StubLargeExportDataSource::reset();
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
    }
}

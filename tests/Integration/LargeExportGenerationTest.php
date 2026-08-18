<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\tests\Stubs\StubLargeExportDataSource;
use lindemannrock\reportmanager\tests\TestCase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Standard export generation across bounded source reads and disk-backed output.
 *
 * @since 5.5.2
 */
final class LargeExportGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

        // Simulate an upgraded 5.5.1 installation retaining the old default.
        $this->settings()->maxExportBatchSize = 10000;
        $this->settings()->csvDelimiter = ',';
        $this->settings()->csvEnclosure = '"';
        $this->settings()->csvIncludeBom = false;
    }

    /** @return iterable<string, array{string}> */
    public static function formatProvider(): iterable
    {
        yield 'CSV' => ['csv'];
        yield 'JSON' => ['json'];
        yield 'XLSX' => ['xlsx'];
    }

    #[DataProvider('formatProvider')]
    public function testStandardExportUsesBoundedReadsAndProducesEveryRow(string $format): void
    {
        $export = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            $format,
            ['fieldHandles' => ['id', 'shared']],
        );

        self::assertTrue($this->exports->generateExport($export));

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(ExportRecord::STATUS_COMPLETED, $fresh->status);
        self::assertSame(1205, $fresh->recordCount);
        self::assertTrue($this->exports->fileExists($fresh));
        self::assertSame(
            [
                ['entityId' => 1, 'fieldHandles' => ['id', 'shared'], 'limit' => 1000, 'offset' => 0],
                ['entityId' => 1, 'fieldHandles' => ['id', 'shared'], 'limit' => 1000, 'offset' => 1000],
            ],
            StubLargeExportDataSource::$exportRequests,
        );

        $this->assertGeneratedFile($fresh, $format, 1205);
    }

    public function testCombinedExportStreamsEachEntityAndAlignsSelectedColumns(): void
    {
        $export = $this->exports->createCombinedExport(
            StubLargeExportDataSource::handle(),
            [StubLargeExportDataSource::PRIMARY_ENTITY_ID, StubLargeExportDataSource::SECONDARY_ENTITY_ID],
            'csv',
            ['fieldHandles' => ['id', 'shared']],
        );

        self::assertSame(['id', 'shared'], $export->getFieldHandlesUsedArray());
        self::assertTrue($this->exports->generateCombinedExport($export));

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(1280, $fresh->recordCount);
        self::assertSame([1, 1, 2], array_column(StubLargeExportDataSource::$exportRequests, 'entityId'));
        self::assertSame([0, 1000, 0], array_column(StubLargeExportDataSource::$exportRequests, 'offset'));
        self::assertSame([1000, 1000, 1000], array_column(StubLargeExportDataSource::$exportRequests, 'limit'));

        $stream = $this->openExportStream($fresh);
        self::assertIsResource($stream);
        $header = fgetcsv($stream, escape: '');
        self::assertSame(['Dataset Name', 'Identifier', 'Shared Value'], $header);

        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        self::assertCount(1280, $rows);
        self::assertSame('Primary Dataset', $rows[0][0]);
        self::assertSame('Secondary Dataset', $rows[1205][0]);
        self::assertSame("'=SUM(1,1)", $rows[0][2]);
    }

    public function testConfiguredWindowBelowSafetyCeilingIsHonored(): void
    {
        $this->settings()->maxExportBatchSize = 100;
        $export = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'json',
            ['fieldHandles' => ['id']],
        );

        self::assertTrue($this->exports->generateExport($export));

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(1205, $fresh->recordCount);
        self::assertCount(13, StubLargeExportDataSource::$exportRequests);
        self::assertSame(
            range(0, 1200, 100),
            array_column(StubLargeExportDataSource::$exportRequests, 'offset'),
        );
        self::assertSame(
            array_fill(0, 13, 100),
            array_column(StubLargeExportDataSource::$exportRequests, 'limit'),
        );
    }

    private function assertGeneratedFile(ExportRecord $export, string $format, int $expectedRows): void
    {
        if ($format === 'csv') {
            $stream = $this->openExportStream($export);
            self::assertIsResource($stream);
            $rows = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $rows[] = $row;
            }
            fclose($stream);

            self::assertCount($expectedRows + 1, $rows);
            self::assertSame(['Identifier', 'Shared Value'], $rows[0]);
            self::assertSame("'=SUM(1,1)", $rows[1][1]);
            return;
        }

        if ($format === 'json') {
            $rows = json_decode($this->exportContents($export), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount($expectedRows, $rows);
            self::assertSame(['Identifier', 'Shared Value'], array_keys($rows[0]));
            self::assertSame('=SUM(1,1)', $rows[0]['Shared Value']);
            return;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'report-manager-test-xlsx-');
        self::assertIsString($tempPath);
        file_put_contents($tempPath, $this->exportContents($export));
        $spreadsheet = IOFactory::load($tempPath);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            self::assertSame($expectedRows + 1, $sheet->getHighestDataRow());
            self::assertSame('Shared Value', $sheet->getCell('B1')->getFormattedValue());
            self::assertSame('=SUM(1,1)', $sheet->getCell('B2')->getFormattedValue());
            self::assertSame(DataType::TYPE_INLINE, $sheet->getCell('B2')->getDataType());
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($tempPath);
        }
    }

    /** @return resource */
    private function openExportStream(ExportRecord $export)
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $this->exportContents($export));
        rewind($stream);

        return $stream;
    }

    private function exportContents(ExportRecord $export): string
    {
        $contents = $this->exports->getFileContent($export);
        self::assertNotNull($contents);

        return $contents;
    }
}

<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use Craft;
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\export\QueuedExportResult;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\tests\Stubs\StubDuplicateHeaderDataSource;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Lossless export serialization when human-facing column labels repeat.
 *
 * @since 5.6.0
 */
final class ExportColumnIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        StubDuplicateHeaderDataSource::reset();

        $service = new DataSourcesService();
        $service->on(
            DataSourcesService::EVENT_REGISTER_DATA_SOURCES,
            static function(RegisterDataSourcesEvent $event): void {
                $event->register(
                    StubDuplicateHeaderDataSource::handle(),
                    StubDuplicateHeaderDataSource::displayName(),
                    StubDuplicateHeaderDataSource::class,
                );
            },
        );
        $this->swapPluginComponent('report-manager', 'dataSources', $service);
        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $this->createTrackedTempDirectory('report-column-identity-') . '/';
        $this->settings()->maxExportBatchSize = 100;
        $this->settings()->csvDelimiter = ',';
        $this->settings()->csvEnclosure = '"';
        $this->settings()->csvIncludeBom = false;
    }

    /** @return iterable<string, array{string}> */
    public static function tableFormatProvider(): iterable
    {
        yield 'CSV' => ['csv'];
        yield 'JSON' => ['json'];
        yield 'XLSX' => ['xlsx'];
    }

    public function testSeparateJsonPreservesRepeatedAndUniqueColumns(): void
    {
        $export = $this->exports->createExport(
            StubDuplicateHeaderDataSource::handle(),
            StubDuplicateHeaderDataSource::PRIMARY_ENTITY_ID,
            'json',
        );

        self::assertTrue($this->exports->generateExport($export));
        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        [$headers, $rows] = $this->readTable($fresh, 'json');

        self::assertSame(['Repeated', 'Repeated (beta)', 'Dataset Name', 'Exact Unique'], $headers);
        self::assertCount(205, $rows);
        self::assertSame(
            ['alpha-1-0', 'beta-0', 'collision-1-0', 'unique-1-0'],
            array_values($rows[0]),
        );
        self::assertSame([0, 100, 200], array_column(StubDuplicateHeaderDataSource::$exportRequests, 'offset'));
    }

    #[DataProvider('tableFormatProvider')]
    public function testCombinedExportUsesOneStableHeaderPlanAcrossBatches(string $format): void
    {
        $export = $this->exports->createCombinedExport(
            StubDuplicateHeaderDataSource::handle(),
            [StubDuplicateHeaderDataSource::PRIMARY_ENTITY_ID, StubDuplicateHeaderDataSource::SECONDARY_ENTITY_ID],
            $format,
        );

        self::assertTrue($this->exports->generateCombinedExport($export));
        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        [$headers, $rows] = $this->readTable($fresh, $format);

        self::assertSame([
            'Dataset Name',
            'Repeated',
            'Repeated (beta)',
            'Dataset Name (primaryCollision)',
            'Exact Unique',
            'Repeated (gamma)',
        ], $headers);
        self::assertCount(207, $rows);
        self::assertSame(
            ['Primary Dataset', 'alpha-1-0', 'beta-0', 'collision-1-0', 'unique-1-0', ''],
            array_values($rows[0]),
        );
        self::assertSame(
            ['Secondary Dataset', 'alpha-2-0', '', 'collision-2-0', 'unique-2-0', 'gamma-0'],
            array_values($rows[205]),
        );
        self::assertSame(
            [
                ['entityId' => 1, 'limit' => 100, 'offset' => 0],
                ['entityId' => 1, 'limit' => 100, 'offset' => 100],
                ['entityId' => 1, 'limit' => 100, 'offset' => 200],
                ['entityId' => 2, 'limit' => 100, 'offset' => 0],
            ],
            StubDuplicateHeaderDataSource::$exportRequests,
        );
    }

    public function testCombinedHeaderContractFailureStoresTranslatedDisplayedMessage(): void
    {
        $originalLanguage = Craft::$app->language;
        Craft::$app->language = 'de';
        StubDuplicateHeaderDataSource::$returnDriftedHeaders = true;

        try {
            $export = $this->exports->createCombinedExport(
                StubDuplicateHeaderDataSource::handle(),
                [StubDuplicateHeaderDataSource::PRIMARY_ENTITY_ID],
                'json',
            );

            self::assertFalse($this->exports->generateCombinedExport($export));
            $fresh = ExportRecord::findOne($export->id);
            self::assertNotNull($fresh);
            $expected = Craft::t(
                'report-manager',
                'Export columns did not match the data source field contract.',
            );

            self::assertSame(
                'Die Exportspalten entsprachen nicht den von der Datenquelle definierten Feldern.',
                $expected,
            );
            self::assertSame($expected, $fresh->errorMessage);

            $template = file_get_contents(dirname(__DIR__, 2) . '/src/templates/exports/view.twig');
            self::assertIsString($template);
            self::assertStringContainsString('{{ export.errorMessage }}', $template);
            self::assertSame(
                $expected,
                Craft::$app->getView()->renderString('{{ export.errorMessage }}', ['export' => $fresh]),
            );
        } finally {
            StubDuplicateHeaderDataSource::$returnDriftedHeaders = false;
            Craft::$app->language = $originalLanguage;
        }
    }

    #[DataProvider('tableFormatProvider')]
    public function testProviderTablesUseStableOrdinalHeaders(string $format): void
    {
        $this->installStubProviderService();
        StubQueuedExportProvider::$nextResult = QueuedExportResult::table(
            ['Repeated', 'Repeated', 'Exact Unique'],
            [['first', 'second', 'unique']],
        );
        $export = $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            $format,
            options: ['filename' => 'provider-owned-name'],
        );

        self::assertTrue($this->exports->generateQueuedExport($export));
        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        [$headers, $rows] = $this->readTable($fresh, $format);

        self::assertSame(['Repeated', 'Repeated (2)', 'Exact Unique'], $headers);
        self::assertSame(['first', 'second', 'unique'], array_values($rows[0]));
        self::assertSame("provider-owned-name.{$format}", $fresh->filename);
    }

    /** @return array{string[], array<int, array<int|string, mixed>>} */
    private function readTable(ExportRecord $export, string $format): array
    {
        $contents = $this->exports->getFileContent($export);
        self::assertIsString($contents);

        if ($format === 'json') {
            $rows = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($rows);
            self::assertNotEmpty($rows);

            return [array_keys($rows[0]), $rows];
        }

        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+b');
            self::assertIsResource($stream);
            fwrite($stream, $contents);
            rewind($stream);
            $headers = fgetcsv($stream, escape: '');
            self::assertIsArray($headers);
            $rows = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $rows[] = $row;
            }
            fclose($stream);

            return [$headers, $rows];
        }

        $tempDirectory = $this->createTrackedTempDirectory('report-column-xlsx-');
        $path = $tempDirectory . '/export.xlsx';
        file_put_contents($path, $contents);
        $spreadsheet = IOFactory::load($path);
        try {
            $rows = $spreadsheet->getActiveSheet()->toArray();
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        $headers = array_shift($rows);
        self::assertIsArray($headers);

        return [$headers, $rows];
    }
}

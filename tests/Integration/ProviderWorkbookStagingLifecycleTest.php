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
use craft\base\FsInterface;
use craft\helpers\FileHelper;
use craft\services\Volumes;
use Error;
use lindemannrock\reportmanager\export\QueuedExportResult;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\storage\ExportStorage;
use lindemannrock\reportmanager\tests\Stubs\InspectableProviderWorkbookExportService;
use lindemannrock\reportmanager\tests\Stubs\StubExportVolume;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Exact temporary-file and spreadsheet ownership for provider workbooks.
 *
 * @since 5.6.0
 */
final class ProviderWorkbookStagingLifecycleTest extends TestCase
{
    private const VOLUME_UID = '__rm_test_workbook_volume';
    private const VOLUME_SUBPATH = '__rm_test_workbook_root';

    private Volumes $originalVolumes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalVolumes = Craft::$app->getVolumes();
        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $this->createTrackedTempDirectory('report-workbook-local-');
    }

    protected function tearDown(): void
    {
        Craft::$app->set('volumes', $this->originalVolumes);
        parent::tearDown();
    }

    public function testSuccessfulWorkbookPreservesContentAndReleasesOwnedStaging(): void
    {
        $service = $this->installWorkbookService();
        $export = $this->createWorkbookExport();

        self::assertTrue($service->generateQueuedExport($export));
        $this->assertOwnedStagingReleased($service);

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(ExportRecord::STATUS_COMPLETED, $fresh->status);
        self::assertSame('xlsx', $fresh->format);
        self::assertSame(3, $fresh->recordCount);
        self::assertSame(100, $fresh->progress);
        self::assertFileExists($fresh->filePath);
        self::assertSame('Xlsx', IOFactory::identify($fresh->filePath));

        $workbook = IOFactory::load($fresh->filePath);
        try {
            self::assertSame(['Sales_2026', 'Sales_2026 2'], $workbook->getSheetNames());
            $first = $workbook->getSheet(0);
            self::assertSame('Name', $first->getCell('A1')->getValue());
            self::assertSame('Amount', $first->getCell('B1')->getValue());
            self::assertSame('Alice', $first->getCell('A2')->getValue());
            self::assertSame(10, $first->getCell('B2')->getValue());
            self::assertSame('=SUM(1,1)', $first->getCell('A3')->getValue());
            self::assertSame(DataType::TYPE_STRING, $first->getCell('A3')->getDataType());
            self::assertTrue($first->getStyle('A1')->getFont()->getBold());
            self::assertSame('A2', $first->getFreezePane());

            $second = $workbook->getSheet(1);
            self::assertSame('Status', $second->getCell('A1')->getValue());
            self::assertSame('Ready', $second->getCell('A2')->getValue());
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    public function testWriterConstructionFailureReleasesOwnedStaging(): void
    {
        $service = $this->installWorkbookService();
        $service->writerConstructionFailure = new RuntimeException('__rm_test_writer_construction_failed');
        $export = $this->createWorkbookExport();

        self::assertFalse($service->generateQueuedExport($export));
        $this->assertFailedExport($export, '__rm_test_writer_construction_failed');
        $this->assertOwnedStagingReleased($service);
    }

    public function testWriterSaveExceptionReleasesOwnedStaging(): void
    {
        $this->assertWriterSaveFailureReleased(new RuntimeException('__rm_test_writer_save_exception'));
    }

    public function testWriterSaveErrorReleasesOwnedStaging(): void
    {
        $this->assertWriterSaveFailureReleased(new Error('__rm_test_writer_save_error'));
    }

    public function testFileReadFailureReleasesOwnedStaging(): void
    {
        $service = $this->installWorkbookService();
        $service->readFails = true;
        $export = $this->createWorkbookExport();

        self::assertFalse($service->generateQueuedExport($export));
        $this->assertFailedExport($export, 'Unable to read the temporary provider workbook file.');
        $this->assertOwnedStagingReleased($service);
    }

    public function testFinalLocalStorageWriteFailureReleasesOwnedStaging(): void
    {
        $service = $this->installWorkbookService();
        $service->finalWriteFailure = new RuntimeException('__rm_test_local_write_failed');
        $export = $this->createWorkbookExport();

        self::assertSame(ExportStorage::TYPE_LOCAL, $export->storageType);
        self::assertFalse($service->generateQueuedExport($export));
        self::assertSame([ExportStorage::TYPE_LOCAL], $service->finalWriteStorageTypes);
        $this->assertFailedExport($export, '__rm_test_local_write_failed');
        $this->assertOwnedStagingReleased($service);
        self::assertFileDoesNotExist($export->filePath);
    }

    public function testFinalRecordedVolumeWriteFailureReleasesOwnedStaging(): void
    {
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->expects(self::once())
            ->method('write')
            ->with(self::stringStartsWith(self::VOLUME_SUBPATH . '/report-manager/exports/'))
            ->willThrowException(new RuntimeException('__rm_test_volume_write_failed'));
        $this->installVolume($filesystem);
        $this->settings()->exportVolumeUid = self::VOLUME_UID;

        $service = $this->installWorkbookService();
        $export = $this->createWorkbookExport();

        self::assertSame(ExportStorage::TYPE_VOLUME, $export->storageType);
        self::assertSame(self::VOLUME_UID, $export->storageVolumeUid);
        self::assertStringStartsWith('report-manager/exports/', $export->filePath);
        self::assertFalse($service->generateQueuedExport($export));
        self::assertSame([ExportStorage::TYPE_VOLUME], $service->finalWriteStorageTypes);
        $this->assertFailedExport($export, ExportStorage::unavailableMessage());
        $this->assertOwnedStagingReleased($service);
    }

    public function testAllocationFailureDoesNotAttemptCleanupAgainstAnInvalidPath(): void
    {
        $service = $this->installWorkbookService();
        $service->allocationFails = true;
        $export = $this->createWorkbookExport();

        self::assertFalse($service->generateQueuedExport($export));
        $this->assertFailedExport($export, 'Unable to create a temporary provider workbook file.');
        self::assertSame([], $service->allocatedTempFiles);
        self::assertSame([], $service->cleanupAttempts);
        $this->assertSpreadsheetsDisconnected($service);
    }

    public function testCleanupFailureCannotReplaceTheOperationalFailure(): void
    {
        $service = $this->installWorkbookService();
        $service->writerSaveFailure = new RuntimeException('__rm_test_original_operational_failure');
        $service->cleanupFailure = new Error('__rm_test_secondary_cleanup_failure');
        $export = $this->createWorkbookExport();

        self::assertFalse($service->generateQueuedExport($export));
        $this->assertFailedExport($export, '__rm_test_original_operational_failure');
        $this->assertOwnedStagingReleased($service);
    }

    public function testCleanupLeavesAnUnrelatedSiblingByteForByteUnchanged(): void
    {
        $sibling = tempnam(sys_get_temp_dir(), 'rm_workbook_sibling_');
        self::assertIsString($sibling);

        try {
            $content = "__rm_test_unrelated_sibling\x00\x01";
            self::assertSame(strlen($content), file_put_contents($sibling, $content));
            $before = hash_file('sha256', $sibling);

            $service = $this->installWorkbookService();
            $export = $this->createWorkbookExport();
            self::assertTrue($service->generateQueuedExport($export));

            $this->assertOwnedStagingReleased($service);
            self::assertFileExists($sibling);
            self::assertSame($before, hash_file('sha256', $sibling));
            self::assertSame($content, file_get_contents($sibling));
            self::assertNotContains($sibling, $service->cleanupAttempts);
        } finally {
            FileHelper::unlink($sibling);
        }
    }

    public function testExactCleanupIsIdempotent(): void
    {
        $service = $this->installWorkbookService();
        $service->repeatCleanup = true;
        $export = $this->createWorkbookExport();

        self::assertTrue($service->generateQueuedExport($export));
        $this->assertOwnedStagingReleased($service);
    }

    public function testOverlappingWorkbookGenerationsOwnDistinctPaths(): void
    {
        $service = $this->installWorkbookService();
        $outer = $this->createWorkbookExport();
        $inner = $this->createWorkbookExport();
        $innerResult = null;

        $service->beforeWriterSave = function(string $outerTempFile) use ($service, $inner, &$innerResult): void {
            self::assertFileExists($outerTempFile);
            $innerResult = $service->generateQueuedExport($inner);
            self::assertFileExists($outerTempFile, 'Nested cleanup must not delete the outer generation staging file.');
        };

        self::assertTrue($service->generateQueuedExport($outer));
        self::assertTrue($innerResult);
        self::assertCount(2, array_unique($service->allocatedTempFiles));
        self::assertNotSame($service->allocatedTempFiles[0], $service->allocatedTempFiles[1]);
        $this->assertOwnedStagingReleased($service);
        self::assertCount(2, $service->spreadsheets);
    }

    private function assertWriterSaveFailureReleased(Throwable $failure): void
    {
        $service = $this->installWorkbookService();
        $service->writerSaveFailure = $failure;
        $export = $this->createWorkbookExport();

        self::assertFalse($service->generateQueuedExport($export));
        $this->assertFailedExport($export, $failure->getMessage());
        $this->assertOwnedStagingReleased($service);
    }

    private function installWorkbookService(): InspectableProviderWorkbookExportService
    {
        $this->installStubProviderService();
        $service = new InspectableProviderWorkbookExportService();
        $this->swapPluginComponent('report-manager', 'exports', $service);
        $this->exports = $service;

        return $service;
    }

    private function createWorkbookExport(): ExportRecord
    {
        StubQueuedExportProvider::$nextResult = QueuedExportResult::workbook([
            [
                'name' => 'Sales/2026',
                'headers' => ['Name', 'Amount'],
                'rows' => [
                    ['Alice', 10],
                    ['=SUM(1,1)', 20],
                ],
            ],
            [
                'name' => 'Sales/2026',
                'headers' => ['Status'],
                'rows' => [['Ready']],
            ],
        ]);

        return $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            'xlsx',
            options: ['filename' => '__rm_test_provider_workbook_' . bin2hex(random_bytes(6)) . '.xlsx'],
        );
    }

    private function assertFailedExport(ExportRecord $export, string $message): void
    {
        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(ExportRecord::STATUS_FAILED, $fresh->status);
        self::assertSame($message, $fresh->errorMessage);
        self::assertNotSame(100, $fresh->progress);
        self::assertNotNull($fresh->completedAt);
    }

    private function assertOwnedStagingReleased(InspectableProviderWorkbookExportService $service): void
    {
        self::assertNotSame([], $service->allocatedTempFiles);
        self::assertEqualsCanonicalizing($service->allocatedTempFiles, $service->cleanupAttempts);
        self::assertCount(count($service->allocatedTempFiles), array_unique($service->cleanupAttempts));

        foreach ($service->allocatedTempFiles as $tempFile) {
            self::assertFileDoesNotExist($tempFile);
        }

        $this->assertSpreadsheetsDisconnected($service);
    }

    private function assertSpreadsheetsDisconnected(InspectableProviderWorkbookExportService $service): void
    {
        self::assertNotSame([], $service->spreadsheets);
        foreach ($service->spreadsheets as $spreadsheet) {
            self::assertSame(0, $spreadsheet->getSheetCount());
        }
    }

    private function installVolume(FsInterface $filesystem): void
    {
        $volume = new StubExportVolume($filesystem, [
            'uid' => self::VOLUME_UID,
            'handle' => '__rm_test_workbook_volume',
            'name' => 'Report Manager Workbook Test Volume',
            'subpath' => self::VOLUME_SUBPATH,
        ]);
        $volumes = $this->createMock(Volumes::class);
        $volumes->method('getVolumeByUid')->willReturn($volume);
        Craft::$app->set('volumes', $volumes);
    }
}

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
use craft\fs\Local;
use craft\services\Volumes;
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\export\QueuedExportResult;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\storage\ExportStorage;
use lindemannrock\reportmanager\tests\Stubs\StubExportVolume;
use lindemannrock\reportmanager\tests\Stubs\StubLargeExportDataSource;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Record-specific export objects with stable consumer-facing filenames.
 *
 * @since 5.6.0
 */
final class ExportObjectIdentityTest extends TestCase
{
    private const LONG_SOURCE_HANDLE = '__rm_test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const VOLUME_UID = '__rm_test_object_identity_volume';
    private const VOLUME_SUBPATH = '__rm_test_object_identity';

    private Volumes $originalVolumes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalVolumes = Craft::$app->getVolumes();
        $this->installStubProviderService();
        $this->installLargeSource();
        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $this->createTrackedTempDirectory('report-object-identity-') . '/';
        $this->settings()->csvIncludeBom = false;
    }

    protected function tearDown(): void
    {
        Craft::$app->set('volumes', $this->originalVolumes);
        parent::tearDown();
    }

    public function testIdenticalLocalDisplayNamesProduceIndependentObjects(): void
    {
        $first = $this->createProviderExport('same-provider-name.csv', 'first bytes');
        $second = $this->createProviderExport('same-provider-name.csv', 'second bytes');

        self::assertSame($first->filename, $second->filename);
        self::assertSame('same-provider-name.csv', $first->filename);
        self::assertNotSame($first->uid, $second->uid);
        self::assertNotSame($first->filePath, $second->filePath);
        self::assertStringContainsString((string)$first->uid, $first->filePath);
        self::assertStringContainsString((string)$second->uid, $second->filePath);
        self::assertStringEndsWith('-' . $first->uid . '.csv', basename($first->filePath));
        self::assertStringEndsWith('-' . $second->uid . '.csv', basename($second->filePath));
        self::assertStringContainsString('first bytes', (string)$this->exports->getFileContent($first));
        $secondBytes = $this->exports->getFileContent($second);
        self::assertIsString($secondBytes);
        self::assertStringContainsString('second bytes', $secondBytes);

        self::assertTrue($this->exports->deleteExport((int)$first->id));
        self::assertTrue($this->exports->fileExists($second));
        self::assertSame($secondBytes, $this->exports->getFileContent($second));
    }

    public function testIdenticalRecordedVolumeDisplayNamesProduceIndependentObjects(): void
    {
        $volumeRoot = $this->createTrackedTempDirectory('report-object-volume-');
        $volume = $this->localVolume($volumeRoot);
        $volumes = $this->createMock(Volumes::class);
        $volumes->method('getVolumeByUid')->willReturnCallback(
            static fn(string $uid): ?StubExportVolume => $uid === self::VOLUME_UID ? $volume : null,
        );
        Craft::$app->set('volumes', $volumes);
        $this->settings()->exportVolumeUid = self::VOLUME_UID;

        $first = $this->createProviderExport('same-volume-name.csv', 'volume first');
        $second = $this->createProviderExport('same-volume-name.csv', 'volume second');
        $firstPath = $volumeRoot . '/' . self::VOLUME_SUBPATH . '/' . $first->filePath;
        $secondPath = $volumeRoot . '/' . self::VOLUME_SUBPATH . '/' . $second->filePath;

        self::assertSame(ExportStorage::TYPE_VOLUME, $first->storageType);
        self::assertSame(self::VOLUME_UID, $first->storageVolumeUid);
        self::assertSame($first->filename, $second->filename);
        self::assertNotSame($first->filePath, $second->filePath);
        self::assertStringEndsWith('-' . $first->uid . '.csv', basename($first->filePath));
        self::assertStringEndsWith('-' . $second->uid . '.csv', basename($second->filePath));
        self::assertFileExists($firstPath);
        self::assertFileExists($secondPath);
        $secondBytes = file_get_contents($secondPath);
        self::assertIsString($secondBytes);

        self::assertTrue($this->exports->deleteExport((int)$first->id));
        self::assertFileDoesNotExist($firstPath);
        self::assertFileExists($secondPath);
        self::assertSame($secondBytes, file_get_contents($secondPath));
    }

    public function testStandardSeparateAndCombinedExportsUseTheirRecordIdentity(): void
    {
        $separate = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'json',
            ['fieldHandles' => ['id']],
        );
        $combined = $this->exports->createCombinedExport(
            StubLargeExportDataSource::handle(),
            [StubLargeExportDataSource::PRIMARY_ENTITY_ID, StubLargeExportDataSource::SECONDARY_ENTITY_ID],
            'json',
            ['fieldHandles' => ['id']],
        );

        self::assertStringContainsString((string)$separate->uid, $separate->filePath);
        self::assertStringContainsString((string)$combined->uid, $combined->filePath);
        self::assertSame($separate->filename, basename(str_replace('-' . $separate->uid, '', $separate->filePath)));
        self::assertSame($combined->filename, basename(str_replace('-' . $combined->uid, '', $combined->filePath)));
        self::assertTrue($this->exports->generateExport($separate));
        self::assertTrue($this->exports->generateCombinedExport($combined));
        self::assertTrue($this->exports->fileExists($separate));
        self::assertTrue($this->exports->fileExists($combined));
    }

    public function testLongStandardLocalFilenameUsesBoundedPhysicalObject(): void
    {
        $export = $this->createLongStandardExport();
        $displayFilename = $export->filename;

        self::assertGreaterThan(255, strlen($displayFilename . '-' . $export->uid));
        $this->assertBoundedPhysicalObject($export, $displayFilename);
        self::assertTrue($this->exports->generateExport($export));
        self::assertTrue($this->exports->fileExists($export));

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        $this->assertBoundedPhysicalObject($fresh, $displayFilename);
    }

    public function testLongStandardRecordedVolumeFilenameUsesBoundedPhysicalObject(): void
    {
        $volumeRoot = $this->createTrackedTempDirectory('report-object-long-volume-');
        $volume = $this->localVolume($volumeRoot);
        $volumes = $this->createMock(Volumes::class);
        $volumes->method('getVolumeByUid')->willReturnCallback(
            static fn(string $uid): ?StubExportVolume => $uid === self::VOLUME_UID ? $volume : null,
        );
        Craft::$app->set('volumes', $volumes);
        $this->settings()->exportVolumeUid = self::VOLUME_UID;

        $export = $this->createLongStandardExport();
        $displayFilename = $export->filename;

        self::assertSame(ExportStorage::TYPE_VOLUME, $export->storageType);
        self::assertSame(self::VOLUME_UID, $export->storageVolumeUid);
        self::assertGreaterThan(255, strlen($displayFilename . '-' . $export->uid));
        $this->assertBoundedPhysicalObject($export, $displayFilename);
        self::assertTrue($this->exports->generateExport($export));
        self::assertFileExists($volumeRoot . '/' . self::VOLUME_SUBPATH . '/' . $export->filePath);

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        $this->assertBoundedPhysicalObject($fresh, $displayFilename);
    }

    public function testFailedLegacyPathIsNormalizedBeforeRetry(): void
    {
        $export = $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            'csv',
            options: ['filename' => 'legacy-pending.csv'],
        );
        $legacyPath = dirname($export->filePath) . '/legacy-pending.csv';
        $export->filePath = $legacyPath;
        $export->status = ExportRecord::STATUS_FAILED;
        self::assertTrue($export->save(false));
        StubQueuedExportProvider::$nextResult = QueuedExportResult::table(['Column'], [['retry']]);

        self::assertTrue($this->exports->generateQueuedExport($export));
        self::assertNotSame($legacyPath, $export->filePath);
        self::assertStringContainsString((string)$export->uid, $export->filePath);
        self::assertFileDoesNotExist($legacyPath);
        self::assertSame('legacy-pending.csv', $export->filename);
    }

    public function testCompletedHistoricalPathRemainsAuthoritative(): void
    {
        $export = $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            'csv',
            options: ['filename' => 'historical-download.csv'],
        );
        $historicalPath = dirname($export->filePath) . '/historical-object.csv';
        $export->filePath = $historicalPath;
        $export->status = ExportRecord::STATUS_COMPLETED;
        self::assertTrue($export->save(false));
        StubQueuedExportProvider::$nextResult = QueuedExportResult::table(['Column'], [['historical']]);

        self::assertTrue($this->exports->generateQueuedExport($export));
        self::assertSame($historicalPath, $export->filePath);
        self::assertSame('historical-download.csv', $export->filename);
        self::assertFileExists($historicalPath);
    }

    private function createProviderExport(string $filename, string $value): ExportRecord
    {
        StubQueuedExportProvider::$nextResult = QueuedExportResult::table(['Column'], [[$value]]);
        $export = $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            'csv',
            options: ['filename' => $filename],
        );
        self::assertTrue($this->exports->generateQueuedExport($export));
        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function createLongStandardExport(): ExportRecord
    {
        StubLargeExportDataSource::$primaryEntityHandle = str_repeat('entity', 20);

        return $this->exports->createExport(
            self::LONG_SOURCE_HANDLE,
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'json',
            [
                'dateRange' => 'custom',
                'dateStart' => '2026-01-01',
                'dateEnd' => '2026-12-31',
                'fieldHandles' => ['id'],
            ],
        );
    }

    private function assertBoundedPhysicalObject(ExportRecord $export, string $displayFilename): void
    {
        $basename = basename($export->filePath);

        self::assertSame($displayFilename, $export->filename);
        self::assertLessThanOrEqual(255, strlen($basename));
        self::assertStringEndsWith('-' . $export->uid . '.json', $basename);
        self::assertSame(36, strlen((string)$export->uid));
    }

    private function installLargeSource(): void
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
                $event->register(
                    self::LONG_SOURCE_HANDLE,
                    StubLargeExportDataSource::displayName(),
                    StubLargeExportDataSource::class,
                );
            },
        );
        $this->swapPluginComponent('report-manager', 'dataSources', $service);
    }

    private function localVolume(string $root): StubExportVolume
    {
        $filesystem = new Local([
            'handle' => self::VOLUME_UID . '_fs',
            'name' => 'Export object identity filesystem',
            'path' => $root,
        ]);

        return new StubExportVolume($filesystem, [
            'uid' => self::VOLUME_UID,
            'handle' => self::VOLUME_UID,
            'name' => self::VOLUME_UID,
            'subpath' => self::VOLUME_SUBPATH,
        ]);
    }
}

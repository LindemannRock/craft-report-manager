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
use lindemannrock\reportmanager\tests\Stubs\StubExportVolume;
use lindemannrock\reportmanager\tests\Stubs\StubLargeExportDataSource;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Craft volume subpath coverage for every canonical export writer.
 *
 * @since 5.6.0
 */
final class ExportVolumeSubpathTest extends TestCase
{
    private const VOLUME_UID = '__rm_test_volume_uid';
    private const VOLUME_SUBPATH = '__rm_test_volume_root';

    private Volumes $originalVolumes;
    private string $filesystemRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalVolumes = Craft::$app->getVolumes();
        $this->filesystemRoot = $this->createTrackedTempDirectory('report-volume-subpath-');
        $this->installLocalVolume();
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $this->settings()->exportPath = $this->createTrackedTempDirectory('report-volume-decoy-');
        $this->settings()->csvIncludeBom = false;
    }

    protected function tearDown(): void
    {
        Craft::$app->set('volumes', $this->originalVolumes);
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function standardFormatProvider(): iterable
    {
        yield 'CSV stream' => ['csv'];
        yield 'JSON stream' => ['json'];
        yield 'XLSX stream' => ['xlsx'];
    }

    #[DataProvider('standardFormatProvider')]
    public function testStandardFormatsUseTheVolumeWrapperSubpathExactlyOnce(string $format): void
    {
        $this->installLargeExportDataSource();
        $export = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            $format,
            ['fieldHandles' => ['id']],
        );

        self::assertTrue($this->exports->generateExport($export));
        $this->assertWrapperLifecycle($export);
    }

    public function testCombinedExportUsesTheSameWrapperAuthority(): void
    {
        $this->installLargeExportDataSource();
        $export = $this->exports->createCombinedExport(
            StubLargeExportDataSource::handle(),
            [StubLargeExportDataSource::PRIMARY_ENTITY_ID, StubLargeExportDataSource::SECONDARY_ENTITY_ID],
            'csv',
            ['fieldHandles' => ['id']],
        );

        self::assertTrue($this->exports->generateCombinedExport($export));
        $this->assertWrapperLifecycle($export);
    }

    /** @return iterable<string, array{string}> */
    public static function providerFormatProvider(): iterable
    {
        yield 'CSV provider table' => ['csv'];
        yield 'JSON provider table' => ['json'];
        yield 'XLSX provider table' => ['xlsx'];
        yield 'ZIP provider manifest' => ['zip'];
    }

    #[DataProvider('providerFormatProvider')]
    public function testProviderFormatsUseTheSameWrapperAuthority(string $format): void
    {
        $this->installStubProviderService();
        StubQueuedExportProvider::$nextResult = $format === 'zip'
            ? QueuedExportResult::files([
                ['filename' => '__rm_test_manifest.txt', 'contents' => 'provider file'],
            ])
            : QueuedExportResult::table(['Column'], [['provider row']]);

        $export = $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            $format,
            options: ['filename' => "__rm_test_provider_storage.{$format}"],
        );

        self::assertTrue($this->exports->generateQueuedExport($export));
        $this->assertWrapperLifecycle($export);
    }

    public function testManualScheduledCombinedAndProviderRecordsShareTheWrapperRelativePath(): void
    {
        $this->installLargeExportDataSource();
        $this->installStubProviderService();

        $manual = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'csv',
        );
        $scheduled = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'json',
            ['triggeredBy' => ExportRecord::TRIGGER_SCHEDULED, 'triggeredByUserId' => null],
        );
        $combined = $this->exports->createCombinedExport(
            StubLargeExportDataSource::handle(),
            [StubLargeExportDataSource::PRIMARY_ENTITY_ID, StubLargeExportDataSource::SECONDARY_ENTITY_ID],
            'xlsx',
        );
        $provider = $this->exports->createQueuedExport(StubQueuedExportProvider::handle(), 'csv');

        foreach ([$manual, $scheduled, $combined, $provider] as $export) {
            self::assertSame('volume', $export->storageType);
            self::assertSame(self::VOLUME_UID, $export->storageVolumeUid);
            self::assertStringStartsWith('report-manager/exports/', $export->filePath);
            self::assertStringNotContainsString(self::VOLUME_SUBPATH, $export->filePath);
        }
        self::assertSame(ExportRecord::TRIGGER_SCHEDULED, $scheduled->triggeredBy);
    }

    private function assertWrapperLifecycle(ExportRecord $export): void
    {
        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(ExportRecord::STATUS_COMPLETED, $fresh->status);
        self::assertSame('volume', $fresh->storageType);
        self::assertSame(self::VOLUME_UID, $fresh->storageVolumeUid);
        self::assertStringStartsWith('report-manager/exports/', $fresh->filePath);
        self::assertStringNotContainsString(self::VOLUME_SUBPATH, $fresh->filePath);

        $physicalPath = $this->filesystemRoot . '/' . self::VOLUME_SUBPATH . '/' . $fresh->filePath;
        $doublePrefixedPath = $this->filesystemRoot . '/' . self::VOLUME_SUBPATH . '/' . self::VOLUME_SUBPATH . '/' . $fresh->filePath;
        self::assertFileExists($physicalPath);
        self::assertFileDoesNotExist($doublePrefixedPath);
        self::assertTrue($this->exports->fileExists($fresh));
        self::assertNotSame('', $this->exports->getFileContent($fresh));

        self::assertTrue($this->exports->deleteExport((int)$fresh->id));
        self::assertFileDoesNotExist($physicalPath);
        self::assertNull(ExportRecord::findOne($fresh->id));
    }

    private function installLocalVolume(): void
    {
        $filesystem = new Local([
            'handle' => '__rm_test_volume_fs',
            'name' => 'Report Manager Test Filesystem',
            'path' => $this->filesystemRoot,
        ]);
        $volume = new StubExportVolume($filesystem, [
            'uid' => self::VOLUME_UID,
            'handle' => '__rm_test_volume',
            'name' => 'Report Manager Test Volume',
            'subpath' => self::VOLUME_SUBPATH,
        ]);
        $volumes = $this->createMock(Volumes::class);
        $volumes->method('getVolumeByUid')->willReturn($volume);
        Craft::$app->set('volumes', $volumes);
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

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
use craft\base\LocalFsInterface;
use craft\base\MissingComponentInterface;
use craft\fs\Local;
use craft\fs\MissingFs;
use craft\models\Volume;
use craft\services\Config;
use craft\services\Volumes;
use Error;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\exceptions\ExportStorageUnavailableException;
use lindemannrock\reportmanager\export\QueuedExportResult;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\storage\ExportStorage;
use lindemannrock\reportmanager\tests\Stubs\StubExportVolume;
use lindemannrock\reportmanager\tests\Stubs\StubLargeExportDataSource;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

/**
 * Effective export-storage authority and fail-closed recovery coverage.
 *
 * @since 5.6.0
 */
final class ExportStorageResolutionTest extends TestCase
{
    private const VOLUME_UID = '__rm_test_resolution_volume';
    private const ERROR = 'The configured export volume is unavailable. Check its volume and filesystem configuration, then try again.';

    private Volumes $originalVolumes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalVolumes = Craft::$app->getVolumes();
    }

    protected function tearDown(): void
    {
        Craft::$app->set('volumes', $this->originalVolumes);
        parent::tearDown();
    }

    public function testExplicitLocalPathAndConfigPrecedenceRemainAuthoritative(): void
    {
        $storedLocal = $this->createTrackedTempDirectory('report-local-stored-');
        $configuredLocal = $this->createTrackedTempDirectory('report-local-configured-');
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $this->settings()->exportPath = $storedLocal;

        $config = $this->createMock(Config::class);
        $config->method('getConfigFromFile')->willReturn([
            'exportVolumeUid' => '',
            'exportPath' => $configuredLocal,
        ]);
        Craft::$app->set('config', $config);
        PluginHelper::applyConfigOverridesToSettings($this->settings(), 'report-manager');

        $volumes = $this->createMock(Volumes::class);
        $volumes->expects(self::never())->method('getVolumeByUid');
        Craft::$app->set('volumes', $volumes);

        self::assertFalse($this->exports->isUsingVolume());
        self::assertSame(rtrim($configuredLocal, '/') . '/', $this->exports->getExportBasePath());
    }

    public function testConfigVolumeOverrideCannotBeRedirectedByStoredLocalPath(): void
    {
        $decoy = $this->createTrackedTempDirectory('report-volume-config-decoy-');
        $volume = $this->remoteVolume($this->filesystemThatReportsExists());
        $this->installVolumes($volume);
        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $decoy;

        $config = $this->createMock(Config::class);
        $config->method('getConfigFromFile')->willReturn(['exportVolumeUid' => self::VOLUME_UID]);
        Craft::$app->set('config', $config);
        PluginHelper::applyConfigOverridesToSettings($this->settings(), 'report-manager');

        $export = $this->completedExport('report-manager/exports/config.csv');
        self::assertTrue($this->exports->isUsingVolume());
        self::assertTrue($this->exports->fileExists($export));
        self::assertSame(self::VOLUME_UID, $this->settings()->exportVolumeUid);
        self::assertSame([], array_values(array_diff(scandir($decoy) ?: [], ['.', '..'])));
    }

    public function testLocalLikeAndRemoteLikeVolumesBothUseCraftVolumeWrappers(): void
    {
        /** @var FsInterface&LocalFsInterface&MockObject $local */
        $local = $this->createMockForIntersectionOfInterfaces([FsInterface::class, LocalFsInterface::class]);
        $local->method('getRootPath')->willReturn($this->createTrackedTempDirectory('report-local-like-'));
        $local->expects(self::once())
            ->method('fileExists')
            ->with('__rm_test_root/report-manager/exports/local.csv')
            ->willReturn(true);
        $this->installVolumes($this->remoteVolume($local));
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        self::assertTrue($this->exports->fileExists($this->completedExport('report-manager/exports/local.csv')));

        $remote = $this->filesystemThatReportsExists('__rm_test_root/report-manager/exports/remote.csv');
        $this->installVolumes($this->remoteVolume($remote));
        self::assertTrue($this->exports->fileExists($this->completedExport('report-manager/exports/remote.csv')));
    }

    public function testMissingInvalidMissingFilesystemAndMissingComponentFailClosed(): void
    {
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $export = $this->completedExport('report-manager/exports/unavailable.csv');

        $this->installVolumes(null);
        $this->assertUnavailable(fn() => $this->exports->fileExists($export));

        /** @var FsInterface&LocalFsInterface&MockObject $invalidFs */
        $invalidFs = $this->createMockForIntersectionOfInterfaces([FsInterface::class, LocalFsInterface::class]);
        $invalidFs->method('getRootPath')->willReturn((string)Craft::getAlias('@webroot') . '/invalid-volume');
        $this->installVolumes($this->remoteVolume($invalidFs));
        $this->assertUnavailable(fn() => $this->exports->fileExists($export));

        $this->installVolumes($this->remoteVolume(new MissingFs(['handle' => '__rm_test_missing_fs'])));
        $this->assertUnavailable(fn() => $this->exports->fileExists($export));

        /** @var FsInterface&MissingComponentInterface&MockObject $missingComponent */
        $missingComponent = $this->createMockForIntersectionOfInterfaces([
            FsInterface::class,
            MissingComponentInterface::class,
        ]);
        $this->installVolumes($this->remoteVolume($missingComponent));
        $this->assertUnavailable(fn() => $this->exports->fileExists($export));

        self::assertSame(self::VOLUME_UID, $this->settings()->exportVolumeUid);
    }

    public function testListingAvailabilityReturnsFalseAndRetainsTheActionableStorageError(): void
    {
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $export = $this->completedExport('report-manager/exports/listing.csv');
        $export->id = 990001;
        $this->installVolumes(null);

        self::assertSame([990001 => false], $this->exports->getFileAvailabilityMap([$export]));
        self::assertSame(self::ERROR, $this->exports->getStorageError());
    }

    public function testExceptionErrorAndOtherThrowableResolutionStatesFailClosed(): void
    {
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $export = $this->completedExport('report-manager/exports/throwing.csv');

        foreach ([
            new RuntimeException('resolution exception'),
            new Error('resolution error'),
            new \TypeError('resolution throwable'),
        ] as $throwable) {
            $volume = $this->createMock(Volume::class);
            $volume->method('getFs')->willReturnCallback(static fn(): never => throw $throwable);
            $this->installVolumes($volume);
            $this->assertUnavailable(fn() => $this->exports->fileExists($export));
        }
    }

    public function testUnavailableVolumeMarksProviderExportFailedWithoutLocalFallbackThenRecovers(): void
    {
        $this->installStubProviderService();
        StubQueuedExportProvider::$nextResult = QueuedExportResult::table(['Column'], [['value']]);
        $decoy = $this->createTrackedTempDirectory('report-recovery-decoy-');
        $root = $this->createTrackedTempDirectory('report-recovery-volume-');
        $filesystem = new Local([
            'handle' => '__rm_test_recovery_fs',
            'name' => 'Recovery Filesystem',
            'path' => $root,
        ]);
        $volume = $this->remoteVolume($filesystem);
        $this->installVolumes(null, $volume, $volume, $volume, $volume);
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $this->settings()->exportPath = $decoy;

        $export = $this->exports->createQueuedExport(StubQueuedExportProvider::handle(), 'csv');
        self::assertFalse($this->exports->generateQueuedExport($export));
        $failed = ExportRecord::findOne($export->id);
        self::assertNotNull($failed);
        self::assertSame(ExportRecord::STATUS_FAILED, $failed->status);
        self::assertSame(self::ERROR, $failed->errorMessage);
        self::assertSame(self::VOLUME_UID, $this->settings()->exportVolumeUid);
        self::assertSame([], array_values(array_diff(scandir($decoy) ?: [], ['.', '..'])));

        self::assertTrue($this->exports->generateQueuedExport($failed));
        $recovered = ExportRecord::findOne($export->id);
        self::assertNotNull($recovered);
        self::assertSame(ExportRecord::STATUS_COMPLETED, $recovered->status);
        self::assertFileExists($root . '/__rm_test_root/' . $recovered->filePath);
    }

    public function testReadOnlyAndThrowingWritesFailWithoutLocalFallback(): void
    {
        $this->installStubProviderService();
        StubQueuedExportProvider::$nextResult = QueuedExportResult::table(['Column'], [['value']]);
        $decoy = $this->createTrackedTempDirectory('report-readonly-decoy-');
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->expects(self::once())
            ->method('write')
            ->with(self::stringStartsWith('__rm_test_root/report-manager/exports/'))
            ->willThrowException(new RuntimeException('read only'));
        $this->installVolumes($this->remoteVolume($filesystem));
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $this->settings()->exportPath = $decoy;

        $export = $this->exports->createQueuedExport(StubQueuedExportProvider::handle(), 'csv');
        self::assertFalse($this->exports->generateQueuedExport($export));
        $failed = ExportRecord::findOne($export->id);
        self::assertNotNull($failed);
        self::assertSame(ExportRecord::STATUS_FAILED, $failed->status);
        self::assertSame(self::ERROR, $failed->errorMessage);
        self::assertSame([], array_values(array_diff(scandir($decoy) ?: [], ['.', '..'])));
    }

    public function testThrowingStreamWriteFailsStandardExportAndLeavesNoLocalFallback(): void
    {
        $this->installLargeExportDataSource();
        $decoy = $this->createTrackedTempDirectory('report-stream-decoy-');
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->expects(self::once())
            ->method('writeFileFromStream')
            ->with(self::stringStartsWith('__rm_test_root/report-manager/exports/'))
            ->willThrowException(new RuntimeException('stream write failed'));
        $this->installVolumes($this->remoteVolume($filesystem));
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $this->settings()->exportPath = $decoy;

        $export = $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'csv',
        );
        self::assertFalse($this->exports->generateExport($export));
        $failed = ExportRecord::findOne($export->id);
        self::assertNotNull($failed);
        self::assertSame(ExportRecord::STATUS_FAILED, $failed->status);
        self::assertSame(self::ERROR, $failed->errorMessage);
        self::assertSame([], array_values(array_diff(scandir($decoy) ?: [], ['.', '..'])));
    }

    public function testThrowingReadAndAvailabilityOperationsNeverFallBackLocally(): void
    {
        $decoy = $this->createTrackedTempDirectory('report-read-decoy-');
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->method('fileExists')->willThrowException(new RuntimeException('provider unavailable'));
        $this->installVolumes($this->remoteVolume($filesystem));
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $this->settings()->exportPath = $decoy;
        $export = $this->completedExport('report-manager/exports/read.csv');

        $this->assertUnavailable(fn() => $this->exports->fileExists($export));
        $this->assertUnavailable(fn() => $this->exports->getFileContent($export));
        self::assertSame([], array_values(array_diff(scandir($decoy) ?: [], ['.', '..'])));
    }

    public function testThrowingDeleteUsesTheWrapperWithoutLocalFallbackAndLeavesPr14PolicyUntouched(): void
    {
        $this->installStubProviderService();
        $decoy = $this->createTrackedTempDirectory('report-delete-decoy-');
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->expects(self::once())
            ->method('fileExists')
            ->with(self::stringStartsWith('__rm_test_root/report-manager/exports/'))
            ->willReturn(true);
        $filesystem->expects(self::once())
            ->method('deleteFile')
            ->with(self::stringStartsWith('__rm_test_root/report-manager/exports/'))
            ->willThrowException(new RuntimeException('delete failed'));
        $this->installVolumes($this->remoteVolume($filesystem));
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $this->settings()->exportPath = $decoy;

        $export = $this->exports->createQueuedExport(StubQueuedExportProvider::handle(), 'csv');
        $export->status = ExportRecord::STATUS_COMPLETED;
        self::assertTrue($export->save());

        self::assertTrue($this->exports->deleteExport((int)$export->id));
        self::assertNull(ExportRecord::findOne($export->id));
        self::assertSame(self::ERROR, $this->exports->getStorageError());
        self::assertSame([], array_values(array_diff(scandir($decoy) ?: [], ['.', '..'])));
    }

    private function completedExport(string $path): ExportRecord
    {
        $export = new ExportRecord();
        $export->filePath = $path;
        $export->filename = basename($path);
        $export->status = ExportRecord::STATUS_COMPLETED;
        $export->format = 'csv';

        return $export;
    }

    private function remoteVolume(FsInterface $filesystem): StubExportVolume
    {
        return new StubExportVolume($filesystem, [
            'uid' => self::VOLUME_UID,
            'handle' => '__rm_test_resolution',
            'name' => 'Resolution Test Volume',
            'subpath' => '__rm_test_root',
        ]);
    }

    private function filesystemThatReportsExists(?string $expectedPath = null): FsInterface & MockObject
    {
        $filesystem = $this->createMock(FsInterface::class);
        $expectation = $filesystem->expects(self::once())->method('fileExists');
        if ($expectedPath !== null) {
            $expectation->with($expectedPath);
        }
        $expectation->willReturn(true);

        return $filesystem;
    }

    private function installVolumes(?Volume ...$sequence): void
    {
        $volumes = $this->createMock(Volumes::class);
        $index = 0;
        $volumes->method('getVolumeByUid')->willReturnCallback(
            static function(string $uid) use (&$index, $sequence): ?Volume {
                $position = min($index, count($sequence) - 1);
                $index++;

                return $sequence[$position];
            },
        );
        Craft::$app->set('volumes', $volumes);
    }

    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected export storage to fail closed.');
        } catch (ExportStorageUnavailableException $exception) {
            self::assertSame(self::ERROR, $exception->getMessage());
            self::assertSame(ExportStorage::unavailableMessage(), $exception->getMessage());
        }
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

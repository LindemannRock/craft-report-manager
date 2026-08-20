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
use craft\fs\Local;
use craft\helpers\Db;
use craft\services\Volumes;
use craft\web\Request;
use DateTime;
use Error;
use lindemannrock\reportmanager\controllers\ExportsController;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\storage\ExportStorage;
use lindemannrock\reportmanager\tests\Stubs\StubExportVolume;
use lindemannrock\reportmanager\tests\TestCase;
use RuntimeException;
use TypeError;
use yii\web\Response;

/**
 * Export file-and-record deletion ownership coverage.
 *
 * @since 5.6.0
 */
final class ExportDeletionOwnershipTest extends TestCase
{
    private const VOLUME_UID = '__rm_test_deletion_volume';
    private const VOLUME_SUBPATH = '__rm_test_deletion_root';

    private Volumes $originalVolumes;
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalVolumes = Craft::$app->getVolumes();
    }

    protected function tearDown(): void
    {
        try {
            Craft::$app->set('volumes', $this->originalVolumes);
            $this->restoreRequestResponse();
        } finally {
            parent::tearDown();
        }
    }

    public function testRecordRemainsWhenRemoteDeleteThrows(): void
    {
        $path = 'report-manager/exports/remote-throw.csv';
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->expects(self::once())
            ->method('fileExists')
            ->with($this->wrappedPath($path))
            ->willReturn(true);
        $filesystem->expects(self::once())
            ->method('deleteFile')
            ->with($this->wrappedPath($path))
            ->willThrowException(new RuntimeException('read-only provider'));
        $this->installVolume($filesystem);
        $export = $this->completedExport($path, ExportStorage::TYPE_VOLUME, self::VOLUME_UID);

        $data = $this->post(
            ['id' => $export->id],
            static fn(): Response => (new ExportsController('exports', ReportManager::$plugin))->actionDelete(),
        );

        self::assertFalse($data['success']);
        self::assertSame(ExportStorage::deletionFailedMessage(), $data['error']);
        $this->assertRetryableIdentity($export);
    }

    public function testRecordRemainsWhenLocalUnlinkFails(): void
    {
        $directory = $this->createTrackedTempDirectory('report-delete-unlink-failure-');
        $export = $this->completedExport($directory, ExportStorage::TYPE_LOCAL);

        self::assertFalse($this->exports->deleteExport((int)$export->id));
        self::assertDirectoryExists($directory);
        self::assertSame(ExportStorage::deletionFailedMessage(), $this->exports->getLastStorageError());
        $this->assertRetryableIdentity($export);
    }

    public function testRecordRemainsWhenVolumeExistenceCheckThrows(): void
    {
        $path = 'report-manager/exports/existence-throws.csv';
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->expects(self::once())
            ->method('fileExists')
            ->with($this->wrappedPath($path))
            ->willThrowException(new RuntimeException('provider unavailable'));
        $filesystem->expects(self::never())->method('deleteFile');
        $this->installVolume($filesystem);
        $export = $this->completedExport($path, ExportStorage::TYPE_VOLUME, self::VOLUME_UID);

        self::assertFalse($this->exports->deleteExport((int)$export->id));
        self::assertSame(ExportStorage::deletionFailedMessage(), $this->exports->getLastStorageError());
        $this->assertRetryableIdentity($export);
    }

    public function testRecordRemainsWhenRecordedVolumeIsUnavailable(): void
    {
        $this->installVolumeMap([]);
        $export = $this->completedExport(
            'report-manager/exports/unavailable.csv',
            ExportStorage::TYPE_VOLUME,
            self::VOLUME_UID,
        );

        self::assertFalse($this->exports->deleteExport((int)$export->id));
        self::assertSame(ExportStorage::unavailableMessage(), $this->exports->getLastStorageError());
        $this->assertRetryableIdentity($export);
    }

    public function testAlreadyAbsentLocalFilePermitsRecordRemoval(): void
    {
        $root = $this->createTrackedTempDirectory('report-delete-absent-local-');
        $export = $this->completedExport($root . '/absent.csv', ExportStorage::TYPE_LOCAL);

        self::assertTrue($this->exports->deleteExport((int)$export->id));
        self::assertNull(ExportRecord::findOne($export->id));
        self::assertNull($this->exports->getLastStorageError());
    }

    public function testAlreadyAbsentVolumeFilePermitsRecordRemoval(): void
    {
        $path = 'report-manager/exports/absent-volume.csv';
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->expects(self::once())
            ->method('fileExists')
            ->with($this->wrappedPath($path))
            ->willReturn(false);
        $filesystem->expects(self::never())->method('deleteFile');
        $this->installVolume($filesystem);
        $export = $this->completedExport($path, ExportStorage::TYPE_VOLUME, self::VOLUME_UID);

        self::assertTrue($this->exports->deleteExport((int)$export->id));
        self::assertNull(ExportRecord::findOne($export->id));
        self::assertNull($this->exports->getLastStorageError());
    }

    public function testSuccessfulLocalDeletionRemovesFileAndRecord(): void
    {
        $root = $this->createTrackedTempDirectory('report-delete-success-local-');
        $path = $root . '/owned.csv';
        file_put_contents($path, 'owned local export');
        $export = $this->completedExport($path, ExportStorage::TYPE_LOCAL);

        self::assertTrue($this->exports->deleteExport((int)$export->id));
        self::assertFileDoesNotExist($path);
        self::assertNull(ExportRecord::findOne($export->id));
    }

    public function testSuccessfulVolumeDeletionUsesWrapperSubpathAndRemovesRecord(): void
    {
        $root = $this->createTrackedTempDirectory('report-delete-success-volume-');
        $filesystem = new Local([
            'handle' => '__rm_test_deletion_local_fs',
            'name' => 'Deletion Local Filesystem',
            'path' => $root,
        ]);
        $volume = $this->volume($filesystem);
        $this->installVolumeMap([self::VOLUME_UID => $volume]);
        $path = 'report-manager/exports/owned-volume.csv';
        $volume->write($path, 'owned volume export');
        $physicalPath = $root . '/' . self::VOLUME_SUBPATH . '/' . $path;
        $export = $this->completedExport($path, ExportStorage::TYPE_VOLUME, self::VOLUME_UID);

        self::assertFileExists($physicalPath);
        self::assertTrue($this->exports->deleteExport((int)$export->id));
        self::assertFileDoesNotExist($physicalPath);
        self::assertNull(ExportRecord::findOne($export->id));
    }

    public function testErrorAndOtherThrowablePreserveRetryableOwnership(): void
    {
        foreach ([new Error('provider error'), new TypeError('provider type error')] as $index => $throwable) {
            $path = "report-manager/exports/throwable-{$index}.csv";
            $filesystem = $this->createMock(FsInterface::class);
            $filesystem->expects(self::once())
                ->method('fileExists')
                ->with($this->wrappedPath($path))
                ->willReturnCallback(static fn(): never => throw $throwable);
            $filesystem->expects(self::never())->method('deleteFile');
            $this->installVolume($filesystem);
            $export = $this->completedExport($path, ExportStorage::TYPE_VOLUME, self::VOLUME_UID);

            self::assertFalse($this->exports->deleteExport((int)$export->id));
            self::assertSame(ExportStorage::deletionFailedMessage(), $this->exports->getLastStorageError());
            $this->assertRetryableIdentity($export);
        }
    }

    public function testUnresolvedStorageIsNotTreatedAsAlreadyAbsent(): void
    {
        $volumes = $this->createMock(Volumes::class);
        $volumes->expects(self::never())->method('getVolumeByUid');
        Craft::$app->set('volumes', $volumes);
        $export = $this->completedExport('report-manager/exports/unresolved.csv', null);

        self::assertFalse($this->exports->deleteExport((int)$export->id));
        self::assertSame(ExportStorage::unresolvedMessage(), $this->exports->getLastStorageError());
        $this->assertRetryableIdentity($export);
    }

    public function testBulkDeletionReportsPartialFailureAndPreservesFailedRows(): void
    {
        $root = $this->createTrackedTempDirectory('report-delete-bulk-');
        $successPath = $root . '/success.csv';
        file_put_contents($successPath, 'bulk success');
        $success = $this->completedExport($successPath, ExportStorage::TYPE_LOCAL);

        $failedPath = 'report-manager/exports/bulk-failure.csv';
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->method('fileExists')->willReturn(true);
        $filesystem->method('deleteFile')->willThrowException(new RuntimeException('remote delete failed'));
        $this->installVolume($filesystem);
        $failed = $this->completedExport($failedPath, ExportStorage::TYPE_VOLUME, self::VOLUME_UID);

        $data = $this->post(
            ['exportIds' => [$success->id, $failed->id]],
            static fn(): Response => (new ExportsController('exports', ReportManager::$plugin))->actionBulkDelete(),
        );

        self::assertFalse($data['success']);
        self::assertSame(1, $data['deleted']);
        self::assertSame(1, $data['failed']);
        self::assertSame([(int)$failed->id], $data['failedIds']);
        self::assertSame(ExportStorage::deletionFailedMessage(), $data['error']);
        self::assertFileDoesNotExist($successPath);
        self::assertNull(ExportRecord::findOne($success->id));
        $this->assertRetryableIdentity($failed);
    }

    public function testRetentionCountsOnlyCompleteFileAndRecordDeletion(): void
    {
        $root = $this->createTrackedTempDirectory('report-delete-retention-success-');
        $successPath = $root . '/success.csv';
        file_put_contents($successPath, 'retention success');
        $success = $this->completedExport($successPath, ExportStorage::TYPE_LOCAL);

        $failedPath = $this->createTrackedTempDirectory('report-delete-retention-failure-');
        $failed = $this->completedExport($failedPath, ExportStorage::TYPE_LOCAL);
        $ownedIds = [(int)$success->id, (int)$failed->id];
        sort($ownedIds);

        Craft::$app->getDb()->createCommand()->update(
            ExportRecord::tableName(),
            ['dateCreated' => Db::prepareDateForDb(new DateTime('1970-01-01 00:00:00'))],
            ['id' => $ownedIds],
        )->execute();
        $this->settings()->autoCleanupExports = true;
        $this->settings()->exportRetention = 10_000;

        $cutoff = (new DateTime())->modify('-10000 days');
        $candidateIds = array_map(
            'intval',
            ExportRecord::find()
                ->select(['id'])
                ->where(['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
                ->column(),
        );
        sort($candidateIds);
        self::assertSame($ownedIds, $candidateIds, 'Retention must select only the two test-owned rows.');

        self::assertSame(1, $this->exports->cleanupOldExports());
        self::assertFileDoesNotExist($successPath);
        self::assertNull(ExportRecord::findOne($success->id));
        self::assertDirectoryExists($failedPath);
        $this->assertRetryableIdentity($failed);
    }

    private function completedExport(string $filePath, ?string $storageType, ?string $volumeUid = null): ExportRecord
    {
        $export = new ExportRecord();
        $export->dataSource = self::MARKER . 'deletion_' . bin2hex(random_bytes(5));
        $export->entityId = 0;
        $export->format = 'csv';
        $export->filename = basename($filePath);
        $export->filePath = $filePath;
        $export->storageType = $storageType;
        $export->storageVolumeUid = $volumeUid;
        $export->fileSize = 1;
        $export->recordCount = 1;
        $export->status = ExportRecord::STATUS_COMPLETED;
        $export->progress = 100;
        $export->triggeredBy = ExportRecord::TRIGGER_MANUAL;
        self::assertTrue($export->save(false));

        return $export;
    }

    private function assertRetryableIdentity(ExportRecord $expected): void
    {
        $actual = ExportRecord::findOne($expected->id);
        self::assertNotNull($actual);
        self::assertSame($expected->storageType, $actual->storageType);
        self::assertSame($expected->storageVolumeUid, $actual->storageVolumeUid);
        self::assertSame($expected->filePath, $actual->filePath);
    }

    private function installVolume(FsInterface $filesystem): void
    {
        $this->installVolumeMap([self::VOLUME_UID => $this->volume($filesystem)]);
    }

    /** @param array<string, StubExportVolume> $volumesByUid */
    private function installVolumeMap(array $volumesByUid): void
    {
        $volumes = $this->createMock(Volumes::class);
        $volumes->method('getVolumeByUid')->willReturnCallback(
            static fn(string $uid): ?StubExportVolume => $volumesByUid[$uid] ?? null,
        );
        Craft::$app->set('volumes', $volumes);
    }

    private function volume(FsInterface $filesystem): StubExportVolume
    {
        return new StubExportVolume($filesystem, [
            'uid' => self::VOLUME_UID,
            'handle' => self::VOLUME_UID,
            'name' => 'Deletion Test Volume',
            'subpath' => self::VOLUME_SUBPATH,
        ]);
    }

    private function wrappedPath(string $path): string
    {
        return self::VOLUME_SUBPATH . '/' . $path;
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(): Response $action
     * @return array<string, mixed>
     */
    private function post(array $params, callable $action): array
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
            Craft::$app->set('request', new Request([
                'enableCookieValidation' => false,
                'enableCsrfValidation' => false,
            ]));
        }
        if ($this->originalResponse === null) {
            $this->originalResponse = Craft::$app->getResponse();
        }
        if ($this->originalRequestMethod === null) {
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams($params);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        Craft::$app->set('response', new Response());

        return $action()->data;
    }

    private function restoreRequestResponse(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
            $this->originalResponse = null;
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
            $this->originalRequestMethod = null;
        }
    }
}

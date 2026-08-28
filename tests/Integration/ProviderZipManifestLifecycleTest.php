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
use craft\db\Query;
use craft\fs\Local;
use craft\helpers\FileHelper;
use craft\queue\Queue;
use craft\services\Volumes;
use lindemannrock\reportmanager\export\QueuedExportResult;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\storage\ExportStorage;
use lindemannrock\reportmanager\tests\Stubs\StubExportVolume;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\Stubs\UnreadableProviderFileStreamWrapper;
use lindemannrock\reportmanager\tests\TestCase;
use ZipArchive;

/**
 * Provider ZIP manifest generation, failure observability, and exact ownership.
 *
 * @since 5.6.0
 */
final class ProviderZipManifestLifecycleTest extends TestCase
{
    private const VOLUME_UID = '__rm_test_zip_volume_uid';
    private const VOLUME_SUBPATH = '__rm_test_zip_volume_root';
    private const UNREADABLE_SCHEME = 'rmzipunreadable';

    private Volumes $originalVolumes;
    private bool $unreadableWrapperRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalVolumes = Craft::$app->getVolumes();
    }

    protected function tearDown(): void
    {
        $this->unregisterUnreadableWrapper();
        StubQueuedExportProvider::reset();
        Craft::$app->set('volumes', $this->originalVolumes);
        parent::tearDown();
    }

    public function testInlineAndFilesystemMembersPreserveBytesAndNormalizedNames(): void
    {
        $this->installStubProviderService();
        $storageRoot = $this->configureLocalStorage();
        $stagingRoot = $this->createTrackedTempDirectory('report-provider-zip-stage-');
        $stagedPath = null;
        $queueRows = $this->queueRowCount();
        $zipTempPaths = $this->zipHelperTempPaths();

        StubQueuedExportProvider::$resultFactory = static function() use ($stagingRoot, &$stagedPath): QueuedExportResult {
            $providerDirectory = $stagingRoot . '/provider-created';
            FileHelper::createDirectory($providerDirectory);
            $stagedPath = $providerDirectory . '/path-member.bin';
            FileHelper::writeToFile($stagedPath, "path-bytes\x00\x01");

            return QueuedExportResult::files([
                ['filename' => '../Raw Data/Unsafe "Name".txt', 'contents' => "inline-bytes\n"],
                ['filename' => '..\\Raw Data\\Unsafe Name.txt', 'path' => $stagedPath],
            ], warnings: ['provider manifest warning']);
        };

        $export = $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            'zip',
            ['request' => 'zip-manifest'],
            ['filename' => 'provider-manifest.zip'],
        );
        $finalPath = $export->filePath;

        try {
            self::assertTrue($this->exports->generateQueuedExport($export));
            self::assertCount(1, StubQueuedExportProvider::$generateCalls);
            self::assertTrue(StubQueuedExportProvider::$generateCalls[0][StubQueuedExportProvider::NORMALIZED_MARKER]);
            self::assertSame('zip-manifest', StubQueuedExportProvider::$generateCalls[0]['request']);

            $fresh = $this->requireExport($export);
            self::assertSame(ExportRecord::STATUS_COMPLETED, $fresh->status);
            self::assertSame(ExportStorage::TYPE_LOCAL, $fresh->storageType);
            self::assertNull($fresh->storageVolumeUid);
            self::assertSame('provider-manifest.zip', $fresh->filename);
            self::assertSame(2, $fresh->recordCount);
            self::assertSame(['provider manifest warning'], $fresh->getWarningsArray());
            self::assertNotNull($fresh->completedAt);
            self::assertNull($fresh->errorMessage);
            self::assertSame($storageRoot, dirname($fresh->filePath));
            self::assertSame("provider-manifest-{$fresh->uid}.zip", basename($fresh->filePath));
            self::assertFileExists($fresh->filePath);
            self::assertSame([
                'raw-data/unsafe-name.txt' => "inline-bytes\n",
                'raw-data/unsafe-name-2.txt' => "path-bytes\x00\x01",
            ], $this->readZipMembers($fresh));
            self::assertIsString($stagedPath);
            self::assertFileExists($stagedPath);
        } finally {
            $this->deleteOwnedExport($export, $finalPath);
            $this->removeOwnedDirectory($stagingRoot);
            $this->removeOwnedDirectory($storageRoot);
            self::assertSame($queueRows, $this->queueRowCount());
            self::assertSame($zipTempPaths, $this->zipHelperTempPaths());
        }
    }

    public function testFilesystemManifestUsesRecordedVolumeWrapperAndUniqueObjectPath(): void
    {
        $this->installStubProviderService();
        $filesystemRoot = $this->createTrackedTempDirectory('report-provider-zip-volume-');
        $decoyRoot = $this->createTrackedTempDirectory('report-provider-zip-decoy-');
        $stagingRoot = $this->createTrackedTempDirectory('report-provider-zip-volume-stage-');
        $this->installLocalVolume($filesystemRoot);
        $this->settings()->exportVolumeUid = self::VOLUME_UID;
        $this->settings()->exportPath = $decoyRoot;
        $queueRows = $this->queueRowCount();
        $zipTempPaths = $this->zipHelperTempPaths();

        StubQueuedExportProvider::$resultFactory = static function() use ($stagingRoot): QueuedExportResult {
            $providerDirectory = $stagingRoot . '/provider-created';
            FileHelper::createDirectory($providerDirectory);
            $path = $providerDirectory . '/volume-member.txt';
            FileHelper::writeToFile($path, 'volume path bytes');

            return QueuedExportResult::files([
                ['filename' => 'Nested Folder/Volume Member.txt', 'path' => $path],
            ]);
        };

        $export = $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            'zip',
            options: ['filename' => 'provider-manifest.zip'],
        );
        $physicalPath = $filesystemRoot . '/' . self::VOLUME_SUBPATH . '/' . $export->filePath;
        $doublePrefixedPath = $filesystemRoot . '/' . self::VOLUME_SUBPATH . '/' . self::VOLUME_SUBPATH . '/' . $export->filePath;

        try {
            self::assertSame(ExportStorage::TYPE_VOLUME, $export->storageType);
            self::assertSame(self::VOLUME_UID, $export->storageVolumeUid);
            self::assertStringStartsWith(ExportStorage::EXPORT_SUBPATH . '/', $export->filePath);
            self::assertStringNotContainsString(self::VOLUME_SUBPATH, $export->filePath);
            self::assertSame("provider-manifest-{$export->uid}.zip", basename($export->filePath));
            self::assertTrue($this->exports->generateQueuedExport($export));

            $fresh = $this->requireExport($export);
            self::assertSame('provider-manifest.zip', $fresh->filename);
            self::assertSame(ExportStorage::TYPE_VOLUME, $fresh->storageType);
            self::assertSame(self::VOLUME_UID, $fresh->storageVolumeUid);
            self::assertSame($export->filePath, $fresh->filePath);
            self::assertFileExists($physicalPath);
            self::assertFileDoesNotExist($doublePrefixedPath);
            self::assertSame([], glob($decoyRoot . '/*') ?: []);
            self::assertSame([
                'nested-folder/volume-member.txt' => 'volume path bytes',
            ], $this->readZipMembers($fresh));
        } finally {
            $this->deleteOwnedExport($export, $physicalPath);
            Craft::$app->set('volumes', $this->originalVolumes);
            $this->removeOwnedDirectory($stagingRoot);
            $this->removeOwnedDirectory($decoyRoot);
            $this->removeOwnedDirectory($filesystemRoot);
            self::assertSame($queueRows, $this->queueRowCount());
            self::assertSame($zipTempPaths, $this->zipHelperTempPaths());
        }
    }

    public function testMissingFilesystemMemberFailsWithoutLeavingArchive(): void
    {
        $this->installStubProviderService();
        $storageRoot = $this->configureLocalStorage();
        $stagingRoot = $this->createTrackedTempDirectory('report-provider-zip-missing-');
        $missingPath = $stagingRoot . '/provider-created/missing.txt';
        $queueRows = $this->queueRowCount();
        $zipTempPaths = $this->zipHelperTempPaths();

        StubQueuedExportProvider::$resultFactory = static function() use ($missingPath): QueuedExportResult {
            FileHelper::createDirectory(dirname($missingPath));

            return QueuedExportResult::files([
                ['filename' => 'Missing Member.txt', 'path' => $missingPath],
            ]);
        };

        $export = $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            'zip',
            options: ['filename' => 'missing-provider-manifest.zip'],
        );
        $finalPath = $export->filePath;

        try {
            self::assertFalse($this->exports->generateQueuedExport($export));
            $fresh = $this->requireExport($export);
            $this->assertFailedExport(
                $fresh,
                "Queued export file 'Missing Member.txt' is missing readable contents or path",
            );
            self::assertFileDoesNotExist($missingPath);
            self::assertDirectoryExists(dirname($missingPath));
            self::assertFileDoesNotExist($finalPath);
            self::assertFalse($this->exports->fileExists($fresh));
        } finally {
            $this->deleteOwnedExport($export, $finalPath);
            $this->removeOwnedDirectory($stagingRoot);
            $this->removeOwnedDirectory($storageRoot);
            self::assertSame($queueRows, $this->queueRowCount());
            self::assertSame($zipTempPaths, $this->zipHelperTempPaths());
        }
    }

    public function testFailedFilesystemReadFailsWithoutLeavingArchive(): void
    {
        $this->installStubProviderService();
        $storageRoot = $this->configureLocalStorage();
        $stagingRoot = $this->createTrackedTempDirectory('report-provider-zip-unreadable-');
        $queueRows = $this->queueRowCount();
        $zipTempPaths = $this->zipHelperTempPaths();
        $this->registerUnreadableWrapper();
        $unreadablePath = self::UNREADABLE_SCHEME . '://provider-created/unreadable.txt';

        StubQueuedExportProvider::$resultFactory = static function() use ($stagingRoot, $unreadablePath): QueuedExportResult {
            $providerDirectory = $stagingRoot . '/provider-created';
            FileHelper::createDirectory($providerDirectory);
            FileHelper::writeToFile($providerDirectory . '/ownership-marker.txt', 'provider staging');

            return QueuedExportResult::files([
                ['filename' => 'Unreadable Member.txt', 'path' => $unreadablePath],
            ]);
        };

        $export = $this->exports->createQueuedExport(
            StubQueuedExportProvider::handle(),
            'zip',
            options: ['filename' => 'unreadable-provider-manifest.zip'],
        );
        $finalPath = $export->filePath;
        set_error_handler(
            static fn(int $severity, string $message): bool => str_contains($message, self::UNREADABLE_SCHEME . '://'),
        );

        try {
            self::assertTrue(is_file($unreadablePath));
            self::assertFalse($this->exports->generateQueuedExport($export));
            $fresh = $this->requireExport($export);
            $this->assertFailedExport(
                $fresh,
                "Queued export file 'Unreadable Member.txt' could not be read",
            );
            self::assertGreaterThanOrEqual(2, UnreadableProviderFileStreamWrapper::$statCalls);
            self::assertSame(1, UnreadableProviderFileStreamWrapper::$openCalls);
            self::assertFileExists($stagingRoot . '/provider-created/ownership-marker.txt');
            self::assertFileDoesNotExist($finalPath);
            self::assertFalse($this->exports->fileExists($fresh));
        } finally {
            restore_error_handler();
            $this->unregisterUnreadableWrapper();
            $this->deleteOwnedExport($export, $finalPath);
            $this->removeOwnedDirectory($stagingRoot);
            $this->removeOwnedDirectory($storageRoot);
            self::assertSame($queueRows, $this->queueRowCount());
            self::assertSame($zipTempPaths, $this->zipHelperTempPaths());
        }
    }

    private function configureLocalStorage(): string
    {
        $storageRoot = $this->createTrackedTempDirectory('report-provider-zip-local-');
        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $storageRoot;

        return $storageRoot;
    }

    private function installLocalVolume(string $filesystemRoot): void
    {
        $filesystem = new Local([
            'handle' => '__rm_test_zip_volume_fs',
            'name' => 'Report Manager ZIP Test Filesystem',
            'path' => $filesystemRoot,
        ]);
        $volume = new StubExportVolume($filesystem, [
            'uid' => self::VOLUME_UID,
            'handle' => '__rm_test_zip_volume',
            'name' => 'Report Manager ZIP Test Volume',
            'subpath' => self::VOLUME_SUBPATH,
        ]);
        $volumes = $this->createMock(Volumes::class);
        $volumes->method('getVolumeByUid')->willReturn($volume);
        Craft::$app->set('volumes', $volumes);
    }

    private function registerUnreadableWrapper(): void
    {
        $this->unregisterUnreadableWrapper();
        UnreadableProviderFileStreamWrapper::reset();
        self::assertTrue(stream_wrapper_register(
            self::UNREADABLE_SCHEME,
            UnreadableProviderFileStreamWrapper::class,
        ));
        $this->unreadableWrapperRegistered = true;
    }

    private function unregisterUnreadableWrapper(): void
    {
        if (!$this->unreadableWrapperRegistered) {
            return;
        }

        self::assertTrue(stream_wrapper_unregister(self::UNREADABLE_SCHEME));
        $this->unreadableWrapperRegistered = false;
    }

    private function requireExport(ExportRecord $export): ExportRecord
    {
        $fresh = ExportRecord::findOne($export->id);
        self::assertInstanceOf(ExportRecord::class, $fresh);

        return $fresh;
    }

    private function assertFailedExport(ExportRecord $export, string $message): void
    {
        self::assertSame(ExportRecord::STATUS_FAILED, $export->status);
        self::assertSame($message, $export->errorMessage);
        self::assertNotNull($export->startedAt);
        self::assertNotNull($export->completedAt);
        self::assertNotSame(100, $export->progress);
    }

    /** @return array<string, string> */
    private function readZipMembers(ExportRecord $export): array
    {
        $content = $this->exports->getFileContent($export);
        self::assertIsString($content);
        $inspectionRoot = $this->createTrackedTempDirectory('report-provider-zip-inspection-');
        $inspectionPath = $inspectionRoot . '/archive.zip';
        FileHelper::writeToFile($inspectionPath, $content);
        $zip = new ZipArchive();
        $opened = false;
        $members = [];

        try {
            $opened = $zip->open($inspectionPath);
            self::assertTrue($opened);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $bytes = $zip->getFromIndex($index);
                self::assertIsString($name);
                self::assertIsString($bytes);
                $members[$name] = $bytes;
            }
        } finally {
            if ($opened === true) {
                $zip->close();
            }
            FileHelper::unlink($inspectionPath);
            FileHelper::removeDirectory($inspectionRoot);
            self::assertFileDoesNotExist($inspectionPath);
            self::assertDirectoryDoesNotExist($inspectionRoot);
        }

        return $members;
    }

    private function deleteOwnedExport(ExportRecord $export, string $physicalPath): void
    {
        $fresh = ExportRecord::findOne($export->id);
        if ($fresh instanceof ExportRecord) {
            self::assertTrue($this->exports->deleteExport((int)$fresh->id));
        }

        self::assertNull(ExportRecord::findOne($export->id));
        self::assertFileDoesNotExist($physicalPath);
    }

    private function removeOwnedDirectory(string $path): void
    {
        FileHelper::removeDirectory($path);
        self::assertDirectoryDoesNotExist($path);
    }

    private function queueRowCount(): int
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof Queue) {
            throw new \RuntimeException('Provider ZIP tests require the isolated database queue.');
        }

        return (int)(new Query())->from($queue->tableName)->count();
    }

    /** @return list<string> */
    private function zipHelperTempPaths(): array
    {
        $paths = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zip_export_*') ?: [];
        sort($paths, SORT_STRING);

        return array_values($paths);
    }
}

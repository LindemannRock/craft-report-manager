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
use craft\helpers\Db;
use craft\services\Config;
use craft\services\Volumes;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\reportmanager\exceptions\ExportStorageUnavailableException;
use lindemannrock\reportmanager\export\QueuedExportResult;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\storage\ExportStorage;
use lindemannrock\reportmanager\tests\Stubs\StubExportVolume;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Per-export storage identity lifecycle coverage.
 *
 * @since 5.6.0
 */
final class ExportStorageAffinityTest extends TestCase
{
    private const VOLUME_A_UID = '__rm_test_affinity_volume_a';
    private const VOLUME_B_UID = '__rm_test_affinity_volume_b';
    private const VOLUME_A_SUBPATH = '__rm_test_affinity_a';
    private const VOLUME_B_SUBPATH = '__rm_test_affinity_b';

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

    public function testPendingExportUsesCapturedStorageAfterSettingsChange(): void
    {
        $this->installStubProviderService();
        StubQueuedExportProvider::$nextResult = QueuedExportResult::table(['Column'], [['local']]);
        $localRoot = $this->createTrackedTempDirectory('report-affinity-local-');
        $volumeBRoot = $this->createTrackedTempDirectory('report-affinity-volume-b-');
        $volumeB = $this->localVolume(self::VOLUME_B_UID, self::VOLUME_B_SUBPATH, $volumeBRoot);
        $this->installVolumeMap([self::VOLUME_B_UID => $volumeB]);
        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $localRoot;

        $export = $this->exports->createQueuedExport(StubQueuedExportProvider::handle(), 'csv');
        $capturedPath = $export->filePath;
        self::assertSame(ExportStorage::TYPE_LOCAL, $export->storageType);
        self::assertNull($export->storageVolumeUid);

        $config = $this->createMock(Config::class);
        $config->method('getConfigFromFile')->willReturn(['exportVolumeUid' => self::VOLUME_B_UID]);
        Craft::$app->set('config', $config);
        PluginHelper::applyConfigOverridesToSettings($this->settings(), 'report-manager');

        self::assertSame(self::VOLUME_B_UID, $this->settings()->exportVolumeUid);
        self::assertTrue($this->exports->generateQueuedExport($export));
        self::assertFileExists($capturedPath);
        self::assertFileDoesNotExist($volumeBRoot . '/' . self::VOLUME_B_SUBPATH . '/' . basename($capturedPath));

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(ExportStorage::TYPE_LOCAL, $fresh->storageType);
        self::assertNull($fresh->storageVolumeUid);
        self::assertSame($capturedPath, $fresh->filePath);
    }

    public function testPendingVolumeExportKeepsCapturedUidAfterSettingsSelectAnotherVolume(): void
    {
        $this->installStubProviderService();
        StubQueuedExportProvider::$nextResult = QueuedExportResult::table(['Column'], [['volume-a']]);
        $volumeARoot = $this->createTrackedTempDirectory('report-affinity-volume-a-');
        $volumeBRoot = $this->createTrackedTempDirectory('report-affinity-volume-b-');
        $volumeA = $this->localVolume(self::VOLUME_A_UID, self::VOLUME_A_SUBPATH, $volumeARoot);
        $volumeB = $this->localVolume(self::VOLUME_B_UID, self::VOLUME_B_SUBPATH, $volumeBRoot);
        $this->installVolumeMap([
            self::VOLUME_A_UID => $volumeA,
            self::VOLUME_B_UID => $volumeB,
        ]);
        $this->settings()->exportVolumeUid = self::VOLUME_A_UID;

        $export = $this->exports->createQueuedExport(StubQueuedExportProvider::handle(), 'csv');
        self::assertSame(ExportStorage::TYPE_VOLUME, $export->storageType);
        self::assertSame(self::VOLUME_A_UID, $export->storageVolumeUid);
        $this->settings()->exportVolumeUid = self::VOLUME_B_UID;

        self::assertTrue($this->exports->generateQueuedExport($export));
        self::assertFileExists($this->volumePath($volumeARoot, self::VOLUME_A_SUBPATH, $export->filePath));
        self::assertFileDoesNotExist($this->volumePath($volumeBRoot, self::VOLUME_B_SUBPATH, $export->filePath));
    }

    public function testHistoricalResolvedRowsUseTheirCapturedIdentityForReadAndDelete(): void
    {
        $localRoot = $this->createTrackedTempDirectory('report-affinity-history-local-');
        $volumeARoot = $this->createTrackedTempDirectory('report-affinity-history-a-');
        $volumeBRoot = $this->createTrackedTempDirectory('report-affinity-history-b-');
        $volumeA = $this->localVolume(self::VOLUME_A_UID, self::VOLUME_A_SUBPATH, $volumeARoot);
        $volumeB = $this->localVolume(self::VOLUME_B_UID, self::VOLUME_B_SUBPATH, $volumeBRoot);
        $this->installVolumeMap([
            self::VOLUME_A_UID => $volumeA,
            self::VOLUME_B_UID => $volumeB,
        ]);

        $localPath = $localRoot . '/historical-local.csv';
        file_put_contents($localPath, 'historical local');
        $local = $this->completedExport($localPath, ExportStorage::TYPE_LOCAL);
        $volumePath = 'report-manager/exports/historical-volume.csv';
        $volumeA->write($volumePath, 'historical volume');
        $remote = $this->completedExport($volumePath, ExportStorage::TYPE_VOLUME, self::VOLUME_A_UID);
        $this->settings()->exportVolumeUid = self::VOLUME_B_UID;

        self::assertSame('historical local', $this->exports->getFileContent($local));
        self::assertSame('historical volume', $this->exports->getFileContent($remote));
        self::assertTrue($this->exports->fileExists($local));
        self::assertTrue($this->exports->fileExists($remote));
        self::assertTrue($this->exports->deleteExport((int)$remote->id));
        self::assertFalse($volumeA->fileExists($volumePath));
        self::assertFalse($volumeB->fileExists($volumePath));
        self::assertNotNull(ExportRecord::findOne($local->id));
    }

    public function testStoredUnavailableVolumeFailsClosedAndRecoversUsingSameUid(): void
    {
        $this->installStubProviderService();
        StubQueuedExportProvider::$nextResult = QueuedExportResult::table(['Column'], [['recovered']]);
        $volumeARoot = $this->createTrackedTempDirectory('report-affinity-recovery-a-');
        $volumeBRoot = $this->createTrackedTempDirectory('report-affinity-recovery-b-');
        $volumeA = $this->localVolume(self::VOLUME_A_UID, self::VOLUME_A_SUBPATH, $volumeARoot);
        $volumeB = $this->localVolume(self::VOLUME_B_UID, self::VOLUME_B_SUBPATH, $volumeBRoot);
        $this->installVolumeMap([self::VOLUME_A_UID => $volumeA]);
        $this->settings()->exportVolumeUid = self::VOLUME_A_UID;
        $export = $this->exports->createQueuedExport(StubQueuedExportProvider::handle(), 'csv');

        $this->settings()->exportVolumeUid = self::VOLUME_B_UID;
        $this->installVolumeMap([self::VOLUME_B_UID => $volumeB]);
        self::assertFalse($this->exports->generateQueuedExport($export));
        $failed = ExportRecord::findOne($export->id);
        self::assertNotNull($failed);
        self::assertSame(self::VOLUME_A_UID, $failed->storageVolumeUid);
        self::assertSame(ExportStorage::unavailableMessage(), $failed->errorMessage);
        self::assertFileDoesNotExist($this->volumePath($volumeBRoot, self::VOLUME_B_SUBPATH, $failed->filePath));

        $this->installVolumeMap([
            self::VOLUME_A_UID => $volumeA,
            self::VOLUME_B_UID => $volumeB,
        ]);
        self::assertTrue($this->exports->generateQueuedExport($failed));
        self::assertFileExists($this->volumePath($volumeARoot, self::VOLUME_A_SUBPATH, $failed->filePath));
        self::assertFileDoesNotExist($this->volumePath($volumeBRoot, self::VOLUME_B_SUBPATH, $failed->filePath));
    }

    public function testUnresolvedLegacyRowsRemainListedAndPreservedWithoutStorageProbing(): void
    {
        $volumes = $this->createMock(Volumes::class);
        $volumes->expects(self::never())->method('getVolumeByUid');
        Craft::$app->set('volumes', $volumes);
        $export = $this->completedExport('report-manager/exports/ambiguous-legacy.csv', null);

        $presentation = $this->exports->getFilePresentation($export);
        self::assertFalse($presentation['available']);
        self::assertSame(ExportStorage::TYPE_UNRESOLVED, $presentation['state']);
        self::assertSame(ExportStorage::unresolvedMessage(), $presentation['error']);
        self::assertNotSame('missing', $presentation['state']);

        try {
            $this->exports->getFileContent($export);
            self::fail('Unresolved storage must fail closed.');
        } catch (ExportStorageUnavailableException $exception) {
            self::assertSame(ExportStorage::unresolvedMessage(), $exception->getMessage());
        }

        self::assertFalse($this->exports->deleteExport((int)$export->id));
        self::assertNotNull(ExportRecord::findOne($export->id));
        self::assertSame(ExportStorage::unresolvedMessage(), $this->exports->getLastStorageError());
    }

    public function testRetentionRoutesResolvedRowsAndPreservesUnresolvedRows(): void
    {
        $volumeARoot = $this->createTrackedTempDirectory('report-affinity-retention-a-');
        $volumeBRoot = $this->createTrackedTempDirectory('report-affinity-retention-b-');
        $localRoot = $this->createTrackedTempDirectory('report-affinity-retention-local-');
        $volumeA = $this->localVolume(self::VOLUME_A_UID, self::VOLUME_A_SUBPATH, $volumeARoot);
        $volumeB = $this->localVolume(self::VOLUME_B_UID, self::VOLUME_B_SUBPATH, $volumeBRoot);
        $this->installVolumeMap([
            self::VOLUME_A_UID => $volumeA,
            self::VOLUME_B_UID => $volumeB,
        ]);
        $this->settings()->exportVolumeUid = self::VOLUME_B_UID;
        $this->settings()->autoCleanupExports = true;
        $this->settings()->exportRetention = 9000;
        $cutoff = (new \DateTime())->modify('-9000 days');
        self::assertSame(0, (int)(new Query())
            ->from(ExportRecord::tableName())
            ->where(['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->count(), 'Retention fixture cutoff must exclude every pre-existing row.');

        $localPath = $localRoot . '/old-local.csv';
        file_put_contents($localPath, 'old local');
        $local = $this->completedExport($localPath, ExportStorage::TYPE_LOCAL);
        $volumePath = 'report-manager/exports/old-volume.csv';
        $volumeA->write($volumePath, 'old volume');
        $remote = $this->completedExport($volumePath, ExportStorage::TYPE_VOLUME, self::VOLUME_A_UID);
        $unresolved = $this->completedExport('report-manager/exports/old-unresolved.csv', null);
        $ids = [(int)$local->id, (int)$remote->id, (int)$unresolved->id];
        Craft::$app->getDb()->createCommand()
            ->update(ExportRecord::tableName(), ['dateCreated' => '2000-01-01 00:00:00'], ['id' => $ids])
            ->execute();

        self::assertSame(2, $this->exports->cleanupOldExports());
        self::assertFileDoesNotExist($localPath);
        self::assertFalse($volumeA->fileExists($volumePath));
        self::assertFalse($volumeB->fileExists($volumePath));
        self::assertNull(ExportRecord::findOne($local->id));
        self::assertNull(ExportRecord::findOne($remote->id));
        self::assertNotNull(ExportRecord::findOne($unresolved->id));
    }

    public function testFreshInstallAndLiveSchemaContainNullableAffinityColumns(): void
    {
        $schema = Craft::$app->getDb()->getTableSchema(ExportRecord::tableName(), true);
        self::assertNotNull($schema);
        self::assertSame(16, $schema->columns['storageType']->size);
        self::assertTrue($schema->columns['storageType']->allowNull);
        self::assertSame(36, $schema->columns['storageVolumeUid']->size);
        self::assertTrue($schema->columns['storageVolumeUid']->allowNull);

        $install = file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');
        self::assertIsString($install);
        self::assertStringContainsString("'storageType' => \$this->string(16)->null()", $install);
        self::assertStringContainsString("'storageVolumeUid' => \$this->string(36)->null()", $install);
    }

    private function completedExport(string $filePath, ?string $storageType, ?string $volumeUid = null): ExportRecord
    {
        $export = new ExportRecord();
        $export->dataSource = self::MARKER . 'storage_affinity';
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

    private function localVolume(string $uid, string $subpath, string $root): StubExportVolume
    {
        $filesystem = new Local([
            'handle' => $uid . '_fs',
            'name' => $uid . ' filesystem',
            'path' => $root,
        ]);

        return new StubExportVolume($filesystem, [
            'uid' => $uid,
            'handle' => $uid,
            'name' => $uid,
            'subpath' => $subpath,
        ]);
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

    private function volumePath(string $root, string $subpath, string $filePath): string
    {
        return $root . '/' . $subpath . '/' . $filePath;
    }
}

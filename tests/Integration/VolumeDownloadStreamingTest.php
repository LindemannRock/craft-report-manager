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
use craft\services\Volumes;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\reportmanager\controllers\ExportsController;
use lindemannrock\reportmanager\exceptions\ExportStorageUnavailableException;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\storage\ExportStorage;
use lindemannrock\reportmanager\tests\Stubs\StubExportVolume;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * Response-stream ownership for recorded Craft-volume downloads.
 *
 * @since 5.6.0
 */
#[CoversClass(ExportsController::class)]
final class VolumeDownloadStreamingTest extends TestCase
{
    private const VOLUME_UID = '__rm_test_stream_volume';
    private const OTHER_VOLUME_UID = '__rm_test_stream_other_volume';
    private const SUBPATH = '__rm_test_stream_root';
    private const FILE_PATH = 'report-manager/exports/stream.csv';

    private Volumes $originalVolumes;
    private object $originalRequest;
    private object $originalResponse;
    /** @var list<string> */
    private array $requestedVolumeUids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalVolumes = Craft::$app->getVolumes();
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));

        $admin = $this->createTestUser(self::MARKER . 'stream_admin', ['admin' => true]);
        $admin->admin = true;
        self::assertTrue(Craft::$app->getElements()->saveElement($admin, false));
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        Craft::$app->set('volumes', $this->originalVolumes);
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('response', $this->originalResponse);

        parent::tearDown();
    }

    public function testVolumeDownloadUsesRecordedWrapperStreamSizeAndMimeWithoutReading(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'stream bytes');
        rewind($stream);

        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->expects(self::exactly(2))
            ->method('fileExists')
            ->with(self::SUBPATH . '/' . self::FILE_PATH)
            ->willReturn(true);
        $filesystem->expects(self::once())
            ->method('getFileStream')
            ->with(self::SUBPATH . '/' . self::FILE_PATH)
            ->willReturn($stream);
        $filesystem->expects(self::once())
            ->method('getFileSize')
            ->with(self::SUBPATH . '/' . self::FILE_PATH)
            ->willReturn(12);
        $filesystem->expects(self::never())->method('read');

        $this->installRecordedVolume($filesystem);
        $this->settings()->exportVolumeUid = self::OTHER_VOLUME_UID;
        $export = $this->completedVolumeExport();
        $response = new RecordingStreamResponse();
        Craft::$app->set('response', $response);
        Craft::$app->getRequest()->getHeaders()->set('Range', 'bytes=2-5');

        try {
            $result = (new ExportsController('exports', ReportManager::$plugin))
                ->actionDownload((int)$export->id);

            self::assertSame($response, $result);
            self::assertSame($stream, $response->sentStream);
            self::assertSame('stream.csv', $response->attachmentName);
            self::assertSame(12, $response->options['fileSize'] ?? null);
            self::assertSame('text/csv', $response->options['mimeType'] ?? null);
            self::assertSame(206, $response->getStatusCode());
            self::assertSame('bytes 2-5/12', $response->getHeaders()->get('Content-Range'));
            self::assertSame(4, $response->getHeaders()->get('Content-Length'));
            self::assertIsResource($stream, 'Yii owns the stream after successful response preparation.');
            self::assertNotEmpty($this->requestedVolumeUids);
            self::assertSame([self::VOLUME_UID], array_values(array_unique($this->requestedVolumeUids)));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testStreamClosesWhenAuthoritativeSizeLookupFailsAfterAcquisition(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->method('fileExists')->willReturn(true);
        $filesystem->method('getFileStream')->willReturn($stream);
        $filesystem->method('getFileSize')->willThrowException(new RuntimeException('size unavailable'));
        $filesystem->expects(self::never())->method('read');
        $this->installRecordedVolume($filesystem);

        try {
            $this->exports->getFileStream($this->completedVolumeExport());
            self::fail('Stream setup must fail closed when authoritative size lookup fails.');
        } catch (ExportStorageUnavailableException) {
            self::assertFalse(is_resource($stream));
        }
    }

    public function testControllerClosesStreamWhenResponsePreparationFails(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        $filesystem = $this->createMock(FsInterface::class);
        $filesystem->method('fileExists')->willReturn(true);
        $filesystem->method('getFileStream')->willReturn($stream);
        $filesystem->method('getFileSize')->willReturn(0);
        $filesystem->expects(self::never())->method('read');
        $this->installRecordedVolume($filesystem);
        Craft::$app->set('response', new ThrowingStreamResponse());
        $export = $this->completedVolumeExport();

        try {
            (new ExportsController('exports', ReportManager::$plugin))->actionDownload((int)$export->id);
            self::fail('Response preparation failure should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('response setup failed', $exception->getMessage());
            self::assertFalse(is_resource($stream));
        }
    }

    public function testLocalDownloadStillUsesSendFile(): void
    {
        $directory = $this->createTrackedTempDirectory('report-local-download-');
        $path = $directory . '/local.csv';
        file_put_contents($path, 'local');
        $export = new ExportRecord([
            'dataSource' => self::MARKER . 'local_download',
            'entityId' => 0,
            'format' => 'csv',
            'filename' => 'local.csv',
            'filePath' => $path,
            'fileSize' => 5,
            'recordCount' => 1,
            'status' => ExportRecord::STATUS_COMPLETED,
            'progress' => 100,
            'triggeredBy' => ExportRecord::TRIGGER_MANUAL,
            'storageType' => ExportStorage::TYPE_LOCAL,
        ]);
        self::assertTrue($export->save(false));
        $response = new RecordingFileResponse();
        Craft::$app->set('response', $response);

        $result = (new ExportsController('exports', ReportManager::$plugin))
            ->actionDownload((int)$export->id);

        self::assertSame($response, $result);
        self::assertSame($path, $response->filePath);
        self::assertSame('local.csv', $response->attachmentName);
        self::assertSame('text/csv', $response->options['mimeType'] ?? null);
    }

    private function completedVolumeExport(): ExportRecord
    {
        $export = new ExportRecord([
            'dataSource' => self::MARKER . 'stream_download',
            'entityId' => 0,
            'format' => 'csv',
            'filename' => 'stream.csv',
            'filePath' => self::FILE_PATH,
            'fileSize' => 999,
            'recordCount' => 1,
            'status' => ExportRecord::STATUS_COMPLETED,
            'progress' => 100,
            'triggeredBy' => ExportRecord::TRIGGER_MANUAL,
            'storageType' => ExportStorage::TYPE_VOLUME,
            'storageVolumeUid' => self::VOLUME_UID,
        ]);
        self::assertTrue($export->save(false));

        return $export;
    }

    private function installRecordedVolume(FsInterface $filesystem): void
    {
        $volume = new StubExportVolume($filesystem, [
            'uid' => self::VOLUME_UID,
            'handle' => self::VOLUME_UID,
            'name' => 'Recorded Stream Volume',
            'subpath' => self::SUBPATH,
        ]);
        $volumes = $this->createMock(Volumes::class);
        $volumes->method('getVolumeByUid')->willReturnCallback(
            function(string $uid) use ($volume): ?StubExportVolume {
                $this->requestedVolumeUids[] = $uid;

                return $uid === self::VOLUME_UID ? $volume : null;
            },
        );
        Craft::$app->set('volumes', $volumes);
    }
}

class RecordingStreamResponse extends Response
{
    /** @var resource|null */
    public $sentStream = null;
    public ?string $attachmentName = null;
    /** @var array<string, mixed> */
    public array $options = [];

    public function sendStreamAsFile($handle, $attachmentName, $options = [])
    {
        $this->sentStream = $handle;
        $this->attachmentName = $attachmentName;
        $this->options = $options;

        return parent::sendStreamAsFile($handle, $attachmentName, $options);
    }
}

final class ThrowingStreamResponse extends RecordingStreamResponse
{
    public function sendStreamAsFile($handle, $attachmentName, $options = [])
    {
        $this->sentStream = $handle;
        $this->attachmentName = $attachmentName;
        $this->options = $options;

        throw new RuntimeException('response setup failed');
    }
}

final class RecordingFileResponse extends Response
{
    public ?string $filePath = null;
    public ?string $attachmentName = null;
    /** @var array<string, mixed> */
    public array $options = [];

    public function sendFile($filePath, $attachmentName = null, $options = []): Response
    {
        $this->filePath = $filePath;
        $this->attachmentName = $attachmentName;
        $this->options = $options;

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;
use RuntimeException;

/**
 * Failure path through
 * {@see \lindemannrock\reportmanager\services\ExportService::generateQueuedExport()}.
 *
 * Pins the catch arm: when the provider throws, the export row must end up
 * with status FAILED, the thrown message captured into `errorMessage`, and
 * `completedAt` set so the CP's "Generated X minutes ago" copy still resolves
 * for the failed entry.
 *
 * Skips the file-existence assertion deliberately — the failure happens
 * before any file is written, and pinning `not exists` would flake if a
 * previous test left a same-named file around (filenames embed the second-
 * resolution timestamp; collisions are unlikely but possible).
 *
 * @since 5.4.0
 */
final class QueuedExportGenerateFailureTest extends TestCase
{
    public function testGenerateMarksExportFailedWhenProviderThrows(): void
    {
        $this->installStubProviderService();
        StubQueuedExportProvider::$nextResult = new RuntimeException('__rm_test_provider_blew_up');

        $export = $this->exports->createQueuedExport(
            providerHandle: StubQueuedExportProvider::handle(),
            format: 'csv',
            payload: [],
        );

        $ok = $this->exports->generateQueuedExport($export);

        self::assertFalse($ok, 'generateQueuedExport() should return false when the provider throws');
        self::assertCount(1, StubQueuedExportProvider::$generateCalls, 'Provider::generate() should run exactly once before throwing');

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(ExportRecord::STATUS_FAILED, $fresh->status);
        self::assertSame('__rm_test_provider_blew_up', $fresh->errorMessage);
        self::assertNotNull($fresh->completedAt, 'completedAt should be stamped even on failure so the CP can show timing');
    }
}

<?php

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use InvalidArgumentException;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * {@see \lindemannrock\reportmanager\services\ExportService::createQueuedExport}
 * — the record-creation half of the queued export pipeline.
 *
 * Pins three behaviours that other plugins depend on when handing payloads to
 * Report Manager:
 *  1. The persisted record round-trips the provider's normalized payload AND
 *     stamps `metadata.provider` + `metadata.permissions` onto the row.
 *  2. Format aliases (e.g. `excel`/`xls`) are folded into the canonical
 *     `xlsx` value before persistence so the downstream `match()` branches
 *     work, and the filename is forced to the matching extension.
 *  3. Unsupported formats are rejected upfront with
 *     `InvalidArgumentException` instead of failing later in the queue.
 *
 * @since 5.4.0
 */
final class QueuedExportCreateTest extends TestCase
{
    public function testCreateQueuedExportPersistsNormalizedPayloadAndProviderMetadata(): void
    {
        $this->installStubProviderService();
        StubQueuedExportProvider::$permissions = [
            'status' => '__rm_test_perm_status',
            'download' => '__rm_test_perm_download',
        ];

        $export = $this->exports->createQueuedExport(
            providerHandle: StubQueuedExportProvider::handle(),
            format: 'csv',
            payload: ['caller' => '__rm_test_caller'],
        );

        self::assertSame(ExportRecord::STATUS_PENDING, $export->status);
        self::assertSame(StubQueuedExportProvider::handle(), $export->providerHandle);
        self::assertSame(0, $export->entityId);
        self::assertTrue($export->isProviderExport());

        $payload = $export->getPayloadArray();
        self::assertSame('__rm_test_caller', $payload['caller']);
        self::assertTrue(
            $payload[StubQueuedExportProvider::NORMALIZED_MARKER] ?? false,
            'normalizePayload() must run before the record is persisted so the marker key lands in the DB',
        );

        $metadata = $export->getMetadataArray();
        self::assertSame(StubQueuedExportProvider::handle(), $metadata['provider']['handle']);
        self::assertSame(StubQueuedExportProvider::displayName(), $metadata['provider']['name']);
        self::assertSame('__rm_test_perm_status', $metadata['permissions']['status']);
        self::assertSame('__rm_test_perm_download', $metadata['permissions']['download']);
    }

    public function testCreateQueuedExportNormalizesExcelAliasAndEnforcesFilenameExtension(): void
    {
        $this->installStubProviderService();

        $export = $this->exports->createQueuedExport(
            providerHandle: StubQueuedExportProvider::handle(),
            format: 'excel',
            payload: [],
            options: ['filename' => '__rm_test_dataset.txt'],
        );

        self::assertSame('xlsx', $export->format, "'excel' alias should fold to 'xlsx' before persistence");
        self::assertStringEndsWith('.xlsx', $export->filename, 'Wrong-extension filenames should be rewritten to match the normalised format');
        // `ensureFilenameExtension()` runs `trim($filename, '.-_')`, which strips
        // leading underscores. The marker survives as `rm_test_` in filenames
        // even though the DB row's `dataSource` column keeps the full `__rm_test_`
        // prefix (the handle string itself isn't trim()'d).
        self::assertStringContainsString('rm_test_dataset', $export->filename);
    }

    public function testCreateQueuedExportRejectsUnsupportedFormatBeforePersistence(): void
    {
        $this->installStubProviderService();

        $beforeCount = $this->countRows(ExportRecord::tableName(), [
            'providerHandle' => StubQueuedExportProvider::handle(),
        ]);

        try {
            $this->exports->createQueuedExport(
                providerHandle: StubQueuedExportProvider::handle(),
                format: 'pdf',
            );
            self::fail('Expected InvalidArgumentException for unsupported format');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('pdf', $e->getMessage());
        }

        $afterCount = $this->countRows(ExportRecord::tableName(), [
            'providerHandle' => StubQueuedExportProvider::handle(),
        ]);
        self::assertSame($beforeCount, $afterCount, 'Rejected formats must not leave a stray pending row behind');
    }
}

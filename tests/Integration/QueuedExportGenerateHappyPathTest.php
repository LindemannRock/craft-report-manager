<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use lindemannrock\reportmanager\export\QueuedExportResult;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Happy path through
 * {@see \lindemannrock\reportmanager\services\ExportService::generateQueuedExport()}.
 *
 * Pins the full state transition for a successful queued export:
 *  - status flips pending → processing → completed
 *  - record gets recordCount / fileSize / progress=100 / completedAt
 *  - provider warnings are persisted via setWarningsArray()
 *  - the generated file actually lands on disk at the expected path
 *
 * The stub returns a 2-row CSV table; `_writeExportFile()` will land it on the
 * local filesystem at `{exportPath}/__rm_test_provider_*.csv`. The TestCase's
 * `cleanupExternalState()` purges that file after the test.
 *
 * @since 5.4.0
 */
final class QueuedExportGenerateHappyPathTest extends TestCase
{
    public function testGenerateTableExportWritesFileAndCompletesRecord(): void
    {
        $this->installStubProviderService();
        StubQueuedExportProvider::$nextResult = QueuedExportResult::table(
            headers: ['col1', 'col2'],
            rows: [
                ['__rm_test_a', 1],
                ['__rm_test_b', 2],
            ],
            warnings: ['__rm_test_warning'],
        );

        $export = $this->exports->createQueuedExport(
            providerHandle: StubQueuedExportProvider::handle(),
            format: 'csv',
            payload: ['caller' => '__rm_test_caller'],
        );

        $ok = $this->exports->generateQueuedExport($export);

        self::assertTrue($ok, 'generateQueuedExport() should return true on the happy path');
        self::assertCount(1, StubQueuedExportProvider::$generateCalls, 'Provider::generate() should run exactly once');

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(ExportRecord::STATUS_COMPLETED, $fresh->status);
        self::assertSame(100, $fresh->progress);
        self::assertSame(2, $fresh->recordCount, 'recordCount should reflect the QueuedExportResult row count');
        self::assertGreaterThan(0, $fresh->fileSize, 'fileSize should be set after the file is written');
        self::assertNotNull($fresh->completedAt);
        self::assertNotNull($fresh->startedAt);
        self::assertNull($fresh->errorMessage);
        self::assertSame(['__rm_test_warning'], $fresh->getWarningsArray(), 'Provider warnings must round-trip through setWarningsArray()');

        self::assertTrue(
            $this->exports->fileExists($fresh),
            "Generated CSV must exist on disk at {$fresh->filePath}",
        );
    }
}

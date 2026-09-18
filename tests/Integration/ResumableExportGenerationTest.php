<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\tests\Integration;

use Craft;
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\export\ExportContinuation;
use lindemannrock\reportmanager\jobs\GenerateExportJob;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\tests\Stubs\ResumableExportSource;
use lindemannrock\reportmanager\tests\Support\ControlledExportContinuation;
use lindemannrock\reportmanager\tests\Support\ControlledExportService;
use lindemannrock\reportmanager\tests\Support\ExportStepQueue;
use lindemannrock\reportmanager\tests\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Durable output, retry, publication and exact deletion across fresh executions.
 *
 * @since 5.6.1
 */
class ResumableExportGenerationTest extends TestCase
{
    private ExportStepQueue $stepQueue;
    private object $savedQueue;
    private string $directory;
    /** @var list<array{int, int, string|null}> */
    private array $queueProgressUpdates = [];

    protected function setUp(): void
    {
        parent::setUp();
        ResumableExportSource::$count = 205;
        ResumableExportSource::$removed = null;
        ResumableExportSource::$bad = null;
        ResumableExportSource::$entityCounts = [];
        ResumableExportSource::$fieldCount = 2;
        ControlledExportContinuation::$interruptAt = null;
        ControlledExportContinuation::$rowBudget = 90;
        ControlledExportContinuation::$boundaries = [];
        $this->directory = $this->createTrackedTempDirectory('report-continuation-');
        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $this->directory;
        $this->settings()->csvIncludeBom = false;
        $this->settings()->csvDelimiter = ',';
        $this->settings()->csvEnclosure = '"';
        $this->savedQueue = Craft::$app->getQueue();
        $this->stepQueue = new ExportStepQueue();
        Craft::$app->set('queue', $this->stepQueue);
        $this->freshWorker();
    }

    protected function tearDown(): void
    {
        ControlledExportContinuation::$interruptAt = null;
        ControlledExportContinuation::$rowBudget = 90;
        ResumableExportSource::$removed = null;
        ResumableExportSource::$bad = null;
        ResumableExportSource::$entityCounts = [];
        ResumableExportSource::$fieldCount = 2;
        Craft::$app->set('queue', $this->savedQueue);
        parent::tearDown();
    }

    public static function formats(): iterable
    {
        foreach (['csv', 'json', 'xlsx'] as $format) {
            yield $format . '-separate' => [$format, false];
            yield $format . '-combined' => [$format, true];
        }
    }

    #[DataProvider('formats')]
    public function testQueueShowsOverallProgressDuringEachContinuation(string $format, bool $combined): void
    {
        $export = $this->createExport($format, $combined);
        self::assertTrue($this->exports->queueExportGeneration($export));
        $queue = $this->createMock(\craft\queue\Queue::class);
        foreach (['priority', 'delay', 'ttr'] as $method) {
            $queue->method($method)->willReturnSelf();
        }
        $queue->method('push')->willReturnCallback(fn($job) => $this->stepQueue->push($job));
        $queue->method('setProgress')->willReturnCallback(function(int $progress, ?string $label) use ($export): void {
            $fresh = ExportRecord::findOne($export->id);
            $this->queueProgressUpdates[] = [$progress, (int)$fresh->progress, $label];
        });
        $observedRows = false;
        $observedAssembly = false;
        while ($this->stepQueue->messages !== []) {
            $before = ExportRecord::findOne($export->id);
            $phase = $before->getMetadataArray()[ExportContinuation::STATE_KEY]['phase'] ?? 'init';
            $this->queueProgressUpdates = [];
            $this->nextJob()->execute($queue);
            /** @var list<array{int, int, string|null}> $updates Captured by the queue callback during execution. */
            $updates = $this->queueProgressUpdates;
            self::assertNotEmpty($updates);
            self::assertSame(max(1, (int)$before->progress), $updates[0][0]);
            if ($phase === 'rows') {
                $observedRows = true;
                self::assertNotEmpty(array_filter($updates, static fn(array $update): bool => $update[0] > $update[1]));
                self::assertSame('Processing', $updates[0][2]);
            }
            if ($phase === 'assembly') {
                $observedAssembly = true;
                self::assertNotEmpty(array_filter($updates, static fn(array $update): bool => $update[0] > 95 && $update[0] < 99));
            }
            $fresh = ExportRecord::findOne($export->id);
            self::assertSame((int)$fresh->progress, $updates[array_key_last($updates)][0]);
            $percentages = array_column($updates, 0);
            $sorted = $percentages;
            sort($sorted);
            self::assertSame($sorted, $percentages);
        }
        self::assertTrue($observedRows);
        self::assertTrue($observedAssembly);
        $this->assertCompleted($export);
    }

    #[DataProvider('formats')]
    public function testFiniteJobsProduceExactOrderedOutput(string $format, bool $combined): void
    {
        $export = $this->createExport($format, $combined);
        self::assertTrue($this->exports->queueExportGeneration($export));
        $this->step();
        $fresh = ExportRecord::findOne($export->id);
        self::assertSame('processing', $fresh->status);
        self::assertFileDoesNotExist($fresh->filePath);
        $this->assertEncryptedObjects($export);
        $steps = $this->drain($export);
        self::assertGreaterThan(3, $steps);
        $rows = $this->readRows($export, $combined ? ['Item Name', 'ID', 'Value'] : ['ID', 'Value']);
        self::assertCount($combined ? 410 : 205, $rows);
        $idColumn = $combined ? 1 : 0;
        self::assertSame(range(1, 205), array_map('intval', array_column(array_slice($rows, 0, 205), $idColumn)));
        if ($combined) {
            self::assertSame(array_fill(0, 205, 'Form 1'), array_column(array_slice($rows, 0, 205), 0));
            self::assertSame(array_fill(0, 205, 'Form 2'), array_column(array_slice($rows, 205), 0));
            self::assertSame(range(1, 205), array_map('intval', array_column(array_slice($rows, 205), 1)));
        }
        self::assertSame('العربية English 1', $rows[1][$idColumn + 1]);
        self::assertSame($format === 'csv' ? "'=1+1" : '=1+1', $rows[0][$idColumn + 1]);
        self::assertDirectoryDoesNotExist($this->workDirectory($export));
    }

    public static function interruptionBoundaries(): iterable
    {
        foreach (['selection', 'chunk', 'checkpoint', 'admission', 'publication', 'cleanup'] as $boundary) {
            yield $boundary => [$boundary];
        }
    }

    #[DataProvider('interruptionBoundaries')]
    public function testRetryAtCommitBoundariesDoesNotDuplicateOrSkip(string $boundary): void
    {
        $export = $this->createExport('json');
        self::assertTrue($this->exports->queueExportGeneration($export));
        ControlledExportContinuation::$interruptAt = $boundary;
        $interrupted = false;
        for ($i = 0; $i < 30 && $this->stepQueue->messages !== []; $i++) {
            $job = $this->nextJob();
            try {
                $this->freshWorker();
                $job->execute($this->stepQueue);
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Controlled interruption', $error->getPrevious()->getMessage());
                self::assertTrue($job->canRetry(1, $error));
                $interrupted = true;
                $this->freshWorker();
                $job->execute($this->stepQueue);
            }
        }
        self::assertTrue($interrupted);
        $this->assertCompleted($export);
        self::assertSame(range(1, 205), array_map('intval', array_column($this->readRows($export), 0)));
        self::assertDirectoryDoesNotExist($this->workDirectory($export));
    }

    public function testRejectedSuccessorIsRecoverableAndExhaustionIsVisible(): void
    {
        $export = $this->createExport('csv');
        $job = new GenerateExportJob(['exportId' => (int)$export->id]);
        $this->stepQueue->reject = true;
        try {
            $job->execute($this->stepQueue);
            self::fail('Expected queue rejection.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('queue rejected', $error->getPrevious()->getMessage());
            self::assertTrue($job->canRetry(1, $error));
        }
        $this->stepQueue->reject = false;
        $job->execute($this->stepQueue);
        $this->drain($export);
        self::assertCount(205, $this->readRows($export));

        $failed = $this->createExport('json');
        $job = new GenerateExportJob(['exportId' => (int)$failed->id]);
        $this->stepQueue->reject = true;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $job->execute($this->stepQueue);
                self::fail('Expected queue rejection.');
            } catch (\RuntimeException $error) {
                self::assertSame($attempt < 3, $job->canRetry($attempt, $error));
            }
        }
        self::assertSame('failed', ExportRecord::findOne($failed->id)->status);
        self::assertTrue($this->exports->deleteExport((int)$failed->id));
        self::assertDirectoryDoesNotExist($this->workDirectory($failed));
    }

    #[DataProvider('interruptionBoundaries')]
    public function testKilledWorkerResumesInFreshProcessesWithoutLocalWriterState(string $boundary): void
    {
        $export = $this->createExport('xlsx');
        self::assertTrue($this->exports->queueExportGeneration($export));
        $spool = $this->directory . '/worker-queue.json';
        $interrupted = false;
        for ($i = 0; $i < 30 && $this->stepQueue->messages !== []; $i++) {
            $job = $this->nextJob();
            $result = $this->child($job, $spool, $interrupted ? '-' : $boundary);
            if ($result === 137) {
                self::assertSame('processing', ExportRecord::findOne($export->id)->status);
                $interrupted = true;
                // Simulate loss of every disposable writer byte on a new worker.
                $stage = Craft::$app->getPath()->getTempPath() . '/report-manager-work-' . $export->uid;
                if (is_dir($stage)) {
                    \craft\helpers\FileHelper::removeDirectory($stage);
                }
                self::assertSame(0, $this->child($job, $spool, '-'));
            } else {
                self::assertSame(0, $result);
            }
        }
        self::assertTrue($interrupted);
        $this->assertCompleted($export);
        self::assertSame(range(1, 205), array_map('intval', array_column($this->readRows($export), 0)));
        self::assertDirectoryDoesNotExist($this->workDirectory($export));
        self::assertDirectoryDoesNotExist(Craft::$app->getPath()->getTempPath() . '/report-manager-work-' . $export->uid);
    }

    public function testAbortedWorkerExhaustionStopsAtFrameworkRetryPreflight(): void
    {
        $export = $this->createExport('json');
        self::assertTrue($this->exports->queueExportGeneration($export));
        $this->step();
        $job = $this->nextJob();
        $message = $this->stepQueue->serializer->serialize($job);
        $spool = $this->directory . '/aborted-worker-queue.json';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::assertSame(137, $this->child($job, $spool, 'chunk', $attempt));
            self::assertSame('processing', ExportRecord::findOne($export->id)->status);
            self::assertSame([], $this->stepQueue->messages);
        }
        $before = ExportRecord::findOne($export->id)->getMetadataArray();
        $executions = 0;
        $this->stepQueue->on(ExportStepQueue::EVENT_BEFORE_EXEC, static function() use (&$executions): void {
            $executions++;
        });
        // Valid unserialization supplies null, not an invented exception.
        self::assertTrue($this->stepQueue->runReserved($message, 4));
        $failed = ExportRecord::findOne($export->id);
        self::assertSame('failed', $failed->status);
        self::assertSame(ExportContinuation::failureMessage(), $failed->errorMessage);
        self::assertNotNull($failed->completedAt);
        self::assertSame($before, $failed->getMetadataArray());
        self::assertSame(0, $executions);
        self::assertSame([], $this->stepQueue->messages);
        self::assertFileDoesNotExist($export->filePath);
        self::assertTrue($this->stepQueue->runReserved($message, 5));
        self::assertTrue($this->exports->deleteExport((int)$export->id));
        self::assertNull(ExportRecord::findOne($export->id));
        self::assertDirectoryDoesNotExist($this->workDirectory($export));
    }

    public function testAbortedStaleJobExhaustionPreservesAdmittedSuccessor(): void
    {
        $export = $this->createExport('json');
        self::assertTrue($this->exports->queueExportGeneration($export));
        $job = $this->nextJob();
        self::assertSame(137, $this->child($job, $this->directory . '/stale-worker-queue.json', 'admission'));
        $this->step();
        $before = ExportRecord::findOne($export->id)->getMetadataArray();
        $pending = $this->stepQueue->messages;
        $executions = 0;
        $this->stepQueue->on(ExportStepQueue::EVENT_BEFORE_EXEC, static function() use (&$executions): void {
            $executions++;
        });
        self::assertTrue($this->stepQueue->runReserved($this->stepQueue->serializer->serialize($job), 4));
        self::assertSame(0, $executions);
        self::assertSame('processing', ExportRecord::findOne($export->id)->status);
        self::assertSame($before, ExportRecord::findOne($export->id)->getMetadataArray());
        self::assertSame($pending, $this->stepQueue->messages);
        $this->drain($export);
        self::assertSame(range(1, 205), array_map('intval', array_column($this->readRows($export), 0)));
        self::assertDirectoryDoesNotExist($this->workDirectory($export));
    }

    public function testReplayAndDeletedExportsCannotResurrectOutput(): void
    {
        $export = $this->createExport('csv');
        $other = $this->createExport('json');
        self::assertTrue($this->exports->queueExportGeneration($export));
        $job = $this->nextJob();
        $job->execute($this->stepQueue);
        $before = count($this->stepQueue->messages);
        $job->execute($this->stepQueue);
        self::assertCount($before, $this->stepQueue->messages);
        self::assertFalse($job->canRetry(3, new \RuntimeException('A stale duplicate failed.')));
        self::assertSame('processing', ExportRecord::findOne($export->id)->status);
        self::assertTrue($this->exports->deleteExport((int)$export->id));
        foreach ($this->stepQueue->messages as $message) {
            $this->stepQueue->serializer->unserialize($message)->execute($this->stepQueue);
        }
        $job->execute($this->stepQueue);
        self::assertNull(ExportRecord::findOne($export->id));
        self::assertFileDoesNotExist($export->filePath);
        self::assertDirectoryDoesNotExist($this->workDirectory($export));
        self::assertNotNull(ExportRecord::findOne($other->id));
        $this->stepQueue->messages = [];
    }

    public function testCapturedMembershipIgnoresInsertionsAndSkipsRemovedIdentity(): void
    {
        $export = $this->createExport('json');
        self::assertTrue($this->exports->queueExportGeneration($export));
        $this->step();
        ResumableExportSource::$count = 210;
        ResumableExportSource::$removed = 2;
        $this->drain($export);
        self::assertSame(array_values(array_diff(range(1, 205), [2])), array_map('intval', array_column($this->readRows($export), 0)));
    }

    public static function builtInSources(): iterable
    {
        yield 'Formie' => ['formie'];
        yield 'Entries' => ['entries'];
        yield 'Categories' => ['categories'];
    }

    #[DataProvider('builtInSources')]
    public function testBuiltInSelectionMatchesSupportedSiteAndDateQueries(string $handle): void
    {
        $source = (new DataSourcesService())->getDataSource($handle);
        self::assertInstanceOf(\lindemannrock\reportmanager\datasources\ResumableDataSourceInterface::class, $source);
        $entities = $source->getAvailableEntities();
        self::assertNotEmpty($entities);
        $id = (int)$entities[0]['id'];
        $sites = array_slice(Craft::$app->getSites()->getAllSiteIds(), 0, 2);
        $options = ['siteIds' => $sites, 'dateRange' => 'all'];
        $expected = array_map(static fn($record): array => ['id' => (int)$record->id, 'siteId' => (int)$record->siteId], $source->getRecords($id, $options));
        self::assertNotEmpty($expected);
        self::assertSame($expected, iterator_to_array($source->getExportSelection($id, $options), false));
        self::assertSame([], iterator_to_array($source->getExportSelection($id, [
            'dateRange' => 'custom', 'dateStart' => '2090-01-01', 'dateEnd' => '2090-12-31',
        ]), false));
        $fields = array_column($source->getEntityFields($id), 'handle');
        $handles = array_values(array_intersect(['id', 'siteId'], $fields));
        $expectedRows = $source->exportToArray($id, $handles, $options);
        $export = $this->exports->createExport($handle, $id, 'json', ['siteIds' => $sites, 'fieldHandles' => $handles, 'dateRange' => 'all']);
        try {
            self::assertTrue($this->exports->queueExportGeneration($export));
            $this->drain($export);
            self::assertSame(array_map('array_values', $expectedRows['rows']), $this->readRows($export));
            self::assertDirectoryDoesNotExist($this->workDirectory($export));
        } finally {
            self::assertTrue($this->exports->deleteExport((int)$export->id));
            $this->stepQueue->messages = [];
        }
    }

    public function testTimeBudgetYieldsBeforeRowLimitAndBadRowStopsAfterThreeAttempts(): void
    {
        ResumableExportSource::$count = 4;
        ControlledExportContinuation::$rowBudget = 0;
        $export = $this->createExport('json');
        self::assertTrue($this->exports->queueExportGeneration($export));
        $this->step();
        $this->step();
        self::assertSame(1, ExportRecord::findOne($export->id)->getMetadataArray()[ExportContinuation::STATE_KEY]['processed']);
        ResumableExportSource::$bad = 2;
        $job = $this->nextJob();
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $job->execute($this->stepQueue);
                self::fail('Expected source serialization failure.');
            } catch (\RuntimeException $error) {
                self::assertSame($attempt < 3, $job->canRetry($attempt, $error));
            }
        }
        self::assertSame('failed', ExportRecord::findOne($export->id)->status);
        self::assertFileDoesNotExist($export->filePath);
        self::assertTrue($this->exports->deleteExport((int)$export->id));
    }

    public function testCapturedVolumeSurvivesStorageAndPublicationFailures(): void
    {
        $originalVolumes = Craft::$app->getVolumes();
        $root = $this->createTrackedTempDirectory('report-continuation-volume-');
        $fs = new \lindemannrock\reportmanager\tests\Support\ExportFaultFilesystem([
            'handle' => '__rm_test_continuation_fs', 'name' => 'Test volume', 'path' => $root,
        ]);
        $volume = new \lindemannrock\reportmanager\tests\Stubs\StubExportVolume($fs, [
            'uid' => '__rm_test_continuation_volume', 'handle' => '__rm_test_continuation_volume',
            'name' => 'Test volume', 'subpath' => 'nested',
        ]);
        $volumeMap = [$volume->uid => $volume];
        $volumes = $this->createMock(\craft\services\Volumes::class);
        $volumes->method('getVolumeByUid')->willReturnCallback(static function(string $uid) use (&$volumeMap) {
            return $volumeMap[$uid] ?? null;
        });
        Craft::$app->set('volumes', $volumes);
        $this->settings()->exportVolumeUid = $volume->uid;
        $export = $this->createExport('csv');
        try {
            self::assertTrue($this->exports->queueExportGeneration($export));
            $this->step();
            $this->settings()->exportVolumeUid = '';
            $job = $this->nextJob();
            $volumeMap = [];
            try {
                $job->execute($this->stepQueue);
                self::fail('Expected unavailable captured volume.');
            } catch (\lindemannrock\reportmanager\exceptions\ExportStorageUnavailableException $error) {
                self::assertTrue($job->canRetry(1, $error));
            }
            $volumeMap = [$volume->uid => $volume];
            $job->execute($this->stepQueue);
            while (ExportRecord::findOne($export->id)->getMetadataArray()[ExportContinuation::STATE_KEY]['phase'] !== 'assembly') {
                $this->step();
            }
            $job = $this->nextJob();
            $fs->failPublication = true;
            try {
                $job->execute($this->stepQueue);
                self::fail('Expected publication failure.');
            } catch (\Throwable $error) {
                self::assertTrue($job->canRetry(1, $error));
            }
            self::assertSame('processing', ExportRecord::findOne($export->id)->status);
            $fs->failPublication = false;
            $job->execute($this->stepQueue);
            $this->drain($export);
            self::assertFileExists($root . '/nested/' . $export->filePath);
            self::assertFileDoesNotExist($this->directory . '/' . basename($export->filePath));
            self::assertDirectoryDoesNotExist($root . '/nested/' . dirname($export->filePath) . '/work-' . $export->uid);
            $this->settings()->exportVolumeUid = $volume->uid;
            $pending = $this->createExport('json');
            try {
                self::assertTrue($this->exports->queueExportGeneration($pending));
                $volumeMap = [];
                self::assertFalse($this->exports->deleteExport((int)$pending->id));
                self::assertSame('failed', ExportRecord::findOne($pending->id)->status);
                $volumeMap = [$volume->uid => $volume];
                $this->step();
                self::assertFileDoesNotExist($root . '/nested/' . $pending->filePath);
            } finally {
                $volumeMap = [$volume->uid => $volume];
                self::assertTrue($this->exports->deleteExport((int)$pending->id));
            }
            $cancelled = $this->createExport('json');
            try {
                self::assertTrue($this->exports->queueExportGeneration($cancelled));
                $this->step();
                $fs->failCleanup = true;
                self::assertFalse($this->exports->deleteExport((int)$cancelled->id));
                self::assertSame('failed', ExportRecord::findOne($cancelled->id)->status);
                $this->step();
                self::assertFileDoesNotExist($root . '/nested/' . $cancelled->filePath);
                self::assertSame('completed', ExportRecord::findOne($export->id)->status);
            } finally {
                $fs->failCleanup = false;
                self::assertTrue($this->exports->deleteExport((int)$cancelled->id));
            }
            $unavailable = $this->createExport('json');
            try {
                $message = $this->stepQueue->serializer->serialize(new GenerateExportJob(['exportId' => (int)$unavailable->id]));
                $volumeMap = [];
                $errors = [];
                $this->stepQueue->on(ExportStepQueue::EVENT_AFTER_ERROR, static function($event) use (&$errors): void {
                    $errors[] = $event->error;
                });
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    self::assertSame($attempt === 3, $this->stepQueue->runReserved($message, $attempt));
                }
                self::assertCount(3, $errors);
                self::assertInstanceOf(\lindemannrock\reportmanager\exceptions\ExportStorageUnavailableException::class, $errors[2]);
                self::assertSame('failed', ExportRecord::findOne($unavailable->id)->status);
                self::assertSame($errors[2]->getMessage(), ExportRecord::findOne($unavailable->id)->errorMessage);
            } finally {
                $volumeMap = [$volume->uid => $volume];
                self::assertTrue($this->exports->deleteExport((int)$unavailable->id));
            }
        } finally {
            $volumeMap = [$volume->uid => $volume];
            $fs->failPublication = false;
            self::assertTrue($this->exports->deleteExport((int)$export->id));
            Craft::$app->set('volumes', $originalVolumes);
        }
    }

    public function testRetentionStopsContinuationAndPreservesUnrelatedExports(): void
    {
        $old = $this->createExport('json');
        $current = $this->createExport('csv');
        self::assertTrue($this->exports->queueExportGeneration($old));
        $this->step();
        Craft::$app->getDb()->createCommand()->update(ExportRecord::tableName(), [
            'dateCreated' => '2000-01-01 00:00:00',
        ], ['id' => $old->id])->execute();
        $this->settings()->autoCleanupExports = true;
        $this->settings()->exportRetention = 1;
        self::assertSame(1, $this->exports->cleanupOldExports());
        $this->step();
        self::assertNull(ExportRecord::findOne($old->id));
        self::assertNotNull(ExportRecord::findOne($current->id));
        self::assertDirectoryDoesNotExist($this->workDirectory($old));
        self::assertFileDoesNotExist($old->filePath);
    }

    public function testOldPendingPayloadStartsContinuationWithDefaultSequence(): void
    {
        $export = $this->createExport('json');
        $class = GenerateExportJob::class;
        $payload = 'O:' . strlen($class) . ':"' . $class . '":2:{s:8:"exportId";i:' . $export->id . ';s:8:"combined";b:0;}';
        $job = unserialize($payload, ['allowed_classes' => [$class]]);
        self::assertInstanceOf(GenerateExportJob::class, $job);
        self::assertSame(0, $job->sequence);
        $job->execute($this->stepQueue);
        $this->drain($export);
        self::assertCount(205, $this->readRows($export));
    }

    public function testExistingSourceSubclassKeepsSingleJobBehaviorUntilItOptsIn(): void
    {
        $source = new \lindemannrock\reportmanager\tests\Stubs\LegacyEntryExportSource();
        self::assertFalse($source::supportsExportContinuation());
        $entities = $source->getAvailableEntities();
        self::assertNotEmpty($entities);
        $export = $this->exports->createExport($source::handle(), (int)$entities[0]['id'], 'csv');
        self::assertFalse(ExportContinuation::supports($export));
        self::assertTrue($this->exports->queueExportGeneration($export));
        $this->step();
        $this->assertCompleted($export);
        self::assertSame([], $this->stepQueue->messages);
        self::assertArrayNotHasKey(ExportContinuation::STATE_KEY, ExportRecord::findOne($export->id)->getMetadataArray());
    }

    public static function benchmarkFormats(): iterable
    {
        yield 'CSV' => ['csv'];
        yield 'JSON' => ['json'];
        yield 'XLSX' => ['xlsx'];
    }

    #[DataProvider('benchmarkFormats')]
    public function testRepresentativeAssemblyHasBoundedMemoryAndExecutionHeadroom(string $format): void
    {
        ResumableExportSource::$entityCounts = [1 => 11482, 2 => 11447];
        ResumableExportSource::$fieldCount = 30;
        $export = $this->createExport($format, true);
        self::assertTrue($this->exports->queueExportGeneration($export));
        $assembly = null;
        $steps = 0;
        $start = microtime(true);
        while ($this->stepQueue->messages !== [] && $steps < 300) {
            $fresh = ExportRecord::findOne($export->id);
            $phase = $fresh->getMetadataArray()[ExportContinuation::STATE_KEY]['phase'] ?? 'init';
            gc_collect_cycles();
            gc_mem_caches();
            memory_reset_peak_usage();
            $stepStart = microtime(true);
            $this->step();
            if ($phase === 'assembly') {
                $assembly = ['seconds' => microtime(true) - $stepStart, 'peakBytes' => memory_get_peak_usage(true)];
            }
            $steps++;
        }
        $this->assertCompleted($export);
        self::assertNotNull($assembly);
        self::assertLessThan(120, $assembly['seconds'], 'Representative assembly must leave substantial headroom below its 300-second budget.');
        self::assertLessThan(128 * 1024 * 1024, $assembly['peakBytes']);
        self::assertSame(22929, (int)ExportRecord::findOne($export->id)->recordCount);
        $count = 0;
        $valid = true;
        $check = static function(array $row) use (&$count, &$valid): void {
            $entity = $count < 11482 ? 1 : 2;
            $expectedId = $entity === 1 ? $count + 1 : $count - 11482 + 1;
            $valid = $valid && count($row) === 31 && $row[0] === 'Form ' . $entity && (int)$row[1] === $expectedId;
            $count++;
        };
        if ($format === 'xlsx') {
            $reader = new \OpenSpout\Reader\XLSX\Reader();
            $reader->open($export->filePath);
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $index => $row) {
                        if ($index > 1) {
                            $check($row->toArray());
                        }
                    }
                }
            } finally {
                $reader->close();
            }
        } elseif ($format === 'csv') {
            $stream = fopen($export->filePath, 'rb');
            try {
                fgetcsv($stream, escape: '');
                while (($row = fgetcsv($stream, escape: '')) !== false) {
                    $check($row);
                }
            } finally {
                fclose($stream);
            }
        } else {
            foreach (json_decode(file_get_contents($export->filePath), true, flags: JSON_THROW_ON_ERROR) as $row) {
                $check(array_values($row));
            }
        }
        self::assertSame(22929, $count);
        self::assertTrue($valid);
        self::assertDirectoryDoesNotExist($this->workDirectory($export));
        fwrite(STDOUT, json_encode([
            'exportBenchmark' => $format, 'rows' => $count, 'columns' => 31, 'jobs' => $steps,
            'assembly' => $assembly, 'totalSeconds' => microtime(true) - $start,
            'fileBytes' => filesize($export->filePath),
        ], JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    private function createExport(string $format, bool $combined = false): ExportRecord
    {
        return $combined
            ? $this->exports->createCombinedExport(ResumableExportSource::handle(), [1, 2], $format)
            : $this->exports->createExport(ResumableExportSource::handle(), 1, $format);
    }

    private function freshWorker(): void
    {
        $sources = new DataSourcesService();
        $sources->on(DataSourcesService::EVENT_REGISTER_DATA_SOURCES, static function(RegisterDataSourcesEvent $event): void {
            $event->register(ResumableExportSource::handle(), ResumableExportSource::displayName(), ResumableExportSource::class);
            $legacy = \lindemannrock\reportmanager\tests\Stubs\LegacyEntryExportSource::class;
            $event->register($legacy::handle(), $legacy::displayName(), $legacy);
        });
        $this->swapPluginComponent('report-manager', 'dataSources', $sources);
        $this->exports = new ControlledExportService();
        $this->swapPluginComponent('report-manager', 'exports', $this->exports);
    }

    private function nextJob(): GenerateExportJob
    {
        self::assertNotEmpty($this->stepQueue->messages);
        $job = $this->stepQueue->serializer->unserialize(array_shift($this->stepQueue->messages));
        self::assertInstanceOf(GenerateExportJob::class, $job);

        return $job;
    }

    private function child(GenerateExportJob $job, string $spool, string $boundary, int $attempt = 1): int
    {
        if (is_file($spool)) {
            unlink($spool);
        }
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__) . '/Fixtures/Project/export-worker.php',
            (string)$job->exportId, (string)$job->sequence, $spool, $boundary, (string)$attempt,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 30;
        try {
            do {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertFalse($status['running'], 'Disposable export worker timed out.');
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if (is_file($spool)) {
                foreach (json_decode(file_get_contents($spool), true, flags: JSON_THROW_ON_ERROR) as $message) {
                    $this->stepQueue->messages[] = $message;
                }
                unlink($spool);
            }
            $exit = $status['signaled'] && $status['termsig'] === SIGKILL ? 137 : $status['exitcode'];
            self::assertContains($exit, [0, 137], $stdout . $stderr);

            return $exit;
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            if (is_file($spool)) {
                unlink($spool);
            }
        }
    }

    private function step(): void
    {
        $this->freshWorker();
        $this->nextJob()->execute($this->stepQueue);
    }

    private function drain(ExportRecord $export): int
    {
        $steps = 0;
        $progress = 0;
        while ($this->stepQueue->messages !== [] && $steps < 50) {
            $this->step();
            $fresh = ExportRecord::findOne($export->id);
            self::assertGreaterThanOrEqual($progress, (int)$fresh->progress);
            $progress = (int)$fresh->progress;
            $steps++;
        }
        self::assertLessThan(50, $steps);
        $this->assertCompleted($export);

        return $steps;
    }

    private function assertCompleted(ExportRecord $export): void
    {
        $fresh = ExportRecord::findOne($export->id);
        self::assertSame('completed', $fresh->status, (string)$fresh->errorMessage);
        self::assertSame(100, (int)$fresh->progress);
    }

    private function workDirectory(ExportRecord $export): string
    {
        return dirname($export->filePath) . '/work-' . $export->uid;
    }

    private function assertEncryptedObjects(ExportRecord $export): void
    {
        $files = glob($this->workDirectory($export) . '/*');
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertFalse(str_contains(file_get_contents($file), 'العربية'));
            $bytes = file_get_contents($file);
            $plaintext = Craft::$app->getSecurity()->decryptByKey($bytes);
            self::assertIsString($plaintext);
            self::assertNotSame($plaintext, $bytes);
        }
    }

    private function readRows(ExportRecord $export, ?array $expectedHeaders = null): array
    {
        $export->refresh();
        if ($export->format === 'json') {
            $rows = json_decode(file_get_contents($export->filePath), true, flags: JSON_THROW_ON_ERROR);
            if ($expectedHeaders !== null) {
                self::assertSame($expectedHeaders, array_keys($rows[0]));
            }
            return array_map('array_values', $rows);
        }
        if ($export->format === 'xlsx') {
            $spreadsheet = IOFactory::load($export->filePath);
            try {
                $rows = $spreadsheet->getActiveSheet()->toArray(null, false, false);
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
            $headers = array_shift($rows);
            if ($expectedHeaders !== null) {
                self::assertSame($expectedHeaders, $headers);
            }
            return $rows;
        }
        $stream = fopen($export->filePath, 'rb');
        try {
            $headers = fgetcsv($stream, escape: '');
            if ($expectedHeaders !== null) {
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
                self::assertSame($expectedHeaders, $headers);
            }
            $rows = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $rows[] = $row;
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }
}

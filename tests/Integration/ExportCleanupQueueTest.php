<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use Composer\InstalledVersions;
use Craft;
use craft\db\Command as CraftCommand;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\queue\Queue;
use craft\services\Config;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\base\helpers\RecurringQueueHelper;
use lindemannrock\base\queue\DeferredQueueJob;
use lindemannrock\base\queue\PortableQueueScheduler;
use lindemannrock\reportmanager\jobs\CleanupExportsJob;
use lindemannrock\reportmanager\jobs\ProcessScheduledReportJob;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\services\ExportCleanupScheduler;
use lindemannrock\reportmanager\services\ExportService;
use lindemannrock\reportmanager\tests\Support\IsolatedQueue;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use yii\mutex\Mutex;
use yii\queue\Queue as YiiQueue;
use yii\queue\sqs\Queue as SqsQueue;

/**
 * Pins the portable recurring generated-export cleanup lifecycle.
 *
 * @since 5.6.0
 */
final class ExportCleanupQueueTest extends TestCase
{
    private const START_TIMESTAMP = 1_800_000_000;

    private RecordingCleanupSqsQueue|RecordingUnknownQueue|null $proxyQueue = null;
    private bool $timePaused = false;

    protected function tearDown(): void
    {
        try {
            if ($this->timePaused) {
                DateTimeHelper::resume();
                $this->timePaused = false;
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testQueueStartsAsAnEmptyConnectionLocalShadow(): void
    {
        self::assertSame(0, (int)(new Query())->from('{{%queue}}')->count());
    }

    public function testRunnerFallbackRestoresTheShadowAndRemovesItsRows(): void
    {
        $this->pushOwnedJob(new CleanupExportsJob(['reschedule' => false]));
        self::assertSame(1, (int)(new Query())->from('{{%queue}}')->count());

        self::finishActiveTestIsolation();

        self::assertInstanceOf(IsolatedQueue::class, Craft::$app->getQueue());
        self::assertSame(0, (int)(new Query())->from('{{%queue}}')->count());
    }

    public function testApprovedBaseQueueRuntimeResolvesFromTheInstalledSource(): void
    {
        $basePath = InstalledVersions::getInstallPath('lindemannrock/craft-plugin-base');
        self::assertIsString($basePath);
        $helper = new ReflectionClass(RecurringQueueHelper::class);
        $scheduler = new ReflectionClass(PortableQueueScheduler::class);
        $handoff = new ReflectionClass(DeferredQueueJob::class);

        self::assertTrue($helper->hasMethod('ensurePending'));
        self::assertTrue($helper->hasMethod('deletePending'));
        self::assertTrue($scheduler->hasMethod('pushAt'));
        self::assertTrue($scheduler->hasMethod('continue'));
        self::assertTrue($scheduler->isFinal());
        self::assertTrue($handoff->isFinal());
        self::assertSame(realpath($basePath . '/src/helpers/RecurringQueueHelper.php'), $helper->getFileName());
        self::assertSame(realpath($basePath . '/src/queue/PortableQueueScheduler.php'), $scheduler->getFileName());
        self::assertSame(realpath($basePath . '/src/queue/DeferredQueueJob.php'), $handoff->getFileName());
    }

    public function testCanonicalDailyTargetAndQueueDescriptionRemainStable(): void
    {
        $this->enableCleanup();
        $from = new \DateTime('2026-08-17 15:45:30', new \DateTimeZone(Craft::$app->getTimeZone()));
        $target = $this->exportCleanupScheduler->getNextRun($this->settings(), $from);
        self::assertNotNull($target);
        self::assertSame('2026-08-18 00:00:00', $target->format('Y-m-d H:i:s'));

        $nextRunTime = $this->exportCleanupScheduler->getNextRunTime($this->settings(), $target);
        $job = $this->recurringJob($nextRunTime);
        self::assertSame(
            $this->settings()->getDisplayName() . ": Cleaning up old exports ($nextRunTime)",
            $job->getDescription(),
        );
        self::assertFalse($job->canRetry(1, new \RuntimeException('test')));
        self::assertSame(1800, $job->getTtr());

        $rowId = $this->pushOwnedJob($job, 300);
        $row = (new Query())->from('{{%queue}}')->where(['id' => $rowId])->one();
        self::assertIsArray($row);
        self::assertSame(1024, (int)$row['priority']);
        self::assertSame(1800, (int)$row['ttr']);
    }

    #[DataProvider('portableBoundaryProvider')]
    public function testDelayBoundaryDefersOnlyAboveNineHundredSeconds(int $delay, string $expectedClass): void
    {
        $queue = $this->installPortableQueue(true);
        $this->pauseAt(self::START_TIMESTAMP);

        PortableQueueScheduler::push(
            job: $this->recurringJob('boundary'),
            delay: $delay,
            identityTokens: $this->identityTokens(),
            mutexName: ExportCleanupScheduler::PORTABLE_MUTEX,
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );

        $row = $this->onlyOwnerRow();
        self::assertInstanceOf($expectedClass, $this->unserializeJob($row));
        self::assertSame($delay <= 900 ? $delay : 900, (int)$row['delay']);
        self::assertSame([$delay <= 900 ? $delay : 900], $this->proxyDelays());
    }

    /** @return iterable<string, array{int, class-string}> */
    public static function portableBoundaryProvider(): iterable
    {
        yield 'inclusive boundary' => [900, CleanupExportsJob::class];
        yield 'first deferred second' => [901, DeferredQueueJob::class];
    }

    public function testDailyTargetUsesMultipleHandoffsWithoutRunningCleanupEarly(): void
    {
        $queue = $this->installPortableQueue(true);
        $exports = new RecordingExportService();
        $this->swapPluginComponent('report-manager', 'exports', $exports);
        $this->pauseAt(self::START_TIMESTAMP);
        $target = self::START_TIMESTAMP + 2_400;

        $firstId = PortableQueueScheduler::pushAt(
            job: $this->recurringJob('daily'),
            targetTimestamp: $target,
            identityTokens: $this->identityTokens(),
            mutexName: ExportCleanupScheduler::PORTABLE_MUTEX,
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );
        self::assertNotNull($firstId);

        $this->pauseAt(self::START_TIMESTAMP + 900);
        self::assertTrue($queue->executeJob($firstId));
        self::assertInstanceOf(DeferredQueueJob::class, $this->unserializeJob($this->onlyOwnerRow()));
        self::assertSame(0, $exports->cleanupCalls);

        $secondId = (string)$this->onlyOwnerRow()['id'];
        $this->pauseAt(self::START_TIMESTAMP + 1_800);
        self::assertTrue($queue->executeJob($secondId));
        $consumer = $this->onlyOwnerRow();
        self::assertInstanceOf(CleanupExportsJob::class, $this->unserializeJob($consumer));
        self::assertSame(600, (int)$consumer['delay']);
        self::assertSame(0, $exports->cleanupCalls);
        self::assertLessThanOrEqual(900, max($this->proxyDelays()));
    }

    public function testLateHandoffDispatchesTheFinalConsumerImmediately(): void
    {
        $queue = $this->installPortableQueue(true);
        $this->pauseAt(self::START_TIMESTAMP);
        $jobId = PortableQueueScheduler::push(
            job: $this->recurringJob('late'),
            delay: 901,
            identityTokens: $this->identityTokens(),
            mutexName: ExportCleanupScheduler::PORTABLE_MUTEX,
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );
        self::assertNotNull($jobId);

        $this->pauseAt(self::START_TIMESTAMP + 950);
        self::assertTrue($queue->executeJob($jobId));

        $row = $this->onlyOwnerRow();
        self::assertInstanceOf(CleanupExportsJob::class, $this->unserializeJob($row));
        self::assertSame(0, (int)$row['delay']);
        self::assertSame([900, 0], $this->proxyDelays());
    }

    public function testNativeAndUnknownQueuesRetainTheCompleteDelay(): void
    {
        $queue = $this->installPortableQueue(false);
        $this->pauseAt(self::START_TIMESTAMP);

        PortableQueueScheduler::push(
            job: $this->recurringJob('native'),
            delay: 86_400,
            identityTokens: $this->identityTokens(),
            mutexName: ExportCleanupScheduler::PORTABLE_MUTEX,
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );

        $row = $this->onlyOwnerRow();
        self::assertInstanceOf(CleanupExportsJob::class, $this->unserializeJob($row));
        self::assertSame(86_400, (int)$row['delay']);
        self::assertSame([], $this->proxyDelays());

        $this->clearIsolatedQueueRows();
        $queue = $this->installPortableQueue(false, true);
        PortableQueueScheduler::push(
            job: $this->recurringJob('unknown-proxy'),
            delay: 86_400,
            identityTokens: $this->identityTokens(),
            mutexName: ExportCleanupScheduler::PORTABLE_MUTEX,
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );

        $row = $this->onlyOwnerRow();
        self::assertInstanceOf(CleanupExportsJob::class, $this->unserializeJob($row));
        self::assertSame(86_400, (int)$row['delay']);
        self::assertSame([86_400], $this->proxyDelays());
    }

    public function testBootstrapRetainsTheEarliestHealthyLegacyRowAndRemovesCompetition(): void
    {
        $this->enableCleanup();
        $legacy = $this->serializeJob(new CleanupExportsJob(['reschedule' => true]));
        $earliestId = $this->insertPayload($legacy, delay: 100, reserved: true);
        $this->insertPayload($legacy, delay: 200);
        $this->pushOwnedJob($this->recurringJob('competing'), 300);

        $this->exportCleanupScheduler->synchronize($this->settings());

        self::assertSame([$earliestId], $this->legacyRowIds());
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testRepeatedBootstrapCreatesOneCanonicalOwnerChain(): void
    {
        $this->enableCleanup();

        $this->exportCleanupScheduler->synchronize($this->settings());
        $firstId = $this->onlyOwnerRow()['id'];
        $this->exportCleanupScheduler->synchronize($this->settings());

        self::assertSame(1, $this->countOwnerRows());
        self::assertSame((string)$firstId, (string)$this->onlyOwnerRow()['id']);
    }

    public function testPhpJsonAndNestedLegacyPayloadsAreRecognizedExactly(): void
    {
        $this->enableCleanup();
        $phpId = $this->insertPayload($this->serializeJob(new CleanupExportsJob(['reschedule' => true])), delay: 100);
        $this->insertPayload(json_encode([
            'plugin' => 'reportmanager',
            'class' => 'CleanupExportsJob',
            'reschedule' => true,
        ], JSON_THROW_ON_ERROR), delay: 200);
        $nested = new DeferredQueueJob([
            'job' => new CleanupExportsJob(['reschedule' => true]),
            'targetTimestamp' => self::START_TIMESTAMP + 2_000,
            'identityTokens' => ['reportmanager', 'CleanupExportsJob'],
            'mutexName' => ExportCleanupScheduler::PORTABLE_MUTEX,
            'chainId' => 'legacy-cleanup-wrapper',
        ]);
        $this->insertPayload($this->serializeJob($nested), delay: 300);

        $this->exportCleanupScheduler->synchronize($this->settings());

        self::assertSame([$phpId], $this->legacyRowIds());
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testLegacyRecognitionPreservesNearMatchesAndOneShotCleanup(): void
    {
        $this->enableCleanup();
        $preservedIds = [
            $this->insertPayload('{"plugin":"reportmanager-addon","class":"CleanupExportsJob","reschedule":true}'),
            $this->insertPayload('{"plugin":"reportmanager","class":"NotCleanupExportsJob","reschedule":true}'),
            $this->insertPayload('{"plugin":"reportmanager","class":"CleanupExportsJob","reschedule":false}'),
            $this->insertPayload('{"plugin":"reportmanager","class":"CleanupExportsJob","reschedule":true,"recurringOwner":"report-manager:export-cleanup:daily-copy"}'),
            $this->pushOwnedJob(new CleanupExportsJob(['reschedule' => false]), 50),
            $this->pushOwnedJob(new CleanupExportsJob([
                'reschedule' => false,
                'recurringOwner' => ExportCleanupScheduler::RECURRING_OWNER,
            ]), 50),
        ];

        $this->exportCleanupScheduler->synchronize($this->settings());

        // The broad test query also sees the preserved owner near-match and
        // exact-owner one-shot alongside the canonical recurring consumer.
        self::assertSame(3, $this->countOwnerRows());
        self::assertSame(
            array_map('intval', $preservedIds),
            array_map('intval', (new Query())->from('{{%queue}}')->where(['id' => $preservedIds])->orderBy(['id' => SORT_ASC])->column()),
        );
    }

    public function testFailedLegacyCleanupDoesNotBlockRecovery(): void
    {
        $this->enableCleanup();
        $this->insertPayload(
            $this->serializeJob(new CleanupExportsJob(['reschedule' => true])),
            fail: true,
        );

        $this->exportCleanupScheduler->synchronize($this->settings());

        self::assertSame([], $this->legacyRowIds());
        self::assertSame(1, $this->countOwnerRows());
    }

    public function testCancellationCoversEveryCleanupStateAndPreservesPerReportRows(): void
    {
        $owned = $this->serializeJob($this->recurringJob('owned'));
        $legacy = $this->serializeJob(new CleanupExportsJob(['reschedule' => true]));
        foreach ([$owned, $legacy] as $payload) {
            $this->insertPayload($payload);
            $this->insertPayload($payload, reserved: true);
            $this->insertPayload($payload, fail: true);
        }
        $handoff = new DeferredQueueJob([
            'job' => $this->recurringJob('handoff'),
            'targetTimestamp' => self::START_TIMESTAMP + 2_000,
            'identityTokens' => $this->identityTokens(),
            'mutexName' => ExportCleanupScheduler::PORTABLE_MUTEX,
            'chainId' => 'owned-cleanup-handoff',
        ]);
        foreach (['pending', 'reserved', 'failed'] as $state) {
            $this->insertPayload(
                $this->serializeJob($handoff),
                fail: $state === 'failed',
                reserved: $state === 'reserved',
            );
        }

        $preservedIds = [
            $this->pushOwnedJob(new CleanupExportsJob(['reschedule' => false]), 50),
            $this->pushOwnedJob(new ProcessScheduledReportJob(['reportId' => 987654]), 50),
            $this->insertPayload('{"plugin":"another-plugin","class":"CleanupExportsJob","reschedule":true}'),
            $this->insertPayload('{"plugin":"reportmanager","class":"GenerateExportJob"}'),
        ];

        self::assertSame(9, $this->exportCleanupScheduler->cancel());
        self::assertSame(0, $this->countOwnerRows());
        self::assertSame([], $this->legacyRowIds());
        self::assertSame(
            array_map('intval', $preservedIds),
            array_map('intval', (new Query())->from('{{%queue}}')->where(['id' => $preservedIds])->orderBy(['id' => SORT_ASC])->column()),
        );
    }

    public function testBootstrapLifecycleContentionIsNonfatalAndLeavesRowsUnchanged(): void
    {
        $this->enableCleanup();
        $legacy = $this->serializeJob(new CleanupExportsJob(['reschedule' => true]));
        $this->insertPayload($legacy, delay: 100);
        $this->insertPayload($legacy, delay: 200);
        $before = $this->queueFingerprints();
        $mutex = new SelectiveCleanupMutex([ExportCleanupScheduler::LIFECYCLE_MUTEX]);
        $original = Craft::$app->getMutex();
        $logOffset = count(Craft::getLogger()->messages);
        Craft::$app->set('mutex', $mutex);

        try {
            $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame($before, $this->queueFingerprints());
        self::assertSame([ExportCleanupScheduler::LIFECYCLE_MUTEX], $mutex->acquisitions);
        self::assertSame([0], $mutex->timeouts);
        self::assertSame([], $mutex->releases);
        $this->assertBootstrapWarningLogged($logOffset, 'lifecycle');
    }

    public function testBootstrapPortableContentionIsNonfatalAndReleasesLifecycleWithoutInspectingRows(): void
    {
        $this->enableCleanup();
        $legacy = $this->serializeJob(new CleanupExportsJob(['reschedule' => true]));
        $this->insertPayload($legacy, delay: 100);
        $this->insertPayload($legacy, delay: 200);
        $before = $this->queueFingerprints();
        $mutex = new SelectiveCleanupMutex([ExportCleanupScheduler::PORTABLE_MUTEX]);
        $original = Craft::$app->getMutex();
        $logOffset = count(Craft::getLogger()->messages);
        Craft::$app->set('mutex', $mutex);

        try {
            $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame($before, $this->queueFingerprints());
        self::assertSame([
            ExportCleanupScheduler::LIFECYCLE_MUTEX,
            ExportCleanupScheduler::PORTABLE_MUTEX,
        ], $mutex->acquisitions);
        self::assertSame([0, 0], $mutex->timeouts);
        self::assertSame([ExportCleanupScheduler::LIFECYCLE_MUTEX], $mutex->releases);
        self::assertFalse($mutex->holds(ExportCleanupScheduler::LIFECYCLE_MUTEX));
        $this->assertBootstrapWarningLogged($logOffset, 'portable');
    }

    public function testLaterBootstrapReconcilesAfterContentionClears(): void
    {
        $this->enableCleanup();
        $mutex = new SelectiveCleanupMutex([ExportCleanupScheduler::LIFECYCLE_MUTEX]);
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame(0, $this->countOwnerRows());
        $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
        self::assertSame(1, $this->countOwnerRows());
    }

    public function testDisabledBootstrapCancelsUnderLifecycleThenPortableLocks(): void
    {
        $this->pushOwnedJob($this->recurringJob('owner'), 300);
        $mutex = new SelectiveCleanupMutex([]);
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);
        $this->settings()->autoCleanupExports = false;

        try {
            $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame(0, $this->countOwnerRows());
        self::assertSame([
            ExportCleanupScheduler::LIFECYCLE_MUTEX,
            ExportCleanupScheduler::PORTABLE_MUTEX,
        ], $mutex->acquisitions);
        self::assertSame([
            ExportCleanupScheduler::PORTABLE_MUTEX,
            ExportCleanupScheduler::LIFECYCLE_MUTEX,
        ], $mutex->releases);
        self::assertSame([0, 0], $mutex->timeouts);
    }

    public function testDisabledBootstrapRetriesCancellationAfterContentionClears(): void
    {
        $this->pushOwnedJob($this->recurringJob('owner'), 300);
        $this->settings()->autoCleanupExports = false;
        $mutex = new SelectiveCleanupMutex([ExportCleanupScheduler::PORTABLE_MUTEX]);
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame(1, $this->countOwnerRows());
        $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testIncompleteCancellationRemainsObservable(): void
    {
        $this->pushOwnedJob($this->recurringJob('cancellation-failure'), 300);
        $db = Craft::$app->getDb();
        $originalCommandClass = $db->commandClass;
        $db->commandClass = FailingCleanupDeleteCommand::class;
        FailingCleanupDeleteCommand::$failQueueDelete = true;

        try {
            $this->exportCleanupScheduler->cancel();
            self::fail('Expected incomplete cancellation to remain observable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Export-cleanup queue cancellation was incomplete.', $exception->getMessage());
        } finally {
            FailingCleanupDeleteCommand::$failQueueDelete = false;
            $db->commandClass = $originalCommandClass;
        }

        self::assertSame(1, $this->countOwnerRows());
    }

    public function testBootstrapCancellationFailureAfterLockAcquisitionPropagates(): void
    {
        $this->pushOwnedJob($this->recurringJob('bootstrap-cancellation-failure'), 300);
        $this->settings()->autoCleanupExports = false;
        $db = Craft::$app->getDb();
        $originalCommandClass = $db->commandClass;
        $db->commandClass = FailingCleanupDeleteCommand::class;
        FailingCleanupDeleteCommand::$failQueueDelete = true;

        try {
            $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
            self::fail('Expected bootstrap cancellation failure to remain observable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Export-cleanup queue cancellation was incomplete.', $exception->getMessage());
        } finally {
            FailingCleanupDeleteCommand::$failQueueDelete = false;
            $db->commandClass = $originalCommandClass;
        }

        self::assertSame(1, $this->countOwnerRows());
    }

    public function testBootstrapInspectionFailureAfterLockAcquisitionPropagates(): void
    {
        $this->enableCleanup();
        $db = Craft::$app->getDb();
        $originalCommandClass = $db->commandClass;
        $db->commandClass = FailingCleanupDeleteCommand::class;
        FailingCleanupDeleteCommand::$failQueueInspection = true;

        try {
            $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
            self::fail('Expected bootstrap inspection failure to remain observable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Export cleanup inspection failed.', $exception->getMessage());
        } finally {
            FailingCleanupDeleteCommand::$failQueueInspection = false;
            $db->commandClass = $originalCommandClass;
        }
    }

    public function testPortableLockFailureLeavesRowsUninspectedAndUnchanged(): void
    {
        $this->enableCleanup();
        $legacy = $this->serializeJob(new CleanupExportsJob(['reschedule' => true]));
        $ids = [$this->insertPayload($legacy, delay: 100), $this->insertPayload($legacy, delay: 200)];
        $mutex = new SelectiveCleanupMutex([ExportCleanupScheduler::PORTABLE_MUTEX]);
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            $this->exportCleanupScheduler->synchronize($this->settings());
            self::fail('Expected portable mutex failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to acquire the export-cleanup portable lock.', $exception->getMessage());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame($ids, $this->legacyRowIds());
        self::assertSame([
            ExportCleanupScheduler::LIFECYCLE_MUTEX,
            ExportCleanupScheduler::PORTABLE_MUTEX,
        ], $mutex->acquisitions);
        self::assertSame([5, 5], $mutex->timeouts);
    }

    public function testExplicitSynchronizationRemainsStrictOnLifecycleContention(): void
    {
        $this->enableCleanup();
        $mutex = new SelectiveCleanupMutex([ExportCleanupScheduler::LIFECYCLE_MUTEX]);
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            $this->exportCleanupScheduler->synchronize($this->settings());
            self::fail('Expected lifecycle lock failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to acquire the export-cleanup lifecycle lock.', $exception->getMessage());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame([5], $mutex->timeouts);
    }

    #[DataProvider('strictLockContentionProvider')]
    public function testScheduleExportCleanupCompatibilityPathRemainsStrict(
        string $lockName,
        string $expectedMessage,
    ): void {
        $this->enableCleanup();
        $mutex = new SelectiveCleanupMutex([$lockName]);
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            ReportManager::$plugin->scheduleExportCleanupJob(checkExisting: true);
            self::fail('Expected compatibility scheduling lock failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertContains(5, $mutex->timeouts);
    }

    #[DataProvider('strictLockContentionProvider')]
    public function testSettingsReplacementRemainsStrictDuringLockContention(
        string $lockName,
        string $expectedMessage,
    ): void {
        $this->enableCleanup();
        $mutex = new SelectiveCleanupMutex([$lockName]);
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            $this->exportCleanupScheduler->replaceIfChanged($this->settings(), false);
            self::fail('Expected settings replacement lock failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame(0, $this->countOwnerRows());
        self::assertContains(5, $mutex->timeouts);
    }

    #[DataProvider('strictLockContentionProvider')]
    public function testExplicitCancellationRemainsStrictDuringLockContention(
        string $lockName,
        string $expectedMessage,
    ): void {
        $this->pushOwnedJob($this->recurringJob('strict-cancellation'), 300);
        $mutex = new SelectiveCleanupMutex([$lockName]);
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            $this->exportCleanupScheduler->cancel();
            self::fail('Expected explicit cancellation lock failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame(1, $this->countOwnerRows());
        self::assertContains(5, $mutex->timeouts);
    }

    /** @return iterable<string, array{string, string}> */
    public static function strictLockContentionProvider(): iterable
    {
        yield 'lifecycle lock' => [
            ExportCleanupScheduler::LIFECYCLE_MUTEX,
            'Unable to acquire the export-cleanup lifecycle lock.',
        ];
        yield 'portable lock' => [
            ExportCleanupScheduler::PORTABLE_MUTEX,
            'Unable to acquire the export-cleanup portable lock.',
        ];
    }

    public function testCanonicalTargetDoesNotShiftWhileWaitingForThePortableLock(): void
    {
        $this->installPortableQueue(true);
        $this->enableCleanup();
        $target = $this->exportCleanupScheduler->getNextRun($this->settings());
        self::assertNotNull($target);
        $this->pauseAt($target->getTimestamp() - 2_000);
        $mutex = new SelectiveCleanupMutex([], $target->getTimestamp() - 1_000);
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        $handoff = $this->unserializeJob($this->onlyOwnerRow());
        self::assertInstanceOf(DeferredQueueJob::class, $handoff);
        self::assertSame($target->getTimestamp(), $handoff->targetTimestamp);
        self::assertSame([0, 0], $mutex->timeouts);
    }

    public function testDeferredContinuationUsesTheCleanupPortableMutex(): void
    {
        $this->installPortableQueue(true);
        $this->enableCleanup();
        $this->exportCleanupScheduler->synchronize($this->settings());
        $row = $this->onlyOwnerRow();
        $handoff = $this->unserializeJob($row);

        self::assertInstanceOf(DeferredQueueJob::class, $handoff);
        self::assertSame(ExportCleanupScheduler::PORTABLE_MUTEX, $handoff->mutexName);
        self::assertSame($this->identityTokens(), $handoff->identityTokens);
        self::assertSame(1024, $handoff->priority);
        self::assertSame(1800, $handoff->ttr);
    }

    public function testEnableDisableReenableAndPositiveRetentionChangesAvoidChurn(): void
    {
        $this->enableCleanup();
        $this->exportCleanupScheduler->synchronize($this->settings());
        $firstId = $this->onlyOwnerRow()['id'];

        $this->settings()->exportRetention = 90;
        self::assertFalse($this->exportCleanupScheduler->replaceIfChanged($this->settings(), true));
        self::assertSame((string)$firstId, (string)$this->onlyOwnerRow()['id']);

        $this->settings()->exportRetention = 0;
        self::assertTrue($this->exportCleanupScheduler->replaceIfChanged($this->settings(), true));
        self::assertSame(0, $this->countOwnerRows());

        self::assertFalse($this->exportCleanupScheduler->replaceIfChanged($this->settings(), false));
        $this->settings()->exportRetention = 30;
        self::assertTrue($this->exportCleanupScheduler->replaceIfChanged($this->settings(), false));
        self::assertSame(1, $this->countOwnerRows());
    }

    public function testConfigOverridesControlTheEffectiveSettingsTransition(): void
    {
        $this->enableCleanup();
        $this->exportCleanupScheduler->synchronize($this->settings());
        $config = $this->createMock(Config::class);
        $config->method('getConfigFromFile')->willReturnCallback(
            static fn(string $handle): array => $handle === 'report-manager'
                ? ['autoCleanupExports' => false, 'exportRetention' => 30]
                : [],
        );
        Craft::$app->set('config', $config);

        $saved = clone $this->settings();
        PluginHelper::applyConfigOverridesToSettings($saved, 'report-manager');
        self::assertTrue($this->exportCleanupScheduler->replaceIfChanged($saved, true));

        self::assertFalse($saved->autoCleanupExports);
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testSettingsControllerReconcilesReloadedConfigAwareStateBeforeSuccessNotice(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/SettingsController.php');
        self::assertIsString($source);

        $oldState = strpos($source, '$exportCleanupWasEnabled = $plugin->exportCleanupScheduler->isEnabled($plugin->getSettings());');
        $save = strpos($source, 'if (!$settings->saveToDatabase($attributesToValidate))');
        $reload = strpos($source, '$savedSettings = Settings::loadFromDatabase();');
        $config = strpos($source, "PluginHelper::applyConfigOverridesToSettings(\$savedSettings, 'report-manager');");
        $replace = strpos($source, '$plugin->exportCleanupScheduler->replaceIfChanged($savedSettings, $exportCleanupWasEnabled);');
        $notice = strpos($source, "Craft::\$app->getSession()->setNotice(Craft::t('report-manager', 'Settings saved.'));", $save ?: 0);

        foreach ([$oldState, $save, $reload, $config, $replace, $notice] as $position) {
            self::assertIsInt($position);
        }
        self::assertTrue($oldState < $save && $save < $reload && $reload < $config && $config < $replace && $replace < $notice);
    }

    public function testDisabledReservedRecurringConsumerDoesNotCleanupOrReschedule(): void
    {
        $exports = new RecordingExportService();
        $this->swapPluginComponent('report-manager', 'exports', $exports);
        $this->settings()->autoCleanupExports = false;
        $this->settings()->exportRetention = 30;
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);
        $jobId = $this->pushOwnedJob($this->recurringJob('disabled'));

        self::assertTrue($queue->executeJob((string)$jobId));

        self::assertSame(0, $exports->cleanupCalls);
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testSuccessfulCleanupCreatesExactlyOneSuccessor(): void
    {
        $exports = new RecordingExportService();
        $exports->deletedCount = 3;
        $this->swapPluginComponent('report-manager', 'exports', $exports);
        $this->enableCleanup();
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);
        $jobId = $this->pushOwnedJob($this->recurringJob('success'));

        self::assertTrue($queue->executeJob((string)$jobId));

        self::assertSame(1, $exports->cleanupCalls);
        self::assertSame(1, $this->countOwnerRows());
    }

    public function testCleanupRetainsLifecycleOwnershipThroughoutExecution(): void
    {
        $mutex = new SelectiveCleanupMutex([]);
        $exports = new RecordingExportService();
        $exports->beforeCleanup = static function() use ($mutex): void {
            self::assertTrue($mutex->holds(ExportCleanupScheduler::LIFECYCLE_MUTEX));
            self::assertFalse($mutex->holds(ExportCleanupScheduler::PORTABLE_MUTEX));
        };
        $this->swapPluginComponent('report-manager', 'exports', $exports);
        $this->enableCleanup();
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            $this->recurringJob('lifecycle-ownership')->execute(Craft::$app->getQueue());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame(1, $exports->cleanupCalls);
        self::assertSame([
            ExportCleanupScheduler::PORTABLE_MUTEX,
            ExportCleanupScheduler::LIFECYCLE_MUTEX,
        ], $mutex->releases);
    }

    public function testReservedLegacyCleanupFinishesIntoOnePortableSuccessor(): void
    {
        $exports = new RecordingExportService();
        $this->swapPluginComponent('report-manager', 'exports', $exports);
        $this->enableCleanup();
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);
        $jobId = $this->pushOwnedJob(new CleanupExportsJob(['reschedule' => true]));

        self::assertTrue($queue->executeJob((string)$jobId));

        self::assertSame(1, $exports->cleanupCalls);
        self::assertSame([], $this->legacyRowIds());
        self::assertSame(1, $this->countOwnerRows());
    }

    public function testSuccessfulOccurrenceKeepsItsTargetWhenPortableLockAcquisitionAdvancesTime(): void
    {
        $this->installPortableQueue(true);
        $exports = new RecordingExportService();
        $this->swapPluginComponent('report-manager', 'exports', $exports);
        $this->enableCleanup();
        $target = $this->exportCleanupScheduler->getNextRun($this->settings());
        self::assertNotNull($target);
        $this->pauseAt($target->getTimestamp() - 2_000);
        $mutex = new SelectiveCleanupMutex([], $target->getTimestamp() - 1_000);
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            $this->recurringJob('occurrence')->execute(Craft::$app->getQueue());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        $handoff = $this->unserializeJob($this->onlyOwnerRow());
        self::assertInstanceOf(DeferredQueueJob::class, $handoff);
        self::assertSame($target->getTimestamp(), $handoff->targetTimestamp);
        self::assertSame([
            ExportCleanupScheduler::LIFECYCLE_MUTEX,
            ExportCleanupScheduler::PORTABLE_MUTEX,
        ], $mutex->acquisitions);
    }

    public function testPortableLockFailureAfterCleanupPropagatesWithoutASuccessor(): void
    {
        $exports = new RecordingExportService();
        $this->swapPluginComponent('report-manager', 'exports', $exports);
        $this->enableCleanup();
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', new SelectiveCleanupMutex([ExportCleanupScheduler::PORTABLE_MUTEX]));

        try {
            $this->recurringJob('lock-failure')->execute(Craft::$app->getQueue());
            self::fail('Expected portable lock failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to acquire the export-cleanup portable lock.', $exception->getMessage());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        self::assertSame(1, $exports->cleanupCalls);
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testCleanupFailurePropagatesWithoutCreatingASuccessor(): void
    {
        $exports = new RecordingExportService();
        $exports->failure = new \RuntimeException('Generated export cleanup failed.');
        $this->swapPluginComponent('report-manager', 'exports', $exports);
        $this->enableCleanup();

        try {
            $this->recurringJob('failure')->execute(Craft::$app->getQueue());
            self::fail('Expected cleanup failure to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Generated export cleanup failed.', $exception->getMessage());
        }

        self::assertSame(1, $exports->cleanupCalls);
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testOneShotCleanupRunsWithoutJoiningTheRecurringFamily(): void
    {
        $exports = new RecordingExportService();
        $this->swapPluginComponent('report-manager', 'exports', $exports);
        $this->enableCleanup();

        (new CleanupExportsJob(['reschedule' => false]))->execute(Craft::$app->getQueue());

        self::assertSame(1, $exports->cleanupCalls);
        self::assertSame(0, $this->countOwnerRows());
    }

    public function testLockAndPushFailuresRemainObservable(): void
    {
        $this->enableCleanup();
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', new SelectiveCleanupMutex([ExportCleanupScheduler::LIFECYCLE_MUTEX]));
        try {
            $this->exportCleanupScheduler->synchronize($this->settings());
            self::fail('Expected lifecycle lock failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to acquire the export-cleanup lifecycle lock.', $exception->getMessage());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        $this->installPortableQueue(true);
        self::assertNotNull($this->proxyQueue);
        $this->proxyQueue->failPushes = true;
        try {
            $this->exportCleanupScheduler->synchronize($this->settings());
            self::fail('Expected proxy push failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Export cleanup proxy failure.', $exception->getMessage());
        }
        self::assertSame(1, $this->countOwnerRows());
    }

    public function testBootstrapPushFailureAfterLockAcquisitionPropagates(): void
    {
        $this->enableCleanup();
        $this->installPortableQueue(true);
        self::assertNotNull($this->proxyQueue);
        $this->proxyQueue->failPushes = true;

        try {
            $this->exportCleanupScheduler->synchronizeOnBootstrap($this->settings());
            self::fail('Expected bootstrap proxy push failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Export cleanup proxy failure.', $exception->getMessage());
        }

        self::assertSame(1, $this->countOwnerRows());
    }

    public function testPluginBootstrapUsesOnlyTheOpportunisticReconciliationEntryPoint(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/ReportManager.php');
        self::assertIsString($source);

        self::assertSame(1, substr_count($source, '$this->exportCleanupScheduler->synchronizeOnBootstrap();'));
        self::assertSame(1, substr_count($source, '$this->exportCleanupScheduler->synchronize($settings, $nextRun);'));
    }

    public function testRuntimeHasNoCloudDependencyOrFilesystemPortabilityClaim(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = file_get_contents($root . '/src/services/ExportCleanupScheduler.php')
            . file_get_contents($root . '/src/jobs/CleanupExportsJob.php');
        $composer = file_get_contents($root . '/composer.json');

        self::assertIsString($runtime);
        self::assertIsString($composer);
        self::assertStringNotContainsString('craft\\cloud', $runtime);
        self::assertStringNotContainsString('craftcms/cloud', $composer);
        self::assertStringNotContainsString('AWS_', $runtime);
        self::assertStringNotContainsString('CLOUD_', $runtime);
    }

    private function enableCleanup(): void
    {
        $this->settings()->autoCleanupExports = true;
        $this->settings()->exportRetention = 30;
    }

    private function installPortableQueue(bool $bounded, bool $unknown = false): Queue
    {
        $current = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $current);
        $this->proxyQueue = $bounded
            ? new RecordingCleanupSqsQueue()
            : ($unknown ? new RecordingUnknownQueue() : null);
        $queue = new Queue([
            'db' => Craft::$app->getDb(),
            'mutex' => Craft::$app->getMutex(),
            'tableName' => $current->tableName,
            'channel' => $current->channel,
            'mutexTimeout' => $current->mutexTimeout,
            'proxyQueue' => $this->proxyQueue,
        ]);
        Craft::$app->set('queue', $queue);
        $installed = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $installed);

        return $installed;
    }

    private function pauseAt(int $timestamp): void
    {
        if ($this->timePaused) {
            DateTimeHelper::resume();
        }
        DateTimeHelper::pause(new \DateTime("@$timestamp"));
        $this->timePaused = true;
    }

    private function recurringJob(string $nextRunTime): CleanupExportsJob
    {
        return new CleanupExportsJob([
            'reschedule' => true,
            'recurringOwner' => ExportCleanupScheduler::RECURRING_OWNER,
            'nextRunTime' => $nextRunTime,
        ]);
    }

    /** @return non-empty-list<string> */
    private function identityTokens(): array
    {
        return [
            ExportCleanupScheduler::PLUGIN_TOKEN,
            'CleanupExportsJob',
            ExportCleanupScheduler::RECURRING_OWNER,
        ];
    }

    /** @return array<string, mixed> */
    private function onlyOwnerRow(): array
    {
        $rows = $this->ownerQuery()->orderBy(['id' => SORT_ASC])->all();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function countOwnerRows(): int
    {
        return (int)$this->ownerQuery()->count();
    }

    private function ownerQuery(): Query
    {
        return (new Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', ExportCleanupScheduler::PLUGIN_TOKEN])
            ->andWhere(['like', 'job', 'CleanupExportsJob'])
            ->andWhere(['like', 'job', ExportCleanupScheduler::RECURRING_OWNER]);
    }

    /** @return list<int> */
    private function legacyRowIds(): array
    {
        $ids = [];
        $rows = (new Query())
            ->from('{{%queue}}')
            ->select(['id', 'job'])
            ->where(['like', 'job', ExportCleanupScheduler::PLUGIN_TOKEN])
            ->andWhere(['like', 'job', 'CleanupExportsJob'])
            ->andWhere(['not like', 'job', ExportCleanupScheduler::RECURRING_OWNER])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($rows as $row) {
            $payload = (string)$row['job'];
            if (str_contains($payload, 's:10:"reschedule";b:1;')
                || preg_match('/"reschedule"\s*:\s*true/', $payload) === 1
            ) {
                $ids[] = (int)$row['id'];
            }
        }

        return $ids;
    }

    private function serializeJob(object $job): string
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);

        return $queue->serializer->serialize($job);
    }

    /** @param array<string, mixed> $row */
    private function unserializeJob(array $row): object
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);
        $job = $queue->serializer->unserialize((string)$row['job']);
        self::assertIsObject($job);

        return $job;
    }

    private function insertPayload(
        string $payload,
        int $delay = 300,
        bool $fail = false,
        bool $reserved = false,
    ): int {
        Craft::$app->getDb()->createCommand()->insert('{{%queue}}', [
            'channel' => 'queue',
            'job' => $payload,
            'description' => 'Report Manager queue test row',
            'timePushed' => DateTimeHelper::currentTimeStamp(),
            'ttr' => 1800,
            'delay' => $delay,
            'priority' => 1024,
            'dateReserved' => $reserved ? new \yii\db\Expression('NOW()') : null,
            'timeUpdated' => $reserved ? DateTimeHelper::currentTimeStamp() : null,
            'fail' => $fail,
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /** @return list<array<string, int|string|null>> */
    private function queueFingerprints(): array
    {
        $rows = (new Query())
            ->from('{{%queue}}')
            ->select(['id', 'job', 'timePushed', 'delay', 'priority', 'ttr', 'timeUpdated', 'fail'])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'payloadHash' => hash('sha256', (string)$row['job']),
            'timePushed' => (int)$row['timePushed'],
            'delay' => (int)$row['delay'],
            'priority' => (int)$row['priority'],
            'ttr' => (int)$row['ttr'],
            'timeUpdated' => $row['timeUpdated'] === null ? null : (int)$row['timeUpdated'],
            'fail' => (int)$row['fail'],
        ], $rows);
    }

    private function assertBootstrapWarningLogged(int $offset, string $lock): void
    {
        $messages = array_slice(Craft::getLogger()->messages, $offset);
        $matching = array_filter(
            $messages,
            static fn(array $message): bool => ($message[2] ?? null) === 'report-manager'
                && str_contains((string)($message[0] ?? ''), "the $lock lock is busy")
                && str_contains((string)($message[0] ?? ''), 'a later request will retry'),
        );

        self::assertNotEmpty($matching);
    }

    /** @return list<int> */
    private function proxyDelays(): array
    {
        return $this->proxyQueue === null ? [] : array_column($this->proxyQueue->pushes, 'delay');
    }
}

/** Records bounded proxy pushes without contacting a provider. */
final class RecordingCleanupSqsQueue extends SqsQueue
{
    /** @var list<array{delay: int, priority: mixed, ttr: int}> */
    public array $pushes = [];
    public bool $failPushes = false;

    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        if ($this->failPushes) {
            throw new \RuntimeException('Export cleanup proxy failure.');
        }

        $this->pushes[] = [
            'delay' => (int)$delay,
            'priority' => $priority,
            'ttr' => (int)$ttr,
        ];

        return 'export-cleanup-proxy-' . count($this->pushes);
    }
}

/** Records an unbounded non-SQS proxy without contacting a provider. */
final class RecordingUnknownQueue extends YiiQueue
{
    /** @var list<array{delay: int, priority: mixed, ttr: int}> */
    public array $pushes = [];

    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        $this->pushes[] = [
            'delay' => (int)$delay,
            'priority' => $priority,
            'ttr' => (int)$ttr,
        ];

        return 'unknown-export-cleanup-proxy-' . count($this->pushes);
    }

    public function status($id): int
    {
        return self::STATUS_WAITING;
    }
}

/** Simulates a database driver reporting an incomplete exact queue deletion. */
final class FailingCleanupDeleteCommand extends CraftCommand
{
    public static bool $failQueueDelete = false;
    public static bool $failQueueInspection = false;

    public function execute()
    {
        if (self::$failQueueDelete && str_contains($this->getRawSql(), 'DELETE FROM `queue`')) {
            return 0;
        }

        return parent::execute();
    }

    protected function queryInternal($method, $fetchMode = null)
    {
        if (self::$failQueueInspection && str_contains($this->getRawSql(), 'FROM `queue`')) {
            throw new \RuntimeException('Export cleanup inspection failed.');
        }

        return parent::queryInternal($method, $fetchMode);
    }
}

/** Export seam that never reads or deletes generated export records or files. */
final class RecordingExportService extends ExportService
{
    public int $cleanupCalls = 0;
    public int $deletedCount = 0;
    public ?\Throwable $failure = null;
    public ?\Closure $beforeCleanup = null;

    public function cleanupOldExports(): int
    {
        $this->cleanupCalls++;
        ($this->beforeCleanup ?? static function(): void {
        })();
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->deletedCount;
    }
}

/** Mutex seam that fails only explicitly named locks. */
final class SelectiveCleanupMutex extends Mutex
{
    /** @var list<string> */
    public array $acquisitions = [];
    /** @var list<string> */
    public array $releases = [];
    /** @var list<int> */
    public array $timeouts = [];
    /** @var list<string> */
    private array $heldNames = [];

    /** @param list<string> $failedNames */
    public function __construct(
        private readonly array $failedNames,
        private readonly ?int $portableTimestamp = null,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    protected function acquireLock($name, $timeout = 0): bool
    {
        $this->acquisitions[] = (string)$name;
        $this->timeouts[] = (int)$timeout;
        if ($name === ExportCleanupScheduler::PORTABLE_MUTEX && $this->portableTimestamp !== null) {
            DateTimeHelper::resume();
            DateTimeHelper::pause(new \DateTime('@' . $this->portableTimestamp));
        }

        if (in_array($name, $this->failedNames, true)) {
            return false;
        }

        $this->heldNames[] = (string)$name;

        return true;
    }

    protected function releaseLock($name): bool
    {
        $this->releases[] = (string)$name;
        $this->heldNames = array_values(array_filter(
            $this->heldNames,
            static fn(string $heldName): bool => $heldName !== (string)$name,
        ));

        return true;
    }

    public function holds(string $name): bool
    {
        return in_array($name, $this->heldNames, true);
    }
}

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
use craft\queue\Queue;
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\jobs\GenerateExportJob;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\tests\Stubs\StubLargeExportDataSource;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;
use yii\queue\Queue as YiiQueue;

/**
 * Generation jobs enter the queue through one failure-safe operation.
 *
 * @since 5.6.0
 */
final class GenerationJobAdmissionTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installLargeExportDataSource();
        $this->storagePath = $this->createTrackedTempDirectory('report-generation-admission-');
        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $this->storagePath;
    }

    public function testNullQueueResultTerminallyFailsExportWithoutCreatingJob(): void
    {
        $queue = Craft::$app->getQueue();
        $queue->on(YiiQueue::EVENT_BEFORE_PUSH, static function($event): void {
            if ($event->job instanceof GenerateExportJob) {
                $event->handled = true;
            }
        });
        $export = $this->createStandardExport();

        self::assertFalse($this->exports->queueExportGeneration($export));

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(ExportRecord::STATUS_FAILED, $fresh->status);
        self::assertNotNull($fresh->completedAt);
        self::assertSame(
            'The export could not be queued. Check the Craft queue configuration and try again.',
            $fresh->errorMessage,
        );
        self::assertSame(0, $this->generationJobCount());
    }

    public function testExceptionBeforeQueueInsertionTerminallyFailsExportWithoutCreatingJob(): void
    {
        $queue = Craft::$app->getQueue();
        $queue->on(YiiQueue::EVENT_BEFORE_PUSH, static function($event): void {
            if ($event->job instanceof GenerateExportJob) {
                throw new \RuntimeException('Queue unavailable before insert.');
            }
        });
        $export = $this->createStandardExport();

        self::assertFalse($this->exports->queueExportGeneration($export));

        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(ExportRecord::STATUS_FAILED, $fresh->status);
        self::assertNotNull($fresh->completedAt);
        self::assertStringContainsString('Craft queue configuration', (string)$fresh->errorMessage);
        self::assertSame(0, $this->generationJobCount());
    }

    public function testProxyFailureAfterInsertionLeavesLateJobUnableToGenerate(): void
    {
        $queue = $this->installThrowingProxyQueue();
        $export = $this->createStandardExport();

        self::assertFalse($this->exports->queueExportGeneration($export));

        $row = (new Query())
            ->from($queue->tableName)
            ->where(['like', 'job', GenerateExportJob::class])
            ->one();
        self::assertIsArray($row);
        $fresh = ExportRecord::findOne($export->id);
        self::assertNotNull($fresh);
        self::assertSame(ExportRecord::STATUS_FAILED, $fresh->status);
        self::assertNotNull($fresh->completedAt);
        self::assertSame([], StubLargeExportDataSource::$exportRequests);

        self::assertTrue($queue->executeJob((string)$row['id']));

        $afterWorker = ExportRecord::findOne($export->id);
        self::assertNotNull($afterWorker);
        self::assertSame(ExportRecord::STATUS_FAILED, $afterWorker->status);
        self::assertSame([], StubLargeExportDataSource::$exportRequests);
        self::assertSame([], array_values(array_diff(scandir($this->storagePath) ?: [], ['.', '..'])));
    }

    public function testProviderCreationIsRecordOnlyAndExplicitAdmissionCreatesOneProviderJob(): void
    {
        $this->installStubProviderService();
        $export = $this->exports->createQueuedExport(
            providerHandle: StubQueuedExportProvider::handle(),
            format: 'csv',
            payload: ['caller' => self::MARKER . 'provider-caller'],
        );

        self::assertSame(ExportRecord::STATUS_PENDING, $export->status);
        self::assertSame(0, $this->generationJobCount());
        self::assertTrue($this->exports->queueExportGeneration($export));
        self::assertSame(1, $this->generationJobCount());

        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);
        $row = (new Query())
            ->from($queue->tableName)
            ->where(['like', 'job', GenerateExportJob::class])
            ->one();
        self::assertIsArray($row);
        $job = $queue->serializer->unserialize((string)$row['job']);
        self::assertInstanceOf(GenerateExportJob::class, $job);
        self::assertSame((int)$export->id, $job->exportId);
        self::assertFalse($job->combined);
    }

    private function createStandardExport(): ExportRecord
    {
        return $this->exports->createExport(
            StubLargeExportDataSource::handle(),
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            'csv',
        );
    }

    private function generationJobCount(): int
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);

        return (int)(new Query())
            ->from($queue->tableName)
            ->where(['like', 'job', GenerateExportJob::class])
            ->count();
    }

    private function installThrowingProxyQueue(): Queue
    {
        $current = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $current);
        $queue = new Queue([
            'db' => $current->db,
            'mutex' => $current->mutex,
            'tableName' => $current->tableName,
            'channel' => $current->channel,
            'mutexTimeout' => $current->mutexTimeout,
            'proxyQueue' => new ThrowingGenerationProxyQueue(),
        ]);
        Craft::$app->set('queue', $queue);

        return $queue;
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
        $this->exports = ReportManager::$plugin->exports;
    }
}

/** Simulates an external proxy rejecting a job after Craft persisted its row. */
final class ThrowingGenerationProxyQueue extends YiiQueue
{
    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        throw new \RuntimeException('Queue unavailable after insert.');
    }

    public function status($id): int
    {
        return self::STATUS_WAITING;
    }
}

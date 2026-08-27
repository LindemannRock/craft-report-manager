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
use craft\web\Request;
use craft\web\Response;
use lindemannrock\reportmanager\controllers\ExportsController;
use lindemannrock\reportmanager\controllers\ReportsController;
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\jobs\GenerateExportJob;
use lindemannrock\reportmanager\jobs\ProcessScheduledReportJob;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\services\ReportsService;
use lindemannrock\reportmanager\tests\Stubs\StubLargeExportDataSource;
use lindemannrock\reportmanager\tests\TestCase;
use yii\queue\Queue as YiiQueue;

/**
 * Every queued export producer reports and persists admission outcomes.
 *
 * @since 5.6.0
 */
final class QueuedProducerAdmissionTest extends TestCase
{
    private object $originalRequest;
    private object $originalResponse;
    private string $originalRequestMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installLargeExportDataSource();
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $admin = $this->createTestUser(self::MARKER . 'queue_producer_admin', ['admin' => true]);
        $admin->admin = true;
        self::assertTrue(Craft::$app->getElements()->saveElement($admin, false));
        $this->actingAs($admin);

        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $this->createTrackedTempDirectory('report-queued-producers-');
    }

    protected function tearDown(): void
    {
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('response', $this->originalResponse);
        $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        Craft::$app->getDb()->createCommand()->delete(ReportRecord::tableName(), [
            'handle' => 'rm_test_initial-scheduled',
        ])->execute();

        parent::tearDown();
    }

    public function testManualSeparateGenerationContinuesAndReturnsExactCounts(): void
    {
        $report = $this->makeReport('manual-separate', 'separate', false);
        $this->rejectGenerationAttempt(1);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        Craft::$app->getRequest()->setBodyParams(['reportId' => (int)$report->id]);

        $response = $this->reportsController()->actionGenerate();

        self::assertSame([
            'success' => false,
            'count' => 1,
            'queued' => 1,
            'failed' => 1,
        ], $response->data);
        $this->assertReportExportOutcomes($report, 1, 1);
        self::assertSame(1, $this->jobCount(GenerateExportJob::class));
    }

    public function testQueuedQuickExportReportsPartialAndCombinedFailureCounts(): void
    {
        $this->rejectGenerationAttempt(1);
        Craft::$app->getRequest()->setBodyParams($this->quickExportPayload('separate'));
        $separate = $this->exportsController();

        self::assertInstanceOf(Response::class, $separate->actionQuickExport());
        self::assertSame('Queued exports: 1; failed exports: 1.', $separate->error);
        self::assertSame(1, $this->jobCount(GenerateExportJob::class));
        $this->assertOwnedExportOutcomes(1, 1);

        $this->clearIsolatedQueueRows();
        $this->purgeTestRows();
        $this->rejectGenerationAttempt(1);
        Craft::$app->getRequest()->setBodyParams($this->quickExportPayload('combined'));
        $combined = $this->exportsController();

        self::assertInstanceOf(Response::class, $combined->actionQuickExport());
        self::assertSame('Queued exports: 0; failed exports: 1.', $combined->error);
        self::assertSame(0, $this->jobCount(GenerateExportJob::class));
        $this->assertOwnedExportOutcomes(0, 1);
    }

    public function testImmediateQuickExportDoesNotUseQueueAdmission(): void
    {
        $queue = Craft::$app->getQueue();
        $queue->on(YiiQueue::EVENT_BEFORE_PUSH, static function($event): void {
            if ($event->job instanceof GenerateExportJob) {
                throw new \RuntimeException('Immediate export unexpectedly entered the queue.');
            }
        });
        $payload = $this->quickExportPayload('combined');
        $payload['entityIds'] = [StubLargeExportDataSource::PRIMARY_ENTITY_ID];
        $payload['processImmediately'] = true;
        Craft::$app->getRequest()->setBodyParams($payload);
        $controller = $this->exportsController();

        self::assertInstanceOf(Response::class, $controller->actionQuickExport());
        self::assertSame('Combined export generated successfully.', $controller->notice);
        self::assertSame(0, $this->jobCount(GenerateExportJob::class));
        $this->assertOwnedExportOutcomes(0, 0, 1);
    }

    public function testInitialScheduledGenerationReportsCountsAndKeepsNormalSuccessor(): void
    {
        $this->settings()->enableScheduledReports = true;
        $report = $this->makeReport('initial-scheduled', 'separate', false);
        $reports = $this->installRecordingReportsService();
        $this->rejectGenerationAttempt(1);
        Craft::$app->getRequest()->setBodyParams($this->reportSavePayload($report));
        $controller = $this->reportsController();

        $response = $controller->actionSave();
        $routeReport = Craft::$app->getUrlManager()->getRouteParams()['report'] ?? null;
        self::assertInstanceOf(Response::class, $response, json_encode([
            'controllerError' => $controller->error,
            'reportErrors' => $routeReport instanceof ReportRecord ? $routeReport->getErrors() : null,
        ]));
        self::assertSame('Queued exports: 1; failed exports: 1.', $controller->error);
        $fresh = ReportRecord::findOne($report->id);
        self::assertNotNull($fresh);
        self::assertNotNull($fresh->lastGeneratedAt);
        $this->assertReportExportOutcomes($fresh, 1, 1);
        self::assertSame(1, $this->jobCount(GenerateExportJob::class));
        self::assertSame(1, $reports->scheduledJobCalls);
    }

    public function testRecurringScheduledGenerationAdvancesOnceAndKeepsOneSuccessor(): void
    {
        $this->settings()->enableScheduledReports = true;
        $report = $this->makeReport('recurring-scheduled', 'separate', true);
        $reports = $this->installRecordingReportsService();
        $previousDue = new \DateTime('-3 months');
        $previousDue->setTime(8, 45, 0);
        $report->nextScheduledAt = $previousDue;
        self::assertTrue($report->save(false));
        $report = ReportRecord::findOne($report->id);
        self::assertNotNull($report);
        $storedDue = $report->getAttribute('nextScheduledAt');
        $storedDue = $storedDue instanceof \DateTime ? $storedDue : new \DateTime((string)$storedDue, new \DateTimeZone('UTC'));
        $storedTime = $storedDue->format('H:i:s');
        $this->rejectGenerationAttempt(1);

        (new ProcessScheduledReportJob(['reportId' => (int)$report->id]))->execute(Craft::$app->getQueue());

        $fresh = ReportRecord::findOne($report->id);
        self::assertNotNull($fresh);
        $next = $fresh->getAttribute('nextScheduledAt');
        $next = $next instanceof \DateTime ? $next : new \DateTime((string)$next, new \DateTimeZone('UTC'));
        self::assertGreaterThan(new \DateTime(), $next);
        self::assertSame($storedTime, $next->format('H:i:s'));
        $this->assertReportExportOutcomes($fresh, 1, 1);
        self::assertSame(1, $this->jobCount(GenerateExportJob::class));
        self::assertSame(1, $reports->scheduledJobCalls);
    }

    private function rejectGenerationAttempt(int $attempt): void
    {
        $seen = 0;
        Craft::$app->getQueue()->on(YiiQueue::EVENT_BEFORE_PUSH, static function($event) use (&$seen, $attempt): void {
            if (!$event->job instanceof GenerateExportJob) {
                return;
            }

            $seen++;
            if ($seen === $attempt) {
                $event->handled = true;
            }
        });
    }

    private function makeReport(string $handle, string $mode, bool $schedule): ReportRecord
    {
        $report = new ReportRecord([
            'name' => self::MARKER . ' ' . $handle,
            'handle' => self::MARKER . $handle,
            'dataSource' => StubLargeExportDataSource::handle(),
            'dateRange' => 'all',
            'exportFormat' => 'csv',
            'exportMode' => $mode,
            'enableSchedule' => $schedule,
            'schedule' => $schedule ? 'monthly' : null,
            'nextScheduledAt' => $schedule ? new \DateTime('-1 day') : null,
            'enabled' => true,
            'sortOrder' => 0,
            'dateCreated' => new \DateTime(),
            'dateUpdated' => new \DateTime(),
        ]);
        $report->setEntityIdsArray([
            StubLargeExportDataSource::PRIMARY_ENTITY_ID,
            StubLargeExportDataSource::SECONDARY_ENTITY_ID,
        ]);
        self::assertTrue($report->save(false));

        return $report;
    }

    /** @return array<string, mixed> */
    private function quickExportPayload(string $mode): array
    {
        return [
            'dataSource' => StubLargeExportDataSource::handle(),
            'entityIds' => [
                StubLargeExportDataSource::PRIMARY_ENTITY_ID,
                StubLargeExportDataSource::SECONDARY_ENTITY_ID,
            ],
            'format' => 'csv',
            'dateRange' => 'all',
            'exportMode' => $mode,
            'siteIds' => [],
            'processImmediately' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function reportSavePayload(ReportRecord $report): array
    {
        return [
            'reportId' => (int)$report->id,
            'name' => $report->name,
            'handle' => $report->handle,
            'description' => '',
            'dataSource' => StubLargeExportDataSource::handle(),
            'entityIds' => [
                StubLargeExportDataSource::PRIMARY_ENTITY_ID,
                StubLargeExportDataSource::SECONDARY_ENTITY_ID,
            ],
            'siteIds' => [],
            'dateRange' => 'all',
            'dateField' => '',
            'fieldHandles' => [],
            'exportFormat' => 'csv',
            'exportMode' => 'separate',
            'enableSchedule' => true,
            'schedule' => 'monthly',
            'enabled' => true,
        ];
    }

    private function assertReportExportOutcomes(ReportRecord $report, int $pending, int $failed): void
    {
        $exports = ExportRecord::find()->where(['reportId' => $report->id])->all();
        self::assertCount($pending + $failed, $exports);
        $actualPending = 0;
        $actualFailed = 0;

        foreach ($exports as $export) {
            self::assertInstanceOf(ExportRecord::class, $export);
            $actualPending += $export->isPending() ? 1 : 0;
            $actualFailed += $export->isFailed() ? 1 : 0;

            if ($export->isFailed()) {
                self::assertNotNull($export->completedAt);
                self::assertStringContainsString('Craft queue configuration', (string)$export->errorMessage);
            }
        }

        self::assertSame($pending, $actualPending);
        self::assertSame($failed, $actualFailed);
    }

    private function assertOwnedExportOutcomes(int $pending, int $failed, int $completed = 0): void
    {
        $exports = ExportRecord::find()->where(['dataSource' => StubLargeExportDataSource::handle()])->all();
        self::assertCount($pending + $failed + $completed, $exports);
        $actual = ['pending' => 0, 'failed' => 0, 'completed' => 0];

        foreach ($exports as $export) {
            self::assertInstanceOf(ExportRecord::class, $export);
            $actual['pending'] += $export->isPending() ? 1 : 0;
            $actual['failed'] += $export->isFailed() ? 1 : 0;
            $actual['completed'] += $export->isCompleted() ? 1 : 0;
        }

        self::assertSame([
            'pending' => $pending,
            'failed' => $failed,
            'completed' => $completed,
        ], $actual);
    }

    private function jobCount(string $class): int
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);

        return (int)(new Query())
            ->from($queue->tableName)
            ->where(['like', 'job', $class])
            ->count();
    }

    private function reportsController(): AdmissionReportsController
    {
        return new AdmissionReportsController('reports', ReportManager::$plugin);
    }

    private function exportsController(): AdmissionExportsController
    {
        return new AdmissionExportsController('exports', ReportManager::$plugin);
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

    private function installRecordingReportsService(): RecordingAdmissionReportsService
    {
        $service = new RecordingAdmissionReportsService();
        $this->swapPluginComponent('report-manager', 'reports', $service);
        $this->reports = $service;

        return $service;
    }
}

final class RecordingAdmissionReportsService extends ReportsService
{
    public int $scheduledJobCalls = 0;

    public function queueScheduledReportJob(ReportRecord $report, bool $replaceExisting = true): bool
    {
        $this->scheduledJobCalls++;

        return true;
    }
}

final class AdmissionReportsController extends ReportsController
{
    public ?string $error = null;
    public ?string $notice = null;

    protected function setReportSessionError(string $message): void
    {
        $this->error = $message;
    }

    protected function setReportSessionNotice(string $message): void
    {
        $this->notice = $message;
    }
}

final class AdmissionExportsController extends ExportsController
{
    public ?string $error = null;
    public ?string $notice = null;

    protected function setSessionError(string $message): void
    {
        $this->error = $message;
    }

    protected function setSessionNotice(string $message): void
    {
        $this->notice = $message;
    }
}

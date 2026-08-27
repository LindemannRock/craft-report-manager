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
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\jobs\GenerateExportJob;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\tests\Stubs\StubLargeExportDataSource;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Quick Export custom-range validation and submitted-state behavior.
 *
 * @since 5.6.0
 */
#[CoversClass(ExportsController::class)]
final class QuickExportDateValidationTest extends TestCase
{
    private object $originalRequest;
    private object $originalResponse;
    private string $originalRequestMethod;
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installLargeExportDataSource();

        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $admin = $this->createTestUser(self::MARKER . 'quick_export_admin', ['admin' => true]);
        $admin->admin = true;
        self::assertTrue(Craft::$app->getElements()->saveElement($admin, false));
        $this->actingAs($admin);

        $this->storagePath = $this->createTrackedTempDirectory('report-quick-export-');
        $this->settings()->exportVolumeUid = '';
        $this->settings()->exportPath = $this->storagePath;
    }

    protected function tearDown(): void
    {
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('response', $this->originalResponse);
        $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;

        parent::tearDown();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function rejectedBranchProvider(): iterable
    {
        yield 'separate queued' => ['separate', false];
        yield 'separate immediate' => ['separate', true];
        yield 'combined queued' => ['combined', false];
        yield 'combined immediate' => ['combined', true];
    }

    #[DataProvider('rejectedBranchProvider')]
    public function testInvertedCustomPairStopsBeforeEveryCreationBranch(string $mode, bool $immediate): void
    {
        $before = $this->ownedState();
        Craft::$app->getRequest()->setBodyParams($this->payload(
            $mode,
            $immediate,
            '4/30/2026',
            '3/30/2026',
        ));

        $controller = $this->controller();
        $response = $controller->actionQuickExport();

        self::assertNull($response);
        self::assertSame($before, $this->ownedState());
        self::assertSame(
            'End date must be on or after the start date.',
            $controller->error,
        );
        $routeParams = Craft::$app->getUrlManager()->getRouteParams();
        self::assertSame('custom', $routeParams['submittedValues']['dateRange'] ?? null);
        self::assertSame($mode, $routeParams['submittedValues']['exportMode'] ?? null);
        self::assertSame(
            'End date must be on or after the start date.',
            $routeParams['customDateRangeError'] ?? null,
        );
    }

    public function testEqualAndOpenEndedCustomRangesRemainAccepted(): void
    {
        foreach ([
            'equal' => ['3/30/2026', '3/30/2026'],
            'start only' => ['3/30/2026', null],
            'end only' => [null, '3/30/2026'],
        ] as $label => [$start, $end]) {
            Craft::$app->getRequest()->setBodyParams($this->payload('combined', false, $start, $end));
            $beforeCount = $this->ownedExportCount();

            $response = $this->controller()->actionQuickExport();

            self::assertInstanceOf(Response::class, $response, $label);
            self::assertSame($beforeCount + 1, $this->ownedExportCount(), $label);
            $export = ExportRecord::find()->where(['dataSource' => StubLargeExportDataSource::handle()])->orderBy(['id' => SORT_DESC])->one();
            self::assertInstanceOf(ExportRecord::class, $export);
            self::assertSame('custom', $export->dateRangeUsed);
            self::assertSame($start !== null, $export->dateStartUsed !== null, $label);
            self::assertSame($end !== null, $export->dateEndUsed !== null, $label);
        }
    }

    public function testNamedQuickExportIgnoresPostedCustomBounds(): void
    {
        $payload = $this->payload('separate', false, '4/30/2026', '3/30/2026');
        $payload['dateRange'] = 'all';
        Craft::$app->getRequest()->setBodyParams($payload);

        self::assertInstanceOf(Response::class, $this->controller()->actionQuickExport());
        $export = ExportRecord::find()->where(['dataSource' => StubLargeExportDataSource::handle()])->orderBy(['id' => SORT_DESC])->one();
        self::assertInstanceOf(ExportRecord::class, $export);
        self::assertSame('all', $export->dateRangeUsed);
        self::assertNull($export->dateStartUsed);
        self::assertNull($export->dateEndUsed);
    }

    public function testQuickExportTemplateContainsEquivalentLiveTranslatedFeedback(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/templates/exports/new.twig');
        self::assertIsString($source);
        self::assertStringContainsString(
            "'End date must be on or after the start date.'|t('report-manager')",
            $source,
        );
        self::assertStringContainsString('start && end && start > end', $source);
        self::assertStringContainsString("errors: customDateRangeError ? [customDateRangeError] : []", $source);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $mode, bool $immediate, ?string $start, ?string $end): array
    {
        return [
            'dataSource' => StubLargeExportDataSource::handle(),
            'entityIds' => [StubLargeExportDataSource::PRIMARY_ENTITY_ID],
            'format' => 'csv',
            'dateRange' => 'custom',
            'customDateStart' => ['date' => $start],
            'customDateEnd' => ['date' => $end],
            'exportMode' => $mode,
            'siteIds' => [],
            'processImmediately' => $immediate,
        ];
    }

    /** @return array{exports: int, queue: int, files: list<string>} */
    private function ownedState(): array
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);

        return [
            'exports' => $this->ownedExportCount(),
            'queue' => (int)(new Query())
                ->from($queue->tableName)
                ->where(['like', 'job', GenerateExportJob::class])
                ->count(),
            'files' => array_values(array_diff(scandir($this->storagePath) ?: [], ['.', '..'])),
        ];
    }

    private function ownedExportCount(): int
    {
        return (int)ExportRecord::find()
            ->where(['dataSource' => StubLargeExportDataSource::handle()])
            ->count();
    }

    private function controller(): QuickExportTestController
    {
        return new QuickExportTestController('exports', ReportManager::$plugin);
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
    }
}

final class QuickExportTestController extends ExportsController
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

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
use craft\elements\User;
use craft\queue\Queue;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use DateTime;
use lindemannrock\reportmanager\controllers\DashboardController;
use lindemannrock\reportmanager\controllers\ReportsController;
use lindemannrock\reportmanager\jobs\GenerateExportJob;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Report capability gates and control-plane projection.
 *
 * @since 5.6.0
 */
#[CoversClass(ReportsController::class)]
#[CoversClass(DashboardController::class)]
final class ReportPermissionCapabilityTest extends TestCase
{
    private object $originalRequest;
    private object $originalResponse;
    private object $originalUser;
    private string $originalRequestMethod;
    private ReportRecord $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalUser = Craft::$app->getUser();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->purgeOwnedPermissionRows();

        $this->report = new ReportRecord([
            'name' => 'Permission Fixture',
            'handle' => self::MARKER . 'permission_fixture',
            'dataSource' => 'entries',
            'dateRange' => 'all',
            'exportFormat' => 'csv',
            'exportMode' => 'separate',
            'enableSchedule' => false,
            'enabled' => true,
            'sortOrder' => 0,
        ]);
        $this->report->setEntityIdsArray([]);
        self::assertTrue($this->report->save(false));
    }

    protected function tearDown(): void
    {
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('response', $this->originalResponse);
        Craft::$app->set('user', $this->originalUser);
        $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        $this->purgeOwnedPermissionRows();

        parent::tearDown();
    }

    public function testCreatorEditorParentAndAdministratorRoutesUseDistinctCapabilities(): void
    {
        $this->actWithPermissions([
            'reportManager:manageReports',
            'reportManager:createReports',
        ], false, 'creator');
        $newResponse = $this->reportsController()->actionEdit();
        self::assertTrue($newResponse->data['variables']['isNew'] ?? false);
        $this->assertForbidden(fn(): \yii\web\Response => $this->reportsController()->actionEdit((int)$this->report->id));

        $this->actWithPermissions([
            'reportManager:manageReports',
            'reportManager:editReports',
        ], false, 'editor');
        $editResponse = $this->reportsController()->actionEdit((int)$this->report->id);
        self::assertFalse($editResponse->data['variables']['isNew'] ?? true);
        $this->assertForbidden(fn(): \yii\web\Response => $this->reportsController()->actionEdit());

        $this->actWithPermissions(['reportManager:manageReports'], false, 'parent');
        $this->assertForbidden(fn(): \yii\web\Response => $this->reportsController()->actionEdit());
        $this->assertForbidden(fn(): \yii\web\Response => $this->reportsController()->actionEdit((int)$this->report->id));

        $this->actWithPermissions([], true, 'administrator');
        self::assertTrue($this->reportsController()->actionEdit()->data['variables']['isNew'] ?? false);
        self::assertFalse(
            $this->reportsController()->actionEdit((int)$this->report->id)->data['variables']['isNew'] ?? true,
        );
    }

    public function testChildPermissionsDoNotBypassTheParentSectionBoundary(): void
    {
        $this->actWithPermissions(['reportManager:createReports'], false, 'create-child-only');
        $this->assertForbidden(fn() => $this->reportsController()->runAction('edit'));

        $this->actWithPermissions(['reportManager:editReports'], false, 'edit-child-only');
        $this->assertForbidden(fn() => $this->reportsController()->runAction('edit', [
            'reportId' => (int)$this->report->id,
        ]));

        $this->actWithPermissions([
            'reportManager:manageReports',
            'reportManager:createReports',
        ], false, 'parent-and-create');
        self::assertInstanceOf(Response::class, $this->reportsController()->runAction('edit'));
    }

    public function testSuppliedZeroAndMissingIdsCannotCrossCreateAndEditBoundaries(): void
    {
        $before = $this->ownedState();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->actWithPermissions([
            'reportManager:manageReports',
            'reportManager:createReports',
        ], false, 'creator-zero');
        Craft::$app->getRequest()->setBodyParams(['reportId' => 0]);
        $this->assertForbidden(fn(): ?\yii\web\Response => $this->reportsController()->actionSave());
        self::assertSame($before, $this->ownedState());

        $this->actWithPermissions([
            'reportManager:manageReports',
            'reportManager:editReports',
        ], false, 'editor-missing');
        Craft::$app->getRequest()->setBodyParams([]);
        $this->assertForbidden(fn(): ?\yii\web\Response => $this->reportsController()->actionSave());
        self::assertSame($before, $this->ownedState());

        Craft::$app->getRequest()->setBodyParams(['reportId' => 987654321]);
        try {
            $this->reportsController()->actionSave();
            self::fail('A supplied nonexistent ID must stay on the edit path.');
        } catch (NotFoundHttpException) {
            self::assertSame($before, $this->ownedState());
        }
    }

    public function testOperationSpecificControlsProjectOnlyTheirExactCapabilities(): void
    {
        $cases = [
            'editor only' => [
                ['reportManager:manageReports', 'reportManager:editReports'],
                ['canDelete' => false, 'canGenerate' => false, 'canAccessGeneratedFiles' => false],
            ],
            'delete operation' => [
                ['reportManager:manageReports', 'reportManager:editReports', 'reportManager:deleteReports'],
                ['canDelete' => true, 'canGenerate' => false, 'canAccessGeneratedFiles' => false],
            ],
            'generate operation' => [
                [
                    'reportManager:manageReports',
                    'reportManager:editReports',
                    'reportManager:manageExports',
                    'reportManager:createExports',
                ],
                ['canDelete' => false, 'canGenerate' => true, 'canAccessGeneratedFiles' => true],
            ],
            'generated view operation' => [
                ['reportManager:manageReports', 'reportManager:editReports', 'reportManager:manageExports'],
                ['canDelete' => false, 'canGenerate' => false, 'canAccessGeneratedFiles' => true],
            ],
            'full capability' => [
                [
                    'reportManager:manageReports',
                    'reportManager:createReports',
                    'reportManager:editReports',
                    'reportManager:deleteReports',
                    'reportManager:manageExports',
                    'reportManager:createExports',
                ],
                ['canDelete' => true, 'canGenerate' => true, 'canAccessGeneratedFiles' => true],
            ],
        ];

        foreach ($cases as $label => [$permissions, $expected]) {
            $this->actWithPermissions($permissions, false, $label);
            $variables = $this->reportsController()
                ->actionEdit((int)$this->report->id)
                ->data['variables'];

            foreach ($expected as $capability => $allowed) {
                self::assertSame($allowed, $variables[$capability] ?? null, "{$label}: {$capability}");
            }
        }

        $this->actWithPermissions([], true, 'administrator-controls');
        $variables = $this->reportsController()
            ->actionEdit((int)$this->report->id)
            ->data['variables'];
        self::assertTrue($variables['canDelete'] ?? false);
        self::assertTrue($variables['canGenerate'] ?? false);
        self::assertTrue($variables['canAccessGeneratedFiles'] ?? false);
    }

    public function testDashboardNewReportProjectionRequiresCreatePermission(): void
    {
        $this->actWithPermissions([
            'reportManager:viewDashboard',
            'reportManager:manageReports',
        ], false, 'dashboard-parent');
        $variables = $this->dashboardController()->actionIndex()->data['variables'];
        self::assertFalse($variables['canCreateReports'] ?? true);

        $this->actWithPermissions([
            'reportManager:viewDashboard',
            'reportManager:manageReports',
            'reportManager:createReports',
        ], false, 'dashboard-creator');
        $variables = $this->dashboardController()->actionIndex()->data['variables'];
        self::assertTrue($variables['canCreateReports'] ?? false);
    }

    public function testReportEditTemplateConsumesExactOperationProjection(): void
    {
        $this->actWithPermissions([
            'reportManager:manageReports',
            'reportManager:editReports',
        ], false, 'template-editor');
        $html = $this->renderCaptured(
            $this->reportsController()->actionEdit((int)$this->report->id),
        );
        self::assertStringNotContainsString('report-manager/reports/delete', $html);
        self::assertStringNotContainsString('id="generate-now-btn"', $html);
        self::assertStringNotContainsString(
            'report-manager/reports/' . $this->report->id . '/generated',
            $html,
        );

        $this->actWithPermissions([
            'reportManager:manageReports',
            'reportManager:editReports',
            'reportManager:deleteReports',
            'reportManager:manageExports',
            'reportManager:createExports',
        ], false, 'template-full');
        $html = $this->renderCaptured(
            $this->reportsController()->actionEdit((int)$this->report->id),
        );
        self::assertStringContainsString('report-manager/reports/delete', $html);
        self::assertStringContainsString('id="generate-now-btn"', $html);
        self::assertStringContainsString(
            'report-manager/reports/' . $this->report->id . '/generated',
            $html,
        );
    }

    public function testSavingNamedRangeClearsPreviouslyStoredCustomBounds(): void
    {
        $this->report->dateRange = 'custom';
        $this->report->customDateStart = new DateTime('2026-03-30 00:00:00');
        $this->report->customDateEnd = new DateTime('2026-04-30 23:59:59');
        $this->report->setEntityIdsArray([1]);
        self::assertTrue($this->report->save(false));

        $this->actWithPermissions([
            'reportManager:manageReports',
            'reportManager:editReports',
        ], false, 'named-range-save');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams([
            'reportId' => (int)$this->report->id,
            'name' => $this->report->name,
            'handle' => self::MARKER . 'permission_renamed',
            'description' => '',
            'dataSource' => 'entries',
            'entityIds' => [1],
            'siteIds' => [],
            'dateRange' => 'all',
            'customDateStart' => ['date' => '4/30/2026'],
            'customDateEnd' => ['date' => '3/30/2026'],
            'dateField' => '',
            'fieldHandles' => [],
            'exportFormat' => 'csv',
            'exportMode' => 'separate',
            'enabled' => true,
        ]);

        $response = $this->reportsController()->actionSave();

        $failedReport = Craft::$app->getUrlManager()->getRouteParams()['report'] ?? null;
        self::assertInstanceOf(
            Response::class,
            $response,
            $failedReport instanceof ReportRecord ? json_encode($failedReport->getErrors()) : '',
        );
        $fresh = ReportRecord::findOne($this->report->id);
        self::assertNotNull($fresh);
        self::assertSame('all', $fresh->dateRange);
        self::assertNull($fresh->customDateStart);
        self::assertNull($fresh->customDateEnd);
    }

    public function testManualSeparateAndCombinedGenerationCannotCarrySavedNamedBounds(): void
    {
        $this->actWithPermissions([], true, 'manual-generation');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');

        foreach (['separate', 'combined'] as $mode) {
            $this->report->dataSource = 'entries';
            $this->report->dateRange = 'all';
            $this->report->customDateStart = new DateTime('2001-01-01 00:00:00');
            $this->report->customDateEnd = new DateTime('2099-12-31 23:59:59');
            $this->report->exportMode = $mode;
            $this->report->setEntityIdsArray([1]);
            self::assertTrue($this->report->save(false));
            Craft::$app->getRequest()->setBodyParams(['reportId' => (int)$this->report->id]);

            $beforeIds = ExportRecord::find()->select('id')->column();
            $response = $this->reportsController()->actionGenerate();

            self::assertSame(200, $response->getStatusCode());
            $exports = ExportRecord::find()
                ->where(['reportId' => (int)$this->report->id])
                ->andWhere(['not in', 'id', $beforeIds ?: [0]])
                ->all();
            self::assertNotEmpty($exports, $mode);
            foreach ($exports as $export) {
                self::assertInstanceOf(ExportRecord::class, $export);
                self::assertNull($export->dateStartUsed, $mode);
                self::assertNull($export->dateEndUsed, $mode);
            }
        }
    }

    /** @param list<string> $permissions */
    private function actWithPermissions(array $permissions, bool $admin, string $suffix): void
    {
        $user = $this->createTestUser(self::MARKER . $suffix, ['admin' => $admin]);
        if ($admin) {
            $user->admin = true;
            self::assertTrue(Craft::$app->getElements()->saveElement($user, false));
        }
        $this->grantPermissions($user, array_merge(['accessCp'], $permissions));
        $this->actingAs($user);

        $webUser = new class() extends \craft\console\User {
            public function getRemainingSessionTime(): int
            {
                return -1;
            }

            public function getImpersonator(): ?User
            {
                return null;
            }
        };
        $webUser->setIdentity($user);
        Craft::$app->set('user', $webUser);
    }

    /** @return array{reports: int, exports: int, queue: int} */
    private function ownedState(): array
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);

        return [
            'reports' => (int)ReportRecord::find()->where(['like', 'handle', self::MARKER . '%', false])->count(),
            'exports' => (int)ExportRecord::find()->where(['like', 'dataSource', self::MARKER . '%', false])->count(),
            'queue' => (int)(new Query())
                ->from($queue->tableName)
                ->where(['like', 'job', GenerateExportJob::class])
                ->count(),
        ];
    }

    private function assertForbidden(callable $action): void
    {
        try {
            $action();
            self::fail('Expected the action to be forbidden.');
        } catch (ForbiddenHttpException) {
        }
    }

    private function renderCaptured(\yii\web\Response $response): string
    {
        $template = $response->data['template'] ?? null;
        $variables = $response->data['variables'] ?? null;
        self::assertIsString($template);
        self::assertIsArray($variables);

        return Craft::$app->getView()->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);
    }

    private function reportsController(): PermissionReportsController
    {
        return new PermissionReportsController('reports', ReportManager::$plugin);
    }

    private function dashboardController(): PermissionDashboardController
    {
        return new PermissionDashboardController('dashboard', ReportManager::$plugin);
    }

    private function purgeOwnedPermissionRows(): void
    {
        if (isset($this->report->id)) {
            Craft::$app->getDb()->createCommand()
                ->delete(ExportRecord::tableName(), ['reportId' => (int)$this->report->id])
                ->execute();
        }
        Craft::$app->getDb()->createCommand()
            ->delete(ReportRecord::tableName(), ['like', 'handle', 'rm_test_permission_%', false])
            ->execute();
    }
}

final class PermissionReportsController extends ReportsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $response = new Response();
        $response->data = compact('template', 'variables');

        return $response;
    }

    protected function setReportSessionError(string $message): void
    {
    }

    protected function setReportSessionNotice(string $message): void
    {
    }
}

final class PermissionDashboardController extends DashboardController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $response = new Response();
        $response->data = compact('template', 'variables');

        return $response;
    }
}

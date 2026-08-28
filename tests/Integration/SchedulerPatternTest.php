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
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\reportmanager\jobs\ProcessScheduledReportJob;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\services\ReportsService;
use lindemannrock\reportmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins Report Manager's independent per-report scheduling behavior.
 *
 * @since 5.4.0
 */
final class SchedulerPatternTest extends TestCase
{
    protected function tearDown(): void
    {
        Craft::$app->getDb()->createCommand()->delete(ReportRecord::tableName(), [
            'handle' => 'rm_test_save-reanchor',
        ])->execute();

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function calendarScheduleProvider(): iterable
    {
        yield 'monthly' => ['monthly'];
        yield 'every two months' => ['every2months'];
        yield 'quarterly' => ['quarterly'];
        yield 'every six months' => ['every6months'];
        yield 'yearly' => ['yearly'];
    }

    #[DataProvider('calendarScheduleProvider')]
    public function testLateCalendarRunAdvancesFromStoredDueBoundary(string $schedule): void
    {
        $previousDue = (new \DateTime('-1 month'))->setTime(10, 15, 0);
        $report = $this->makeDueReport('late-' . $schedule, $schedule, $previousDue);
        $report = ReportRecord::findOne($report->id);
        self::assertNotNull($report);
        $storedDue = $this->reportDate($report, 'nextScheduledAt');
        $storedTime = $storedDue->format('H:i:s');

        $expected = ScheduleHelper::calculateNext($schedule, $storedDue);
        self::assertNotNull($expected);
        while ($expected <= new \DateTime()) {
            $expected = ScheduleHelper::calculateNext($schedule, $expected);
            self::assertNotNull($expected);
        }

        self::assertTrue($this->reports->markReportGenerated($report, $storedDue));

        $fresh = ReportRecord::findOne($report->id);
        self::assertNotNull($fresh);
        self::assertSame($expected->format('Y-m-d H:i:s'), $this->reportDate($fresh, 'nextScheduledAt')->format('Y-m-d H:i:s'));
        self::assertSame($storedTime, $this->reportDate($fresh, 'nextScheduledAt')->format('H:i:s'));
    }

    public function testLateMonthlyRunKeepsBaseEndOfMonthClampAcrossMissedOccurrences(): void
    {
        $previousDue = new \DateTime('2020-01-31 10:15:00', new \DateTimeZone(Craft::$app->getTimeZone()));
        $report = $this->makeDueReport('end-of-month-clamp', 'monthly', $previousDue);
        $report = ReportRecord::findOne($report->id);
        self::assertNotNull($report);
        $storedDue = $this->reportDate($report, 'nextScheduledAt');
        $storedTime = $storedDue->format('H:i:s');

        self::assertTrue($this->reports->markReportGenerated($report, $storedDue));

        $fresh = ReportRecord::findOne($report->id);
        self::assertNotNull($fresh);
        $next = $this->reportDate($fresh, 'nextScheduledAt');
        self::assertGreaterThan(new \DateTime(), $next);
        self::assertSame('28', $next->format('d'));
        self::assertSame($storedTime, $next->format('H:i:s'));
    }

    public function testLateCalendarRunSkipsMissedTargetsWithoutCreatingExtraExports(): void
    {
        $previousDue = (new \DateTime('-14 months'))->setTime(9, 35, 0);
        $report = $this->makeDueReport('missed-calendar-targets', 'monthly', $previousDue);
        $report = ReportRecord::findOne($report->id);
        self::assertNotNull($report);
        $storedDue = $this->reportDate($report, 'nextScheduledAt');
        $beforeExports = (int)\lindemannrock\reportmanager\records\ExportRecord::find()
            ->where(['reportId' => $report->id])
            ->count();

        self::assertTrue($this->reports->markReportGenerated($report, $storedDue));

        $fresh = ReportRecord::findOne($report->id);
        self::assertNotNull($fresh);
        self::assertGreaterThan(new \DateTime(), $this->reportDate($fresh, 'nextScheduledAt'));
        self::assertSame($beforeExports, (int)\lindemannrock\reportmanager\records\ExportRecord::find()
            ->where(['reportId' => $report->id])
            ->count());
    }

    public function testSavingScheduledReportStillReanchorsCalendarScheduleFromNow(): void
    {
        $oldDue = new \DateTime('2020-01-15 07:30:00', new \DateTimeZone(Craft::$app->getTimeZone()));
        $report = $this->makeDueReport('save-reanchor', 'monthly', $oldDue);
        $report->dataSource = 'entries';
        $reports = new NonQueueingReportsService();
        $this->swapPluginComponent('report-manager', 'reports', $reports);
        $this->reports = $reports;
        $before = new \DateTime();

        self::assertTrue($this->reports->saveReport($report), json_encode($report->getErrors()));

        $after = new \DateTime();
        $next = $this->reportDate($report, 'nextScheduledAt');
        $lower = ScheduleHelper::calculateNext('monthly', $before);
        $upper = ScheduleHelper::calculateNext('monthly', $after);
        self::assertNotNull($lower);
        self::assertNotNull($upper);
        self::assertGreaterThanOrEqual($lower, $next);
        self::assertLessThanOrEqual($upper, $next);
        self::assertNotSame('07:30:00', $next->format('H:i:s'));
    }

    public function testFixedScheduleStillUsesItsCurrentTimeSlotAfterLateRun(): void
    {
        $previousDue = new \DateTime('2020-01-15 07:30:00', new \DateTimeZone(Craft::$app->getTimeZone()));
        $report = $this->makeDueReport('fixed-schedule', 'daily2am', $previousDue);

        self::assertTrue($this->reports->markReportGenerated($report, $previousDue));

        $fresh = ReportRecord::findOne($report->id);
        self::assertNotNull($fresh);
        $next = $this->reportDate($fresh, 'nextScheduledAt');
        self::assertGreaterThan(new \DateTime(), $next);
        self::assertSame('02:00:00', DateFormatHelper::toCraftTimezone($next)?->format('H:i:s'));
    }

    public function testScheduledReportGuardIgnoresFailedExistingReportRow(): void
    {
        $settings = $this->settings();
        $originalEnableScheduledReports = $settings->enableScheduledReports;
        $settings->enableScheduledReports = true;

        try {
            $report = new ReportRecord([
                'name' => self::MARKER . ' Failed existing report',
                'handle' => self::MARKER . 'failed-existing-report',
                'dataSource' => self::MARKER . 'source',
                'dateRange' => 'last30days',
                'exportFormat' => 'csv',
                'exportMode' => 'separate',
                'enableSchedule' => true,
                'schedule' => 'daily',
                'nextScheduledAt' => (new \DateTime('+1 day'))->format('Y-m-d H:i:s'),
                'enabled' => true,
                'sortOrder' => 0,
                'dateCreated' => new \DateTime(),
                'dateUpdated' => new \DateTime(),
            ]);
            $report->setEntityIdsArray([1]);
            $this->assertTrue($report->save(false));

            Craft::$app->getQueue()->delay(300)->push(new ProcessScheduledReportJob([
                'reportId' => (int) $report->id,
            ]));
            $this->assertSame(1, $this->countQueueRows('ProcessScheduledReportJob'));

            Craft::$app->getDb()->createCommand()
                ->update('{{%queue}}', ['fail' => true], [
                    'and',
                    ['like', 'job', 'reportmanager'],
                    ['like', 'job', 'ProcessScheduledReportJob'],
                    ['like', 'job', $this->scheduledReportQueueToken((int) $report->id)],
                ])
                ->execute();

            $this->assertTrue($this->reports->queueScheduledReportJob($report, false));
            $this->assertSame(2, $this->countQueueRows('ProcessScheduledReportJob'));
        } finally {
            $settings->enableScheduledReports = $originalEnableScheduledReports;
        }
    }

    public function testScheduledReportBootstrapDoesNotChurnExistingPendingRow(): void
    {
        $settings = $this->settings();
        $originalEnableScheduledReports = $settings->enableScheduledReports;
        $settings->enableScheduledReports = true;

        try {
            $report = $this->makeScheduledReport('bootstrap-no-churn-report');

            $this->assertTrue($this->reports->queueScheduledReportJob($report));
            $existingRow = $this->latestScheduledReportQueueRow((int) $report->id);
            $this->assertNotNull($existingRow);

            $this->reports->queueAllScheduledReportJobs();

            $rowAfterBootstrap = $this->latestScheduledReportQueueRow((int) $report->id);
            $this->assertNotNull($rowAfterBootstrap);
            $this->assertSame((string) $existingRow['id'], (string) $rowAfterBootstrap['id']);
            $this->assertSame(1, $this->countScheduledReportQueueRows((int) $report->id));
        } finally {
            $settings->enableScheduledReports = $originalEnableScheduledReports;
        }
    }

    public function testScheduledReportBootstrapCollapsesDuplicatePendingRows(): void
    {
        $settings = $this->settings();
        $originalEnableScheduledReports = $settings->enableScheduledReports;
        $settings->enableScheduledReports = true;

        try {
            $report = $this->makeScheduledReport('bootstrap-duplicate-report');

            Craft::$app->getQueue()->delay(300)->push(new ProcessScheduledReportJob([
                'reportId' => (int) $report->id,
            ]));
            Craft::$app->getQueue()->delay(600)->push(new ProcessScheduledReportJob([
                'reportId' => (int) $report->id,
            ]));
            $this->assertSame(2, $this->countScheduledReportQueueRows((int) $report->id));

            $this->reports->queueScheduledReportJob($report, false);

            $row = $this->latestScheduledReportQueueRow((int) $report->id);
            $this->assertNotNull($row);
            $this->assertSame(1, $this->countScheduledReportQueueRows((int) $report->id));
        } finally {
            $settings->enableScheduledReports = $originalEnableScheduledReports;
        }
    }

    public function testReportSpecificDeletionRemovesItsSingularScheduledJob(): void
    {
        $settings = $this->settings();
        $originalEnableScheduledReports = $settings->enableScheduledReports;
        $settings->enableScheduledReports = true;

        try {
            $report = $this->makeScheduledReport('delete-owned-report-job');
            self::assertTrue($this->reports->queueScheduledReportJob($report));
            self::assertSame(1, $this->countScheduledReportQueueRows((int)$report->id));

            self::assertSame(1, $this->reports->deleteScheduledReportJobs((int)$report->id));
            self::assertSame(0, $this->countScheduledReportQueueRows((int)$report->id));
        } finally {
            $settings->enableScheduledReports = $originalEnableScheduledReports;
        }
    }

    public function testScheduleOptionsComeFromBaseCuratedList(): void
    {
        $options = $this->settings()->getScheduleOptions();

        $this->assertSame([
            'disabled',
            'every6hours',
            'every12hours',
            'daily',
            'daily2am',
            'weekly',
            'monthly',
            'every2months',
            'quarterly',
            'every6months',
            'yearly',
        ], array_column($options, 'value'));
    }

    public function testFutureYearScheduledReportDescriptionIncludesYear(): void
    {
        $report = new ReportRecord([
            'name' => self::MARKER . ' Future yearly report',
            'handle' => self::MARKER . 'future-yearly-report',
            'dataSource' => self::MARKER . 'source',
            'dateRange' => 'last30days',
            'exportFormat' => 'csv',
            'exportMode' => 'separate',
            'enableSchedule' => true,
            'schedule' => 'yearly',
            'nextScheduledAt' => '2027-05-27 11:14:00',
            'enabled' => true,
            'sortOrder' => 0,
            'dateCreated' => new \DateTime(),
            'dateUpdated' => new \DateTime(),
        ]);
        $report->setEntityIdsArray([1]);
        $this->assertTrue($report->save(false));

        $this->assertTrue($this->reports->queueScheduledReportJob($report));

        $description = (new \craft\db\Query())
            ->select(['description'])
            ->from('{{%queue}}')
            ->where(['like', 'job', 'reportmanager'])
            ->andWhere(['like', 'job', 'ProcessScheduledReportJob'])
            ->scalar();

        $this->assertIsString($description);
        $this->assertStringContainsString('2027', $description);
    }

    private function countQueueRows(string $jobClass): int
    {
        return (int)(new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'reportmanager'])
            ->andWhere(['like', 'job', $jobClass])
            ->count();
    }

    private function countScheduledReportQueueRows(int $reportId): int
    {
        return (int) (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'reportmanager'])
            ->andWhere(['like', 'job', 'ProcessScheduledReportJob'])
            ->andWhere(['like', 'job', $this->scheduledReportQueueToken($reportId)])
            ->count();
    }

    private function latestScheduledReportQueueRow(int $reportId): ?array
    {
        $row = (new \craft\db\Query())
            ->select(['id', 'description'])
            ->from('{{%queue}}')
            ->where(['like', 'job', 'reportmanager'])
            ->andWhere(['like', 'job', 'ProcessScheduledReportJob'])
            ->andWhere(['like', 'job', $this->scheduledReportQueueToken($reportId)])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return is_array($row) ? $row : null;
    }

    private function scheduledReportQueueToken(int $reportId): string
    {
        return 's:8:"reportId";i:' . $reportId . ';';
    }

    private function makeScheduledReport(string $handle): ReportRecord
    {
        $report = new ReportRecord([
            'name' => self::MARKER . ' ' . $handle,
            'handle' => self::MARKER . $handle,
            'dataSource' => self::MARKER . 'source',
            'dateRange' => 'last30days',
            'exportFormat' => 'csv',
            'exportMode' => 'separate',
            'enableSchedule' => true,
            'schedule' => 'daily',
            'nextScheduledAt' => (new \DateTime('+1 day'))->format('Y-m-d H:i:s'),
            'enabled' => true,
            'sortOrder' => 0,
            'dateCreated' => new \DateTime(),
            'dateUpdated' => new \DateTime(),
        ]);
        $report->setEntityIdsArray([1]);
        $this->assertTrue($report->save(false));

        return $report;
    }

    private function makeDueReport(string $handle, string $schedule, \DateTime $due): ReportRecord
    {
        $report = new ReportRecord([
            'name' => self::MARKER . ' ' . $handle,
            'handle' => self::MARKER . $handle,
            'dataSource' => self::MARKER . 'source',
            'dateRange' => 'last30days',
            'exportFormat' => 'csv',
            'exportMode' => 'separate',
            'enableSchedule' => true,
            'schedule' => $schedule,
            'nextScheduledAt' => $due->format('Y-m-d H:i:s'),
            'enabled' => true,
            'sortOrder' => 0,
            'dateCreated' => new \DateTime(),
            'dateUpdated' => new \DateTime(),
        ]);
        $report->setEntityIdsArray([1]);
        self::assertTrue($report->save(false));

        return $report;
    }

    private function reportDate(ReportRecord $report, string $attribute): \DateTime
    {
        $value = $report->getAttribute($attribute);

        return $value instanceof \DateTime
            ? $value
            : new \DateTime((string)$value, new \DateTimeZone('UTC'));
    }
}

final class NonQueueingReportsService extends ReportsService
{
    public function queueScheduledReportJob(ReportRecord $report, bool $replaceExisting = true): bool
    {
        return true;
    }
}

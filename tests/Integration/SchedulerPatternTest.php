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
use lindemannrock\reportmanager\jobs\CleanupExportsJob;
use lindemannrock\reportmanager\jobs\ProcessScheduledReportJob;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Pins Report Manager's scheduler-pattern integration with base helpers.
 *
 * @since 5.4.0
 */
final class SchedulerPatternTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteReportManagerQueueRows();
    }

    protected function tearDown(): void
    {
        $this->deleteReportManagerQueueRows();
        parent::tearDown();
    }

    public function testCleanupRescheduleDoesNotSelfBlockOnExistingCleanupRow(): void
    {
        Craft::$app->getQueue()->delay(300)->push(new CleanupExportsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(1, $this->countQueueRows('CleanupExportsJob'));

        ReportManager::getInstance()->scheduleNextExportCleanupJob();

        $this->assertSame(2, $this->countQueueRows('CleanupExportsJob'));
    }

    public function testCleanupBootstrapDoesNotDuplicateExistingDelayedCleanupRow(): void
    {
        $settings = $this->settings();
        $originalAutoCleanupExports = $settings->autoCleanupExports;
        $originalExportRetention = $settings->exportRetention;

        $settings->autoCleanupExports = true;
        $settings->exportRetention = 30;

        try {
            Craft::$app->getQueue()->delay(300)->push(new CleanupExportsJob([
                'reschedule' => true,
            ]));
            $this->assertSame(1, $this->countQueueRows('CleanupExportsJob'));

            ReportManager::getInstance()->scheduleExportCleanupJob();

            $this->assertSame(1, $this->countQueueRows('CleanupExportsJob'));
        } finally {
            $settings->autoCleanupExports = $originalAutoCleanupExports;
            $settings->exportRetention = $originalExportRetention;
        }
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
                    ['like', 'job', 'reportId";i:' . $report->id . ';'],
                ])
                ->execute();

            $this->assertTrue($this->reports->queueScheduledReportJob($report, false));
            $this->assertSame(2, $this->countQueueRows('ProcessScheduledReportJob'));
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
        return (int) (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'reportmanager'])
            ->andWhere(['like', 'job', $jobClass])
            ->count();
    }

    private function deleteReportManagerQueueRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%queue}}', [
                'and',
                ['like', 'job', 'reportmanager'],
                [
                    'or',
                    ['like', 'job', 'CleanupExportsJob'],
                    ['like', 'job', 'ProcessScheduledReportJob'],
                ],
            ])
            ->execute();

        Craft::$app->getDb()->createCommand()
            ->delete(ReportRecord::tableName(), ['like', 'handle', self::MARKER])
            ->execute();
    }
}

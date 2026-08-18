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
use lindemannrock\reportmanager\jobs\ProcessScheduledReportJob;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Pins Report Manager's independent per-report scheduling behavior.
 *
 * @since 5.4.0
 */
final class SchedulerPatternTest extends TestCase
{
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
}

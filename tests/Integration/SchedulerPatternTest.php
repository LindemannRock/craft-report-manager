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

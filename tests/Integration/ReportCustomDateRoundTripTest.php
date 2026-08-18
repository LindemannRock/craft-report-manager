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
use craft\helpers\DateTimeHelper;
use DateTime;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Saved custom report dates retain their local calendar days when reloaded.
 *
 * @since 5.6.0
 */
final class ReportCustomDateRoundTripTest extends TestCase
{
    public function testReloadAndUnchangedSavePreserveLocalCalendarDays(): void
    {
        $originalTimeZone = Craft::$app->getTimeZone();
        Craft::$app->setTimeZone('Asia/Kuwait');

        try {
            $report = new ReportRecord([
                'name' => 'Date Round Trip',
                'handle' => self::MARKER . 'date_round_trip',
                'dataSource' => self::MARKER . 'source',
                'dateRange' => 'custom',
                'exportFormat' => 'csv',
                'exportMode' => 'combined',
                'enableSchedule' => false,
                'enabled' => true,
                'sortOrder' => 0,
                'customDateStart' => $this->pickerDate('7/25/2024', false),
                'customDateEnd' => $this->pickerDate('8/18/2026', true),
            ]);
            $report->setEntityIdsArray([1]);
            self::assertTrue($report->save(false));
            self::assertSame('2024-07-24 21:00:00', $this->storedDate((int)$report->id, 'customDateStart'));
            self::assertSame('2026-08-18 20:59:59', $this->storedDate((int)$report->id, 'customDateEnd'));

            $reloaded = ReportRecord::findOne($report->id);
            self::assertNotNull($reloaded);
            $displayStart = $reloaded->getCustomDateStartForDisplay();
            $displayEnd = $reloaded->getCustomDateEndForDisplay();
            self::assertInstanceOf(DateTime::class, $displayStart);
            self::assertInstanceOf(DateTime::class, $displayEnd);
            self::assertSame('2024-07-25 00:00:00 +03:00', $displayStart->format('Y-m-d H:i:s P'));
            self::assertSame('2026-08-18 23:59:59 +03:00', $displayEnd->format('Y-m-d H:i:s P'));

            $reloaded->customDateStart = $this->pickerDate($displayStart->format('n/j/Y'), false);
            $reloaded->customDateEnd = $this->pickerDate($displayEnd->format('n/j/Y'), true);
            self::assertTrue($reloaded->save(false));

            self::assertSame('2024-07-24 21:00:00', $this->storedDate((int)$report->id, 'customDateStart'));
            self::assertSame('2026-08-18 20:59:59', $this->storedDate((int)$report->id, 'customDateEnd'));
        } finally {
            Craft::$app->setTimeZone($originalTimeZone);
        }
    }

    private function pickerDate(string $date, bool $endOfDay): DateTime
    {
        $value = DateTimeHelper::toDateTime([
            'date' => $date,
            'locale' => 'en-US',
            'timezone' => 'Asia/Kuwait',
        ]);
        self::assertInstanceOf(DateTime::class, $value);

        return $endOfDay ? $value->setTime(23, 59, 59) : $value->setTime(0, 0, 0);
    }

    private function storedDate(int $reportId, string $attribute): ?string
    {
        $value = (new Query())
            ->select([$attribute])
            ->from(ReportRecord::tableName())
            ->where(['id' => $reportId])
            ->scalar();

        return is_string($value) ? $value : null;
    }
}

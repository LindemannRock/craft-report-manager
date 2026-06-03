<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use DateTime;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Pins the custom date range rule: an inverted range (start after end) matches
 * no records and would silently produce an empty export, so it must be rejected.
 *
 * @since 5.4.0
 */
final class CustomDateRangeValidationTest extends TestCase
{
    public function testInvertedCustomRangeIsRejected(): void
    {
        $report = $this->makeReport('custom', new DateTime('2026-04-30'), new DateTime('2026-03-30'));

        self::assertFalse($report->validate(['customDateEnd']));
        self::assertSame(
            'End date must be on or after the start date.',
            $report->getFirstError('customDateEnd'),
        );
    }

    public function testValidCustomRangeIsAccepted(): void
    {
        $report = $this->makeReport('custom', new DateTime('2026-03-30'), new DateTime('2026-04-30'));

        $report->validate(['customDateEnd']);
        self::assertArrayNotHasKey('customDateEnd', $report->getErrors());
    }

    public function testEqualStartAndEndIsAccepted(): void
    {
        $report = $this->makeReport('custom', new DateTime('2026-03-30'), new DateTime('2026-03-30'));

        $report->validate(['customDateEnd']);
        self::assertArrayNotHasKey('customDateEnd', $report->getErrors());
    }

    public function testOpenEndedCustomRangeIsAccepted(): void
    {
        $startOnly = $this->makeReport('custom', new DateTime('2026-04-30'), null);
        $startOnly->validate(['customDateEnd']);
        self::assertArrayNotHasKey('customDateEnd', $startOnly->getErrors());

        $endOnly = $this->makeReport('custom', null, new DateTime('2026-03-30'));
        $endOnly->validate(['customDateEnd']);
        self::assertArrayNotHasKey('customDateEnd', $endOnly->getErrors());
    }

    public function testInvertedDatesIgnoredForNonCustomRange(): void
    {
        // Same inverted dates, but a non-custom range ignores them entirely.
        $report = $this->makeReport('last30days', new DateTime('2026-04-30'), new DateTime('2026-03-30'));

        $report->validate(['customDateEnd']);
        self::assertArrayNotHasKey('customDateEnd', $report->getErrors());
    }

    private function makeReport(string $dateRange, ?DateTime $start, ?DateTime $end): ReportRecord
    {
        return new ReportRecord([
            'dateRange' => $dateRange,
            'customDateStart' => $start,
            'customDateEnd' => $end,
        ]);
    }
}

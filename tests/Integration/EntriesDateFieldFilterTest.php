<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use craft\elements\Entry;
use DateTime;
use lindemannrock\reportmanager\datasources\EntriesDataSource;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Proves the entry date-field filter is actually applied to the query and that
 * each supported field (postDate / dateCreated / dateUpdated) maps to a valid,
 * executable filter — the postDate path in particular, which goes through
 * Craft's native param because the entries table is not on the main query.
 *
 * Uses whatever entries the test install already has rather than seeding
 * elements (the suite avoids saveElement under console requests).
 *
 * @since 5.4.0
 */
final class EntriesDateFieldFilterTest extends TestCase
{
    private const FAR_FUTURE = '2099-01-01';

    public function testEachDateFieldFilterExecutesAndConstrainsResults(): void
    {
        $entry = Entry::find()->status(null)->one();
        if (!$entry instanceof Entry) {
            self::markTestSkipped('No entries in the test install to filter.');
        }

        $source = new EntriesDataSource();
        $sectionId = (int) $entry->sectionId;
        $total = $source->getRecordCount($sectionId);

        self::assertGreaterThan(0, $total, 'Section under test must have at least one entry.');

        // A far-future lower bound must exclude everything for every field — this
        // both proves the filter is applied (not silently ignored) and that the
        // column/param for each field produces valid, executable SQL.
        foreach (['postDate', 'dateCreated', 'dateUpdated'] as $field) {
            $count = $source->getRecordCount($sectionId, [
                'dateField' => $field,
                'dateStart' => new DateTime(self::FAR_FUTURE),
            ]);

            self::assertSame(0, $count, "Far-future start should exclude all entries for {$field}.");
        }
    }

    public function testPostDateIsTheDefaultFieldForEntries(): void
    {
        $entry = Entry::find()->status(null)->one();
        if (!$entry instanceof Entry) {
            self::markTestSkipped('No entries in the test install to filter.');
        }

        $source = new EntriesDataSource();
        $sectionId = (int) $entry->sectionId;

        // No dateField given → resolves to the source default (postDate). A
        // far-future bound therefore still excludes everything.
        $count = $source->getRecordCount($sectionId, [
            'dateStart' => new DateTime(self::FAR_FUTURE),
        ]);

        self::assertSame(0, $count);
    }
}

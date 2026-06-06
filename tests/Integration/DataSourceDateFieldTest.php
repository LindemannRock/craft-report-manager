<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use lindemannrock\reportmanager\datasources\CategoriesDataSource;
use lindemannrock\reportmanager\datasources\EntriesDataSource;
use lindemannrock\reportmanager\datasources\FormieDataSource;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\tests\TestCase;
use ReflectionMethod;

/**
 * Pins the per-source "filter by date" contract: which date fields each source
 * exposes, its default, how an unset/invalid choice is resolved, and that a
 * report rejects a date field the source does not support.
 *
 * @since 5.4.0
 */
final class DataSourceDateFieldTest extends TestCase
{
    public function testEntriesExposesPostDateCreatedUpdatedWithPostDateDefault(): void
    {
        self::assertSame(
            ['postDate', 'dateCreated', 'dateUpdated'],
            array_column(EntriesDataSource::dateFieldOptions(), 'value'),
        );
        self::assertSame('postDate', EntriesDataSource::defaultDateField());
    }

    public function testCategoriesExposesCreatedUpdatedWithCreatedDefault(): void
    {
        self::assertSame(
            ['dateCreated', 'dateUpdated'],
            array_column(CategoriesDataSource::dateFieldOptions(), 'value'),
        );
        self::assertSame('dateCreated', CategoriesDataSource::defaultDateField());
    }

    public function testFormieExposesSubmissionAndUpdatedWithSubmissionDefault(): void
    {
        self::assertSame(
            ['dateCreated', 'dateUpdated'],
            array_column(FormieDataSource::dateFieldOptions(), 'value'),
        );
        self::assertSame('dateCreated', FormieDataSource::defaultDateField());
    }

    public function testResolveDateFieldFallsBackToDefaultForEmptyOrInvalid(): void
    {
        $source = new EntriesDataSource();
        $resolve = new ReflectionMethod($source, 'resolveDateField');
        $resolve->setAccessible(true);

        self::assertSame('postDate', $resolve->invoke($source, []), 'Empty falls back to default');
        self::assertSame('postDate', $resolve->invoke($source, ['dateField' => 'nope']), 'Invalid falls back to default');
        self::assertSame('dateUpdated', $resolve->invoke($source, ['dateField' => 'dateUpdated']), 'Valid choice is honoured');
    }

    public function testDateColumnQualificationUsesElementsTableForElementSources(): void
    {
        $categories = new CategoriesDataSource();
        $catColumn = new ReflectionMethod($categories, 'dateColumn');
        $catColumn->setAccessible(true);
        self::assertSame('elements.dateCreated', $catColumn->invoke($categories, 'dateCreated'));

        $formie = new FormieDataSource();
        $formieColumn = new ReflectionMethod($formie, 'dateColumn');
        $formieColumn->setAccessible(true);
        self::assertSame('elements.dateCreated', $formieColumn->invoke($formie, 'dateCreated'));
    }

    public function testReportAcceptsADateFieldTheSourceSupports(): void
    {
        $report = new ReportRecord(['dataSource' => 'entries', 'dateField' => 'postDate']);

        $report->validate(['dateField']);
        self::assertArrayNotHasKey('dateField', $report->getErrors());
    }

    public function testReportRejectsADateFieldTheSourceDoesNotSupport(): void
    {
        // Categories has no Post Date.
        $report = new ReportRecord(['dataSource' => 'categories', 'dateField' => 'postDate']);

        self::assertFalse($report->validate(['dateField']));
        self::assertArrayHasKey('dateField', $report->getErrors());
    }

    public function testEmptyDateFieldIsValid(): void
    {
        $report = new ReportRecord(['dataSource' => 'entries', 'dateField' => null]);

        $report->validate(['dateField']);
        self::assertArrayNotHasKey('dateField', $report->getErrors());
    }
}

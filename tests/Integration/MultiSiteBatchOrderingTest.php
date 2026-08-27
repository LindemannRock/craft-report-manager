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
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use craft\models\CategoryGroup;
use craft\models\Section;
use lindemannrock\reportmanager\datasources\CategoriesDataSource;
use lindemannrock\reportmanager\datasources\DataSourceInterface;
use lindemannrock\reportmanager\datasources\EntriesDataSource;
use lindemannrock\reportmanager\datasources\FormieDataSource;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Deterministic built-in data-source windows across multi-site variants.
 *
 * @since 5.6.0
 */
final class MultiSiteBatchOrderingTest extends TestCase
{
    private const ELEMENT_COUNT = 34;
    private const SITE_COUNT = 3;
    private const WINDOW_SIZE = 100;

    public function testEntryWindowsContainEveryOwnedSiteVariantExactlyOnce(): void
    {
        $section = $this->entrySection();
        $siteIds = array_slice(array_map('intval', $section->getSiteIds()), 0, self::SITE_COUNT);
        $marker = self::MARKER . 'entry_window_' . bin2hex(random_bytes(6));
        $createdIds = [];

        try {
            for ($index = 0; $index < self::ELEMENT_COUNT; $index++) {
                $entry = new Entry();
                $entry->sectionId = (int)$section->id;
                $entry->typeId = (int)$section->getEntryTypes()[0]->id;
                $entry->siteId = $siteIds[0];
                $entry->enabled = true;
                $entry->enabledForSite = true;
                $entry->title = "{$marker} {$index}";
                $entry->slug = "{$marker}-{$index}";
                self::assertTrue(Craft::$app->getElements()->saveElement(
                    $entry,
                    runValidation: false,
                    updateSearchIndex: false,
                ));
                $createdIds[] = (int)$entry->id;
            }

            $source = new EntriesDataSource();
            $firstRead = $this->readWindows($source, (int)$section->id, $siteIds);
            $secondRead = $this->readWindows($source, (int)$section->id, $siteIds);
            $this->assertOwnedPairsAreStableAndComplete($firstRead, $secondRead, $createdIds, $siteIds);
        } finally {
            $this->deleteOwnedElements(Entry::class, $createdIds, $siteIds[0] ?? null);
        }

        $this->assertElementRowsRemoved($createdIds);
    }

    public function testCategoryWindowsContainEveryOwnedSiteVariantExactlyOnce(): void
    {
        $group = $this->categoryGroup();
        $siteIds = array_slice(array_map('intval', array_keys($group->getSiteSettings())), 0, self::SITE_COUNT);
        $marker = self::MARKER . 'category_window_' . bin2hex(random_bytes(6));
        $createdIds = [];

        try {
            for ($index = 0; $index < self::ELEMENT_COUNT; $index++) {
                $category = new Category();
                $category->groupId = (int)$group->id;
                $category->siteId = $siteIds[0];
                $category->enabled = true;
                $category->enabledForSite = true;
                $category->title = "{$marker} {$index}";
                $category->slug = "{$marker}-{$index}";
                self::assertTrue(Craft::$app->getElements()->saveElement(
                    $category,
                    runValidation: false,
                    updateSearchIndex: false,
                ));
                $createdIds[] = (int)$category->id;
            }

            $source = new CategoriesDataSource();
            $firstRead = $this->readWindows($source, (int)$group->id, $siteIds);
            $secondRead = $this->readWindows($source, (int)$group->id, $siteIds);
            $this->assertOwnedPairsAreStableAndComplete($firstRead, $secondRead, $createdIds, $siteIds);
        } finally {
            $this->deleteOwnedElements(Category::class, $createdIds, $siteIds[0] ?? null);
        }

        $this->assertElementRowsRemoved($createdIds);
    }

    public function testFormieWindowsAreStableWhenAFullWindowIsAvailable(): void
    {
        self::assertTrue(class_exists(\verbb\formie\elements\Submission::class));
        $source = new FormieDataSource();
        $entity = null;
        foreach ($source->getAvailableEntities() as $candidate) {
            if (($candidate['recordCount'] ?? 0) > self::WINDOW_SIZE) {
                $entity = $candidate;
                break;
            }
        }

        if ($entity === null) {
            $sourceCode = file_get_contents(dirname(__DIR__, 2) . '/src/datasources/FormieDataSource.php');
            self::assertIsString($sourceCode);
            self::assertStringContainsString("'elements_sites.siteId' => SORT_DESC", $sourceCode);
            return;
        }

        $siteIds = array_map('intval', Craft::$app->getSites()->getAllSiteIds());
        $firstRead = $this->readWindows($source, (int)$entity['id'], $siteIds);
        $secondRead = $this->readWindows($source, (int)$entity['id'], $siteIds);

        self::assertSame($firstRead, $secondRead);
        $keys = array_column($firstRead, 'key');
        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertGreaterThan(self::WINDOW_SIZE, count($firstRead));
    }

    /**
     * @param int[] $siteIds
     * @return list<array{key: string, id: int, siteId: int, window: int}>
     */
    private function readWindows(DataSourceInterface $source, int $entityId, array $siteIds): array
    {
        $count = $source->getRecordCount($entityId, ['siteIds' => $siteIds]);
        $pairs = [];

        for ($offset = 0; $offset < $count; $offset += self::WINDOW_SIZE) {
            $records = $source->getRecords($entityId, [
                'siteIds' => $siteIds,
                'limit' => self::WINDOW_SIZE,
                'offset' => $offset,
            ]);
            foreach ($records as $record) {
                self::assertInstanceOf(ElementInterface::class, $record);
                $id = (int)$record->id;
                $siteId = (int)$record->siteId;
                $pairs[] = [
                    'key' => "{$id}:{$siteId}",
                    'id' => $id,
                    'siteId' => $siteId,
                    'window' => intdiv($offset, self::WINDOW_SIZE),
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param list<array{key: string, id: int, siteId: int, window: int}> $firstRead
     * @param list<array{key: string, id: int, siteId: int, window: int}> $secondRead
     * @param int[] $createdIds
     * @param int[] $siteIds
     */
    private function assertOwnedPairsAreStableAndComplete(
        array $firstRead,
        array $secondRead,
        array $createdIds,
        array $siteIds,
    ): void {
        self::assertSame($firstRead, $secondRead);
        $allKeys = array_column($firstRead, 'key');
        self::assertSame($allKeys, array_values(array_unique($allKeys)));

        $owned = array_values(array_filter(
            $firstRead,
            static fn(array $pair): bool => in_array($pair['id'], $createdIds, true),
        ));
        self::assertCount(self::ELEMENT_COUNT * self::SITE_COUNT, $owned);
        self::assertGreaterThan(1, count(array_unique(array_column($owned, 'window'))));

        $expected = [];
        foreach ($createdIds as $elementId) {
            foreach ($siteIds as $siteId) {
                $expected[] = "{$elementId}:{$siteId}";
            }
        }
        $actual = array_column($owned, 'key');
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        self::assertSame($expected, $actual);
    }

    private function entrySection(): Section
    {
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (count($section->getSiteIds()) >= self::SITE_COUNT && $section->getEntryTypes() !== []) {
                return $section;
            }
        }

        self::fail('The integration project needs an entry section propagated to at least three sites.');
    }

    private function categoryGroup(): CategoryGroup
    {
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if (count($group->getSiteSettings()) >= self::SITE_COUNT) {
                return $group;
            }
        }

        self::fail('The integration project needs a category group propagated to at least three sites.');
    }

    /** @param class-string<Entry|Category> $elementClass @param int[] $ids */
    private function deleteOwnedElements(string $elementClass, array $ids, ?int $siteId): void
    {
        foreach (array_reverse($ids) as $id) {
            $element = $elementClass::find()->id($id)->siteId($siteId)->status(null)->one();
            if ($element instanceof ElementInterface) {
                Craft::$app->getElements()->deleteElement($element, true);
            }
        }
    }

    /** @param int[] $ids */
    private function assertElementRowsRemoved(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        self::assertSame('0', (new Query())->from('{{%elements}}')->where(['id' => $ids])->count());
        self::assertSame('0', (new Query())->from('{{%elements_sites}}')->where(['elementId' => $ids])->count());
    }
}

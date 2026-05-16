<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\datasources;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\elements\Category;
use craft\helpers\Db;
use DateTime;

/**
 * Categories Data Source
 *
 * Data source integration for native Craft categories.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class CategoriesDataSource extends BaseDataSource
{
    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'categories';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('report-manager', 'Craft Categories');
    }

    /**
     * @inheritdoc
     */
    public static function description(): string
    {
        return Craft::t('report-manager', 'Generate reports from Craft categories');
    }

    /**
     * @inheritdoc
     */
    public static function uiLabels(): array
    {
        return array_merge(parent::uiLabels(), [
            'entitySingular' => Craft::t('report-manager', 'Category Group'),
            'entityPlural' => Craft::t('report-manager', 'Category Groups'),
            'recordSingular' => Craft::t('report-manager', 'category'),
            'recordPlural' => Craft::t('report-manager', 'categories'),
            'entitySelectionLabel' => Craft::t('report-manager', 'Category Groups to Export'),
            'entitySelectionInstructions' => Craft::t('report-manager', 'Select one or more category groups to include in this report.'),
            'quickExportEntitySelectionInstructions' => Craft::t('report-manager', 'Select one or more category groups to export. Each group will generate a separate export file.'),
            'emptyEntitiesMessage' => Craft::t('report-manager', 'No category groups available.'),
            'selectedEntitiesLabel' => Craft::t('report-manager', 'Selected Category Groups'),
            'combinedPrimaryColumnLabel' => Craft::t('report-manager', 'Category Group Name'),
            'dateRangeInstructions' => Craft::t('report-manager', 'Filter categories by date range.'),
            'exportModeInstructions' => Craft::t('report-manager', 'How to handle multiple category groups.'),
        ]);
    }

    /**
     * @inheritdoc
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getAvailableEntities(): array
    {
        $rows = (new Query())
            ->from(['c' => '{{%categories}}'])
            ->innerJoin(['el' => '{{%elements}}'], '[[el.id]] = [[c.id]]')
            ->where([
                'el.draftId' => null,
                'el.revisionId' => null,
                'el.dateDeleted' => null,
            ])
            ->select(['groupId' => 'c.groupId', 'cnt' => 'COUNT(*)'])
            ->groupBy('c.groupId')
            ->all();

        $counts = array_column($rows, 'cnt', 'groupId');

        $entities = [];

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $entities[] = [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'handle' => (string) $group->handle,
                'recordCount' => (int) ($counts[$group->id] ?? 0),
                'recordLabel' => Craft::t('report-manager', 'categories'),
            ];
        }

        return $entities;
    }

    /**
     * @inheritdoc
     */
    public function getEntity(int $entityId): ?array
    {
        $group = Craft::$app->getCategories()->getGroupById($entityId);

        if ($group === null) {
            return null;
        }

        return [
            'id' => (int) $group->id,
            'name' => (string) $group->name,
            'handle' => (string) $group->handle,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getEntityFields(int $entityId): array
    {
        $group = Craft::$app->getCategories()->getGroupById($entityId);

        if ($group === null) {
            return [];
        }

        $fields = [
            ['handle' => 'id', 'label' => Craft::t('report-manager', 'Category ID'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'uid', 'label' => Craft::t('report-manager', 'UID'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'title', 'label' => Craft::t('report-manager', 'Title'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'slug', 'label' => Craft::t('report-manager', 'Slug'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'uri', 'label' => Craft::t('report-manager', 'URI'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'status', 'label' => Craft::t('report-manager', 'Status'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'siteId', 'label' => Craft::t('report-manager', 'Site ID'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'siteHandle', 'label' => Craft::t('report-manager', 'Site Handle'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'siteName', 'label' => Craft::t('report-manager', 'Site Name'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'level', 'label' => Craft::t('report-manager', 'Level'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'parent', 'label' => Craft::t('report-manager', 'Parent'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'dateCreated', 'label' => Craft::t('report-manager', 'Date Created'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'dateUpdated', 'label' => Craft::t('report-manager', 'Date Updated'), 'type' => 'system', 'exportable' => true],
        ];

        $seenHandles = array_fill_keys(array_column($fields, 'handle'), true);
        $fieldLayout = $group->getFieldLayout();

        if (method_exists($fieldLayout, 'getCustomFields')) {
            foreach ($fieldLayout->getCustomFields() as $field) {
                if (!$field instanceof FieldInterface || isset($seenHandles[$field->handle])) {
                    continue;
                }

                $seenHandles[$field->handle] = true;
                $fields[] = [
                    'handle' => $field->handle,
                    'label' => $field->name,
                    'type' => (new \ReflectionClass($field))->getShortName(),
                    'exportable' => true,
                ];
            }
        }

        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function getRecords(int $entityId, array $options = []): array
    {
        $query = Category::find()
            ->groupId($entityId)
            ->status(null)
            ->orderBy(['lft' => SORT_ASC]);

        $this->applyQueryOptions($query, $options);

        return $query->all();
    }

    /**
     * @inheritdoc
     */
    public function getRecordCount(int $entityId, array $options = []): int
    {
        $query = Category::find()
            ->groupId($entityId)
            ->status(null);

        $this->applyQueryOptions($query, $options);

        return (int) $query->count();
    }

    /**
     * @inheritdoc
     */
    public function getAnalytics(int $entityId, string $dateRange = 'last30days'): array
    {
        $currentCount = $this->getRecordCount($entityId, ['dateRange' => $dateRange]);
        $totalCount = $this->getRecordCount($entityId);

        return [
            'categoryGroupId' => $entityId,
            'dateRange' => $dateRange,
            'currentPeriod' => [
                'categories' => $currentCount,
                'change' => 0,
            ],
            'totals' => [
                'categories' => $totalCount,
            ],
            'averagePerDay' => 0,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getTrendData(int $entityId, string $dateRange = 'last30days'): array
    {
        $categories = $this->getRecords($entityId, ['dateRange' => $dateRange]);
        $dateFormat = $this->getDateFormatForRange($dateRange);
        $trendData = [];

        foreach ($categories as $category) {
            if (!$category instanceof Category || $category->dateCreated === null) {
                continue;
            }

            $date = $category->dateCreated->format($dateFormat);
            $trendData[$date] = ($trendData[$date] ?? 0) + 1;
        }

        ksort($trendData);

        return [
            'labels' => array_keys($trendData),
            'values' => array_values($trendData),
        ];
    }

    /**
     * @inheritdoc
     */
    public function exportToArray(int $entityId, array $fieldHandles = [], array $options = []): array
    {
        $allFields = $this->getEntityFields($entityId);
        $fieldsToExport = empty($fieldHandles)
            ? $allFields
            : array_filter($allFields, static fn($field) => in_array($field['handle'], $fieldHandles, true));

        $headers = array_map(static fn($field) => $field['label'], $fieldsToExport);
        $handles = array_map(static fn($field) => $field['handle'], $fieldsToExport);
        $categories = $this->getRecords($entityId, $options);
        $rows = [];

        foreach ($categories as $category) {
            if (!$category instanceof Category) {
                continue;
            }

            $row = [];
            foreach ($handles as $handle) {
                $row[] = $this->getCategoryFieldValue($category, $handle);
            }
            $rows[] = $row;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * Apply shared category query options.
     *
     * @param \craft\elements\db\CategoryQuery $query Category query
     * @param array $options Query options
     */
    private function applyQueryOptions(\craft\elements\db\CategoryQuery $query, array $options): void
    {
        if (!empty($options['siteIds']) && is_array($options['siteIds'])) {
            $query->siteId($options['siteIds']);
        } else {
            $query->siteId('*');
        }

        if (!empty($options['dateStart'])) {
            $dateStart = $options['dateStart'] instanceof DateTime
                ? $options['dateStart']
                : new DateTime($options['dateStart']);
            $query->andWhere(['>=', 'elements.dateCreated', Db::prepareDateForDb($dateStart)]);
        }

        if (!empty($options['dateEnd'])) {
            $dateEnd = $options['dateEnd'] instanceof DateTime
                ? $options['dateEnd']
                : new DateTime($options['dateEnd']);
            $query->andWhere(['<=', 'elements.dateCreated', Db::prepareDateForDb($dateEnd)]);
        }

        if (!empty($options['dateRange'])) {
            $dateStart = $this->getDateRangeStart($options['dateRange']);
            $dateEnd = $this->getDateRangeEnd($options['dateRange']);

            if ($dateStart) {
                $query->andWhere(['>=', 'elements.dateCreated', Db::prepareDateForDb($dateStart)]);
            }
            if ($dateEnd) {
                $query->andWhere(['<=', 'elements.dateCreated', Db::prepareDateForDb($dateEnd)]);
            }
        }

        if (!empty($options['limit'])) {
            $query->limit((int) $options['limit']);
        }

        if (!empty($options['offset'])) {
            $query->offset((int) $options['offset']);
        }
    }

    /**
     * Get a field value from a category.
     *
     * @param Category $category Category element
     * @param string $handle Field handle
     * @return string
     */
    private function getCategoryFieldValue(Category $category, string $handle): string
    {
        return match ($handle) {
            'id' => (string) $category->id,
            'uid' => (string) $category->uid,
            'title' => (string) $category->title,
            'slug' => (string) $category->slug,
            'uri' => (string) $category->uri,
            'status' => (string) $category->getStatus(),
            'siteId' => (string) $category->siteId,
            'siteHandle' => $category->site->handle ?? '',
            'siteName' => $category->site->name ?? '',
            'level' => (string) $category->level,
            'parent' => $this->getParentLabel($category),
            'dateCreated' => $this->formatExportDate($category->dateCreated),
            'dateUpdated' => $this->formatExportDate($category->dateUpdated),
            default => $this->formatExportValue($category->getFieldValue($handle)),
        };
    }

    /**
     * Get parent category label.
     *
     * @param Category $category Category element
     * @return string
     */
    private function getParentLabel(Category $category): string
    {
        $parent = $category->getParent();

        return $parent instanceof Category ? (string) $parent->title : '';
    }
}

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
use craft\elements\Entry;
use craft\helpers\Db;
use DateTime;

/**
 * Entries Data Source
 *
 * Data source integration for native Craft entries.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class EntriesDataSource extends BaseDataSource
{
    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'entries';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('report-manager', 'Craft Entries');
    }

    /**
     * @inheritdoc
     */
    public static function description(): string
    {
        return Craft::t('report-manager', 'Generate reports from Craft entries');
    }

    /**
     * @inheritdoc
     */
    public static function uiLabels(): array
    {
        return array_merge(parent::uiLabels(), [
            'entitySingular' => Craft::t('report-manager', 'Section'),
            'entityPlural' => Craft::t('report-manager', 'Sections'),
            'recordSingular' => Craft::t('report-manager', 'entry'),
            'recordPlural' => Craft::t('report-manager', 'entries'),
            'entitySelectionLabel' => Craft::t('report-manager', 'Sections to Export'),
            'entitySelectionInstructions' => Craft::t('report-manager', 'Select one or more sections to include in this report.'),
            'quickExportEntitySelectionInstructions' => Craft::t('report-manager', 'Select one or more sections to export. Each section will generate a separate export file.'),
            'emptyEntitiesMessage' => Craft::t('report-manager', 'No sections available.'),
            'selectedEntitiesLabel' => Craft::t('report-manager', 'Selected Sections'),
            'combinedPrimaryColumnLabel' => Craft::t('report-manager', 'Section Name'),
            'dateRangeInstructions' => Craft::t('report-manager', 'Filter entries by date range.'),
            'exportModeInstructions' => Craft::t('report-manager', 'How to handle multiple sections.'),
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
        $entities = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $recordCount = $this->getRecordCount((int) $section->id);

            $entities[] = [
                'id' => (int) $section->id,
                'name' => (string) $section->name,
                'handle' => (string) $section->handle,
                'recordCount' => $recordCount,
                'recordLabel' => Craft::t('report-manager', 'entries'),
            ];
        }

        return $entities;
    }

    /**
     * @inheritdoc
     */
    public function getEntity(int $entityId): ?array
    {
        $section = Craft::$app->getEntries()->getSectionById($entityId);

        if ($section === null) {
            return null;
        }

        return [
            'id' => (int) $section->id,
            'name' => (string) $section->name,
            'handle' => (string) $section->handle,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getEntityFields(int $entityId): array
    {
        $section = Craft::$app->getEntries()->getSectionById($entityId);

        if ($section === null) {
            return [];
        }

        $fields = [
            ['handle' => 'id', 'label' => Craft::t('report-manager', 'Entry ID'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'title', 'label' => Craft::t('report-manager', 'Title'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'slug', 'label' => Craft::t('report-manager', 'Slug'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'uri', 'label' => Craft::t('report-manager', 'URI'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'status', 'label' => Craft::t('report-manager', 'Status'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'siteId', 'label' => Craft::t('report-manager', 'Site ID'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'siteHandle', 'label' => Craft::t('report-manager', 'Site Handle'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'siteName', 'label' => Craft::t('report-manager', 'Site Name'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'dateCreated', 'label' => Craft::t('report-manager', 'Date Created'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'dateUpdated', 'label' => Craft::t('report-manager', 'Date Updated'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'postDate', 'label' => Craft::t('report-manager', 'Post Date'), 'type' => 'system', 'exportable' => true],
            ['handle' => 'expiryDate', 'label' => Craft::t('report-manager', 'Expiry Date'), 'type' => 'system', 'exportable' => true],
        ];

        $seenHandles = array_fill_keys(array_column($fields, 'handle'), true);

        foreach ($section->getEntryTypes() as $entryType) {
            $fieldLayout = $entryType->getFieldLayout();

            if (!method_exists($fieldLayout, 'getCustomFields')) {
                continue;
            }

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
        $query = Entry::find()
            ->sectionId($entityId)
            ->status(null)
            ->orderBy(['dateCreated' => SORT_DESC]);

        $this->applyQueryOptions($query, $options);

        return $query->all();
    }

    /**
     * @inheritdoc
     */
    public function getRecordCount(int $entityId, array $options = []): int
    {
        $query = Entry::find()
            ->sectionId($entityId)
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
            'sectionId' => $entityId,
            'dateRange' => $dateRange,
            'currentPeriod' => [
                'entries' => $currentCount,
                'change' => 0,
            ],
            'totals' => [
                'entries' => $totalCount,
            ],
            'averagePerDay' => 0,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getTrendData(int $entityId, string $dateRange = 'last30days'): array
    {
        $entries = $this->getRecords($entityId, ['dateRange' => $dateRange]);
        $dateFormat = $this->getDateFormatForRange($dateRange);
        $trendData = [];

        foreach ($entries as $entry) {
            if (!$entry instanceof Entry || $entry->dateCreated === null) {
                continue;
            }

            $date = $entry->dateCreated->format($dateFormat);
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
        $entries = $this->getRecords($entityId, $options);
        $rows = [];

        foreach ($entries as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }

            $row = [];
            foreach ($handles as $handle) {
                $row[] = $this->getEntryFieldValue($entry, $handle);
            }
            $rows[] = $row;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * Apply shared entry query options.
     *
     * @param \craft\elements\db\EntryQuery $query Entry query
     * @param array $options Query options
     */
    private function applyQueryOptions(\craft\elements\db\EntryQuery $query, array $options): void
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
     * Get a field value from an entry.
     *
     * @param Entry $entry Entry element
     * @param string $handle Field handle
     * @return string
     */
    private function getEntryFieldValue(Entry $entry, string $handle): string
    {
        return match ($handle) {
            'id' => (string) $entry->id,
            'title' => (string) $entry->title,
            'slug' => (string) $entry->slug,
            'uri' => (string) $entry->uri,
            'status' => (string) $entry->getStatus(),
            'siteId' => (string) $entry->siteId,
            'siteHandle' => $entry->site->handle ?? '',
            'siteName' => $entry->site->name ?? '',
            'dateCreated' => $this->formatExportDate($entry->dateCreated),
            'dateUpdated' => $this->formatExportDate($entry->dateUpdated),
            'postDate' => $this->formatExportDate($entry->postDate),
            'expiryDate' => $this->formatExportDate($entry->expiryDate),
            default => $this->formatExportValue($entry->getFieldValue($handle)),
        };
    }
}

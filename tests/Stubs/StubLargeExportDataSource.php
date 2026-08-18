<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Stubs;

use lindemannrock\reportmanager\datasources\DataSourceInterface;

/**
 * Deterministic data source for bounded standard-export coverage.
 *
 * @since 5.5.2
 */
final class StubLargeExportDataSource implements DataSourceInterface
{
    public const PRIMARY_ENTITY_ID = 1;
    public const SECONDARY_ENTITY_ID = 2;

    /** @var list<array{entityId: int, fieldHandles: string[], limit: int, offset: int}> */
    public static array $exportRequests = [];

    public static function handle(): string
    {
        return '__rm_test_large_export';
    }

    public static function displayName(): string
    {
        return 'Large Export Test Source';
    }

    public static function description(): string
    {
        return 'Supplies deterministic rows for standard export tests.';
    }

    public static function uiLabels(): array
    {
        return [
            'entitySingular' => 'Dataset',
            'entityPlural' => 'Datasets',
            'recordSingular' => 'Row',
            'recordPlural' => 'Rows',
            'combinedPrimaryColumnLabel' => 'Dataset Name',
        ];
    }

    public static function capabilities(): array
    {
        return [
            'fields' => true,
            'dateRanges' => true,
            'analytics' => false,
            'combinedExport' => true,
            'siteFiltering' => false,
            'scheduling' => true,
        ];
    }

    public static function dateFieldOptions(): array
    {
        return [['value' => 'dateCreated', 'label' => 'Date Created']];
    }

    public static function defaultDateField(): string
    {
        return 'dateCreated';
    }

    public static function iconUrl(): ?string
    {
        return null;
    }

    public static function isAvailable(): bool
    {
        return true;
    }

    public static function reset(): void
    {
        self::$exportRequests = [];
    }

    public function getAvailableEntities(): array
    {
        return [
            $this->getEntity(self::PRIMARY_ENTITY_ID),
            $this->getEntity(self::SECONDARY_ENTITY_ID),
        ];
    }

    public function getEntity(int $entityId): ?array
    {
        return match ($entityId) {
            self::PRIMARY_ENTITY_ID => [
                'id' => self::PRIMARY_ENTITY_ID,
                'name' => 'Primary Dataset',
                'handle' => 'primary-dataset',
            ],
            self::SECONDARY_ENTITY_ID => [
                'id' => self::SECONDARY_ENTITY_ID,
                'name' => 'Secondary Dataset',
                'handle' => 'secondary-dataset',
            ],
            default => null,
        };
    }

    public function getEntityFields(int $entityId): array
    {
        $fields = [
            ['handle' => 'id', 'label' => 'Identifier', 'type' => 'number', 'exportable' => true],
            ['handle' => 'shared', 'label' => 'Shared Value', 'type' => 'text', 'exportable' => true],
        ];

        $fields[] = $entityId === self::SECONDARY_ENTITY_ID
            ? ['handle' => 'secondary', 'label' => 'Secondary Value', 'type' => 'text', 'exportable' => true]
            : ['handle' => 'primary', 'label' => 'Primary Value', 'type' => 'text', 'exportable' => true];

        return $fields;
    }

    public function getRecords(int $entityId, array $options = []): array
    {
        $limit = max(0, (int)($options['limit'] ?? $this->getRecordCount($entityId)));
        $offset = max(0, (int)($options['offset'] ?? 0));
        $end = min($offset + $limit, $this->getRecordCount($entityId));
        $records = [];

        for ($index = $offset; $index < $end; $index++) {
            $records[] = $this->record($entityId, $index);
        }

        return $records;
    }

    public function getRecordCount(int $entityId, array $options = []): int
    {
        return match ($entityId) {
            self::PRIMARY_ENTITY_ID => 1205,
            self::SECONDARY_ENTITY_ID => 75,
            default => 0,
        };
    }

    public function getAnalytics(int $entityId, string $dateRange = 'last30days'): array
    {
        return [];
    }

    public function getTrendData(int $entityId, string $dateRange = 'last30days'): array
    {
        return ['labels' => [], 'values' => []];
    }

    public function exportToArray(int $entityId, array $fieldHandles = [], array $options = []): array
    {
        $limit = max(1, (int)($options['limit'] ?? $this->getRecordCount($entityId)));
        $offset = max(0, (int)($options['offset'] ?? 0));
        self::$exportRequests[] = [
            'entityId' => $entityId,
            'fieldHandles' => array_values($fieldHandles),
            'limit' => $limit,
            'offset' => $offset,
        ];

        $fields = $this->getEntityFields($entityId);
        if ($fieldHandles !== []) {
            $fields = array_values(array_filter(
                $fields,
                static fn(array $field): bool => in_array($field['handle'], $fieldHandles, true),
            ));
        }

        $records = $this->getRecords($entityId, ['limit' => $limit, 'offset' => $offset]);

        return [
            'headers' => array_column($fields, 'label'),
            'rows' => array_map(
                static fn(array $record): array => array_map(
                    static fn(array $field): mixed => $record[$field['handle']],
                    $fields,
                ),
                $records,
            ),
        ];
    }

    public function getSettingsHtml(): ?string
    {
        return null;
    }

    /** @return array{id: int, shared: string, primary: string, secondary: string} */
    private function record(int $entityId, int $index): array
    {
        return [
            'id' => $index + 1,
            'shared' => $index === 0 ? '=SUM(1,1)' : "shared-{$entityId}-{$index}",
            'primary' => "primary-{$index}",
            'secondary' => "secondary-{$index}",
        ];
    }
}

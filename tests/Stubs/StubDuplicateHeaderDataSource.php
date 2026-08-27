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
 * Deterministic custom source with repeated human field labels.
 *
 * @since 5.6.0
 */
final class StubDuplicateHeaderDataSource implements DataSourceInterface
{
    public const PRIMARY_ENTITY_ID = 1;
    public const SECONDARY_ENTITY_ID = 2;

    /** @var list<array{entityId: int, limit: int, offset: int}> */
    public static array $exportRequests = [];

    public static bool $returnDriftedHeaders = false;

    public static function handle(): string
    {
        return '__rm_test_duplicate_headers';
    }

    public static function displayName(): string
    {
        return 'Duplicate Header Test Source';
    }

    public static function description(): string
    {
        return 'Supplies lossless duplicate-header export fixtures.';
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
            'dateRanges' => false,
            'analytics' => false,
            'combinedExport' => true,
            'siteFiltering' => false,
            'scheduling' => true,
        ];
    }

    public static function dateFieldOptions(): array
    {
        return [];
    }

    public static function defaultDateField(): string
    {
        return '';
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
        self::$returnDriftedHeaders = false;
    }

    public function getAvailableEntities(): array
    {
        return [$this->getEntity(self::PRIMARY_ENTITY_ID), $this->getEntity(self::SECONDARY_ENTITY_ID)];
    }

    public function getEntity(int $entityId): ?array
    {
        return match ($entityId) {
            self::PRIMARY_ENTITY_ID => ['id' => $entityId, 'name' => 'Primary Dataset', 'handle' => 'primary'],
            self::SECONDARY_ENTITY_ID => ['id' => $entityId, 'name' => 'Secondary Dataset', 'handle' => 'secondary'],
            default => null,
        };
    }

    public function getEntityFields(int $entityId): array
    {
        $fields = [
            ['handle' => 'alpha', 'label' => 'Repeated', 'type' => 'text', 'exportable' => true],
        ];

        $fields[] = $entityId === self::PRIMARY_ENTITY_ID
            ? ['handle' => 'beta', 'label' => 'Repeated', 'type' => 'text', 'exportable' => true]
            : ['handle' => 'gamma', 'label' => 'Repeated', 'type' => 'text', 'exportable' => true];

        $fields[] = ['handle' => 'primaryCollision', 'label' => 'Dataset Name', 'type' => 'text', 'exportable' => true];
        $fields[] = ['handle' => 'unique', 'label' => 'Exact Unique', 'type' => 'text', 'exportable' => true];

        return $fields;
    }

    public function getRecords(int $entityId, array $options = []): array
    {
        $limit = max(0, (int)($options['limit'] ?? $this->getRecordCount($entityId)));
        $offset = max(0, (int)($options['offset'] ?? 0));
        $end = min($offset + $limit, $this->getRecordCount($entityId));
        $records = [];

        for ($index = $offset; $index < $end; $index++) {
            $records[] = [
                'alpha' => "alpha-{$entityId}-{$index}",
                'beta' => "beta-{$index}",
                'gamma' => "gamma-{$index}",
                'primaryCollision' => "collision-{$entityId}-{$index}",
                'unique' => "unique-{$entityId}-{$index}",
            ];
        }

        return $records;
    }

    public function getRecordCount(int $entityId, array $options = []): int
    {
        return $entityId === self::PRIMARY_ENTITY_ID ? 205 : 2;
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
        self::$exportRequests[] = ['entityId' => $entityId, 'limit' => $limit, 'offset' => $offset];

        $fields = $this->getEntityFields($entityId);
        if ($fieldHandles !== []) {
            $fields = array_values(array_filter(
                $fields,
                static fn(array $field): bool => in_array($field['handle'], $fieldHandles, true),
            ));
        }

        $headers = array_column($fields, 'label');
        if (self::$returnDriftedHeaders) {
            $headers[0] = 'Unexpected Header';
        }

        return [
            'headers' => $headers,
            'rows' => array_map(
                static fn(array $record): array => array_map(
                    static fn(array $field): mixed => $record[$field['handle']],
                    $fields,
                ),
                $this->getRecords($entityId, ['limit' => $limit, 'offset' => $offset]),
            ),
        ];
    }

    public function getSettingsHtml(): ?string
    {
        return null;
    }
}

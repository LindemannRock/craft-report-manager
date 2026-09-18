<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\tests\Stubs;

use lindemannrock\reportmanager\datasources\BaseDataSource;
use lindemannrock\reportmanager\datasources\ResumableDataSourceInterface;

/**
 * Registered source with explicit durable identities and deterministic values.
 *
 * @since 5.7.0
 */
class ResumableExportSource extends BaseDataSource implements ResumableDataSourceInterface
{
    public static int $count = 205;
    public static ?int $removed = null;
    public static ?int $bad = null;
    public static array $entityCounts = [];
    public static int $fieldCount = 2;

    public static function supportsExportContinuation(): bool
    {
        return true;
    }

    public static function handle(): string
    {
        return '__rm_test_resumable';
    }

    public static function displayName(): string
    {
        return 'Resumable test source';
    }

    public static function description(): string
    {
        return 'Durable ordered source identities';
    }

    public static function isAvailable(): bool
    {
        return true;
    }

    public function getAvailableEntities(): array
    {
        return [$this->getEntity(1), $this->getEntity(2)];
    }

    public function getEntity(int $entityId): ?array
    {
        return ['id' => $entityId, 'name' => 'Form ' . $entityId, 'handle' => self::handle() . '-' . $entityId];
    }

    public function getEntityFields(int $entityId): array
    {
        $fields = [
            ['handle' => 'id', 'label' => 'ID', 'type' => 'number', 'exportable' => true],
            ['handle' => 'value', 'label' => 'Value', 'type' => 'text', 'exportable' => true],
        ];
        for ($index = 2; $index < self::$fieldCount; $index++) {
            $fields[] = ['handle' => 'value' . $index, 'label' => 'Value ' . $index, 'type' => 'text', 'exportable' => true];
        }
        return $fields;
    }

    public function getRecordCount(int $entityId, array $options = []): int
    {
        return self::$entityCounts[$entityId] ?? self::$count;
    }

    public function getRecords(int $entityId, array $options = []): array
    {
        return [];
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
        throw new \LogicException('The continuation source must be read by captured identity.');
    }

    public function getExportSelection(int $entityId, array $options = []): iterable
    {
        for ($id = 1; $id <= $this->getRecordCount($entityId); $id++) {
            yield ['id' => $id];
        }
    }

    public function exportSelectedRecord(int $entityId, array $identity, array $fieldHandles, array $options = []): array
    {
        if ($identity['id'] === self::$bad) {
            throw new \RuntimeException('The source record could not be serialized.');
        }

        $row = [$identity['id'], $identity['id'] === 1 ? '=1+1' : 'العربية English ' . $entityId];
        for ($index = 2; $index < self::$fieldCount; $index++) {
            $row[] = str_repeat('قيمة نموذجية English value ', 4) . $index;
        }

        return [
            'headers' => array_column($this->getEntityFields($entityId), 'label'),
            'rows' => $identity['id'] === self::$removed ? [] : [$row],
        ];
    }
}

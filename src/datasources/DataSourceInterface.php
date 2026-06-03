<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\datasources;

/**
 * Data Source Interface
 *
 * Defines the contract for report data sources.
 * Each data source (Formie, Freeform, Craft entries, etc.) must implement this interface.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
interface DataSourceInterface
{
    /**
     * Get the data source type handle
     *
     * @return string Unique handle (e.g., 'formie', 'survey-campaigns')
     */
    public static function handle(): string;

    /**
     * Get the display name for the data source
     *
     * @return string Human-readable name (e.g., 'Formie', 'Survey Campaigns')
     */
    public static function displayName(): string;

    /**
     * Get the data source description
     *
     * @return string Short description of the data source
     */
    public static function description(): string;

    /**
     * Get UI labels for this data source.
     *
     * @return array<string, string>
     */
    public static function uiLabels(): array;

    /**
     * Get supported data-source capabilities.
     *
     * @return array<string, bool>
     */
    public static function capabilities(): array;

    /**
     * Get the date fields this source can filter a report's date range by.
     *
     * @return array<array{value: string, label: string}> Ordered; first entry is conventional default
     * @since 5.4.0
     */
    public static function dateFieldOptions(): array;

    /**
     * Get the default date field used when a report has not chosen one.
     *
     * @return string One of the values returned by dateFieldOptions()
     * @since 5.4.0
     */
    public static function defaultDateField(): string;

    /**
     * Get the data source icon URL
     *
     * @return string|null URL to data source icon
     */
    public static function iconUrl(): ?string;

    /**
     * Check if this data source is available (plugin installed/enabled)
     *
     * @return bool
     */
    public static function isAvailable(): bool;

    /**
     * Get available entities for this data source
     *
     * Returns items that can be reported on (e.g., forms for Formie, sections for entries).
     *
     * @return array<array{id: int, name: string, handle: string, recordCount?: int, recordLabel?: string}>
     */
    public function getAvailableEntities(): array;

    /**
     * Get a specific entity by ID
     *
     * @param int $entityId The entity ID
     * @return array{id: int, name: string, handle: string}|null
     */
    public function getEntity(int $entityId): ?array;

    /**
     * Get available fields for an entity
     *
     * Returns fields that can be included in reports/exports
     *
     * @param int $entityId The entity ID
     * @return array<array{handle: string, label: string, type: string, exportable: bool}>
     */
    public function getEntityFields(int $entityId): array;

    /**
     * Get records for an entity
     *
     * @param int $entityId The entity ID
     * @param array $options Query options (dateStart, dateEnd, limit, offset, status, etc.)
     * @return array Array of record data
     */
    public function getRecords(int $entityId, array $options = []): array;

    /**
     * Get total record count for an entity
     *
     * @param int $entityId The entity ID
     * @param array $options Query options (dateStart, dateEnd, status, etc.)
     * @return int
     */
    public function getRecordCount(int $entityId, array $options = []): int;

    /**
     * Get analytics data for an entity
     *
     * Returns aggregated statistics for dashboard display
     *
     * @param int $entityId The entity ID
     * @param string $dateRange Date range (today, last7days, last30days, last90days, all)
     * @return array Analytics data (totals, trends, etc.)
     */
    public function getAnalytics(int $entityId, string $dateRange = 'last30days'): array;

    /**
     * Get record trend data for charts
     *
     * @param int $entityId The entity ID
     * @param string $dateRange Date range
     * @return array{labels: array, values: array}
     */
    public function getTrendData(int $entityId, string $dateRange = 'last30days'): array;

    /**
     * Export records to array format
     *
     * @param int $entityId The entity ID
     * @param array $fieldHandles Field handles to include (empty = all)
     * @param array $options Query options
     * @return array Array of export rows with headers
     */
    public function exportToArray(int $entityId, array $fieldHandles = [], array $options = []): array;

    /**
     * Get the settings HTML for this data source (if any)
     *
     * @return string|null HTML for settings form
     */
    public function getSettingsHtml(): ?string;
}

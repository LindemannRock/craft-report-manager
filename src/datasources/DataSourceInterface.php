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
 * Each data source (Formie, Survey Campaigns, etc.) must implement this interface.
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
     * @since 5.0.0
     */
    public static function handle(): string;

    /**
     * Get the display name for the data source
     *
     * @return string Human-readable name (e.g., 'Formie', 'Survey Campaigns')
     * @since 5.0.0
     */
    public static function displayName(): string;

    /**
     * Get the data source description
     *
     * @return string Short description of the data source
     * @since 5.0.0
     */
    public static function description(): string;

    /**
     * Get the data source icon URL
     *
     * @return string|null URL to data source icon
     * @since 5.0.0
     */
    public static function iconUrl(): ?string;

    /**
     * Check if this data source is available (plugin installed/enabled)
     *
     * @return bool
     * @since 5.0.0
     */
    public static function isAvailable(): bool;

    /**
     * Get available entities for this data source
     *
     * Returns items that can be reported on (e.g., forms for Formie, campaigns for survey-campaigns)
     *
     * @return array<array{id: int, name: string, handle: string, submissionCount: int}>
     * @since 5.0.0
     */
    public function getAvailableEntities(): array;

    /**
     * Get a specific entity by ID
     *
     * @param int $entityId The entity ID
     * @return array{id: int, name: string, handle: string}|null
     * @since 5.0.0
     */
    public function getEntity(int $entityId): ?array;

    /**
     * Get available fields for an entity
     *
     * Returns fields that can be included in reports/exports
     *
     * @param int $entityId The entity ID
     * @return array<array{handle: string, label: string, type: string, exportable: bool}>
     * @since 5.0.0
     */
    public function getEntityFields(int $entityId): array;

    /**
     * Get submissions/records for an entity
     *
     * @param int $entityId The entity ID
     * @param array $options Query options (dateStart, dateEnd, limit, offset, status, etc.)
     * @return array Array of submission data
     * @since 5.0.0
     */
    public function getSubmissions(int $entityId, array $options = []): array;

    /**
     * Get total submission count for an entity
     *
     * @param int $entityId The entity ID
     * @param array $options Query options (dateStart, dateEnd, status, etc.)
     * @return int
     * @since 5.0.0
     */
    public function getSubmissionCount(int $entityId, array $options = []): int;

    /**
     * Get analytics data for an entity
     *
     * Returns aggregated statistics for dashboard display
     *
     * @param int $entityId The entity ID
     * @param string $dateRange Date range (today, last7days, last30days, last90days, all)
     * @return array Analytics data (totals, trends, etc.)
     * @since 5.0.0
     */
    public function getAnalytics(int $entityId, string $dateRange = 'last30days'): array;

    /**
     * Get submission trend data for charts
     *
     * @param int $entityId The entity ID
     * @param string $dateRange Date range
     * @return array{labels: array, values: array}
     * @since 5.0.0
     */
    public function getTrendData(int $entityId, string $dateRange = 'last30days'): array;

    /**
     * Export submissions to array format
     *
     * @param int $entityId The entity ID
     * @param array $fieldHandles Field handles to include (empty = all)
     * @param array $options Query options
     * @return array Array of export rows with headers
     * @since 5.0.0
     */
    public function exportToArray(int $entityId, array $fieldHandles = [], array $options = []): array;

    /**
     * Get the settings HTML for this data source (if any)
     *
     * @return string|null HTML for settings form
     * @since 5.0.0
     */
    public function getSettingsHtml(): ?string;
}

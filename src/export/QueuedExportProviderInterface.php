<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\export;

/**
 * Queued Export Provider Interface
 *
 * Defines a generic queued export provider that can hand arbitrary export
 * payloads to Report Manager without fitting the legacy data source/entity
 * report model.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.3.0
 */
interface QueuedExportProviderInterface
{
    /**
     * Get the provider handle.
     *
     * @return string Unique handle, e.g. `search-manager.analytics`
     */
    public static function handle(): string;

    /**
     * Get the provider display name.
     *
     * @return string Human-readable provider name
     */
    public static function displayName(): string;

    /**
     * Check whether the provider is available in the current install.
     *
     * @return bool
     */
    public static function isAvailable(): bool;

    /**
     * Get supported export formats.
     *
     * @return string[] Supported formats: csv, json, xlsx, zip
     */
    public static function supportedFormats(): array;

    /**
     * Normalize and validate the queued payload before it is persisted.
     *
     * @param array $payload Arbitrary payload from the source plugin
     * @return array Normalized payload that can be JSON-encoded
     */
    public function normalizePayload(array $payload): array;

    /**
     * Get the export display name for this payload.
     *
     * @param array $payload Normalized payload
     * @return string
     */
    public function getExportName(array $payload): string;

    /**
     * Get the filename for this payload and format.
     *
     * The extension may be included or omitted; Report Manager will enforce the
     * correct extension before saving the export record.
     *
     * @param array $payload Normalized payload
     * @param string $format Normalized format
     * @return string
     */
    public function getFilename(array $payload, string $format): string;

    /**
     * Get provider-specific permissions for status/download access.
     *
     * Supported keys are `status` and `download`. If a key is omitted, Report
     * Manager falls back to its own export permissions.
     *
     * @param array $payload Normalized payload
     * @return array<string, string>
     */
    public function getPermissions(array $payload): array;

    /**
     * Generate the queued export.
     *
     * @param array $payload Normalized payload
     * @param QueuedExportContext $context Export context and progress callback
     * @return QueuedExportResult
     */
    public function generate(array $payload, QueuedExportContext $context): QueuedExportResult;
}

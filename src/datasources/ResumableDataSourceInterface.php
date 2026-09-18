<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\datasources;

/**
 * Optional source capability for durable standard exports.
 *
 * Report Manager owns storage, jobs, retries and output. Sources own ordered
 * selection and serialization. Existing DataSourceInterface implementations
 * without this capability keep their single-job behavior.
 *
 * @since 5.6.1
 */
interface ResumableDataSourceInterface extends DataSourceInterface
{
    /** Explicit opt-in; subclasses must not silently inherit a changed export contract. */
    public static function supportsExportContinuation(): bool;

    /**
     * Yield ordered, JSON-serializable identities without hydrating field values.
     *
     * Each identity must distinguish site variants and remain usable in another
     * process. Selection is captured per entity when its selection job runs;
     * it is not a snapshot of field values or of all entities at one instant.
     * A failed capture is discarded and retried, never partly consumed.
     *
     * @return iterable<array<string, int|string>>
     */
    public function getExportSelection(int $entityId, array $options = []): iterable;

    /**
     * Serialize one captured identity, preserving selected field order/headers.
     *
     * Return zero rows if the record has been removed or no longer matches the
     * captured filters. Never replace a missing record with its current offset
     * neighbor. Values are read now; rows already committed are not refreshed.
     *
     * @return array{headers: array, rows: array}
     */
    public function exportSelectedRecord(int $entityId, array $identity, array $fieldHandles, array $options = []): array;
}

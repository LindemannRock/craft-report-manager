<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\export;

/**
 * Base Queued Export Provider
 *
 * Provides conservative defaults for source plugins that only need to implement
 * the actual export generation.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.3.0
 */
abstract class BaseQueuedExportProvider implements QueuedExportProviderInterface
{
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
    public static function supportedFormats(): array
    {
        return ['csv', 'json', 'xlsx'];
    }

    /**
     * @inheritdoc
     */
    public function normalizePayload(array $payload): array
    {
        return $payload;
    }

    /**
     * @inheritdoc
     */
    public function getExportName(array $payload): string
    {
        return static::displayName();
    }

    /**
     * @inheritdoc
     */
    public function getFilename(array $payload, string $format): string
    {
        $handle = preg_replace('/[^a-zA-Z0-9_-]+/', '-', static::handle()) ?: 'export';
        $timestamp = (new \DateTime())->format('Y-m-d_H-i-s');

        return strtolower(trim($handle, '-')) . '_' . $timestamp . '.' . $format;
    }

    /**
     * @inheritdoc
     */
    public function getPermissions(array $payload): array
    {
        return [];
    }
}

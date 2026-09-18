<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\tests\Stubs;

use lindemannrock\reportmanager\datasources\EntriesDataSource;

/**
 * Existing integrations may subclass a built-in without opting into continuation.
 *
 * @since 5.7.0
 */
class LegacyEntryExportSource extends EntriesDataSource
{
    public static function handle(): string
    {
        return '__rm_test_legacy_entries';
    }
}

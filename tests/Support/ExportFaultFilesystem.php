<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\tests\Support;

use craft\fs\Local;

/**
 * Real filesystem operations with controlled publication and cleanup failures.
 *
 * @since 5.6.1
 */
class ExportFaultFilesystem extends Local
{
    public bool $failPublication = false;
    public bool $failCleanup = false;

    public function writeFileFromStream(string $path, $stream, array $config = []): void
    {
        if ($this->failPublication) {
            throw new \RuntimeException('Controlled publication failure.');
        }
        parent::writeFileFromStream($path, $stream, $config);
    }

    public function deleteDirectory(string $path): void
    {
        if ($this->failCleanup) {
            throw new \RuntimeException('Controlled cleanup failure.');
        }
        parent::deleteDirectory($path);
    }
}

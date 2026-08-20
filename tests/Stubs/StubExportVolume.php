<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Stubs;

use craft\base\FsInterface;
use craft\models\Volume;

/**
 * Real Craft volume wrapper with a controlled backing filesystem.
 *
 * @since 5.6.0
 */
final class StubExportVolume extends Volume
{
    public function __construct(
        private readonly FsInterface $stubFilesystem,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function getFs(): FsInterface
    {
        return $this->stubFilesystem;
    }
}

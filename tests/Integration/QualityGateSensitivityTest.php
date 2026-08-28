<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use craft\helpers\App;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * Proves that a forced behavioral failure makes the disposable suite nonzero.
 *
 * @since 5.6.0
 */
final class QualityGateSensitivityTest extends TestCase
{
    public function testForcedFailureMakesRuntimeSuiteNonzero(): void
    {
        self::assertFalse(
            in_array(App::env('REPORT_MANAGER_TEST_FORCE_FAILURE'), ['1', 1, true], true),
            'The forced-failure probe must make PHPUnit return nonzero.',
        );
    }
}

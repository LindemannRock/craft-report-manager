<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\reportmanager\tests\Support\TestProjectBoundary;
use lindemannrock\reportmanager\tests\Support\TestProjectFixtureSeeder;

$vendorRoot = $_SERVER['REPORT_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT'] ?? null;
if (!is_string($vendorRoot) || !is_file($vendorRoot . '/autoload.php')) {
    throw new RuntimeException('The fixture seeder requires an explicit source vendor root.');
}
require $vendorRoot . '/autoload.php';

$boundary = TestProjectBoundary::resolve();
require $boundary->projectRoot . '/bootstrap.php';
require $boundary->vendorRoot . '/craftcms/cms/bootstrap/console.php';

$identity = (new TestProjectFixtureSeeder($boundary))->seed();
fwrite(STDOUT, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

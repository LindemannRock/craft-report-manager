<?php

/**
 * PHPUnit bootstrap for the report-manager plugin.
 *
 * Delegates to the shared base-plugin bootstrap, which initialises Craft as a
 * console application. Tests run against the live DDEV database, but the Craft
 * queue is replaced with a connection-local temporary table before plugins
 * bootstrap. Other cleanup is by marker (see `tests/TestCase.php`).
 *
 * @since 5.3.0
 */

declare(strict_types=1);

use lindemannrock\reportmanager\tests\Support\IsolatedQueue;
use lindemannrock\reportmanager\tests\Support\TestProjectBoundary;

if (!function_exists('craft_modify_app_config')) {
    /** Install the shadow queue before Craft bootstraps enabled plugins. */
    function craft_modify_app_config(array &$config, string $appType): void
    {
        if ($appType !== 'console') {
            throw new \RuntimeException('Report Manager tests require Craft\'s console application.');
        }

        $queueConfig = $config['components']['queue'] ?? [];
        if (!is_array($queueConfig)) {
            throw new \RuntimeException('Report Manager tests require an array-configured Craft queue.');
        }

        $queueConfig['class'] = IsolatedQueue::class;
        $queueConfig['proxyQueue'] = null;
        $config['components']['queue'] = $queueConfig;
    }
}

$boundary = TestProjectBoundary::resolve();
$baseBootstrap = null;
foreach ([
    dirname(__DIR__) . '/vendor/lindemannrock/craft-plugin-base/src/testing/bootstrap.php',
    $boundary->vendorRoot . '/lindemannrock/craft-plugin-base/src/testing/bootstrap.php',
    dirname(__DIR__, 3) . '/vendor/lindemannrock/craft-plugin-base/src/testing/bootstrap.php',
] as $candidate) {
    if (file_exists($candidate)) {
        $baseBootstrap = $candidate;
        break;
    }
}

if ($baseBootstrap === null) {
    fwrite(STDERR, "Base plugin testing bootstrap not found in package or workspace vendor.\n");
    fwrite(STDERR, "Run `composer install` and ensure the required LindemannRock Base version is present.\n");
    exit(1);
}

require_once $baseBootstrap;

\lindemannrock\base\testing\bootstrap($boundary->projectRoot);

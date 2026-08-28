<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\reportmanager\tests\Support\AcceptedSuiteAuthority;

$packageRoot = dirname(__DIR__);
require_once $packageRoot . '/tests/Support/AcceptedSuiteAuthority.php';

try {
    $baseline = AcceptedSuiteAuthority::load($packageRoot . '/tests/accepted-suite.json');
    $actual = AcceptedSuiteAuthority::inspectDeclared($packageRoot . '/tests', $packageRoot);
    AcceptedSuiteAuthority::assertDeclared($baseline['declared'], $actual);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Test conventions passed: {$actual['integrationClasses']} integration classes, {$actual['testMethods']} test methods; accepted authority exact.\n");

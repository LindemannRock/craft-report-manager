<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Support;

use lindemannrock\reportmanager\ReportManager;
use ReflectionClass;

/**
 * Validates the runner-owned Craft project and current package candidate.
 *
 * @since 5.6.0
 */
final readonly class TestProjectBoundary
{
    public const PROJECT_ROOT_ENV = 'REPORT_MANAGER_TEST_PROJECT_ROOT';
    public const DISPOSABLE_ENV = 'REPORT_MANAGER_TEST_PROJECT_DISPOSABLE';

    private function __construct(
        public string $packageRoot,
        public string $projectRoot,
        public string $vendorRoot,
        public bool $disposable,
    ) {
    }

    /** @param array<string, mixed>|null $environment */
    public static function resolve(?array $environment = null, ?string $packageRoot = null): self
    {
        $environment ??= array_merge($_ENV, $_SERVER);
        $packageRoot = self::directory($packageRoot ?? dirname(__DIR__, 2), 'package root');
        $configuredRoot = $environment[self::PROJECT_ROOT_ENV] ?? null;
        $disposable = in_array($environment[self::DISPOSABLE_ENV] ?? null, [1, '1', true, 'true', 'yes'], true);
        if (!$disposable || !is_string($configuredRoot) || $configuredRoot === '') {
            throw new \RuntimeException(
                'Report Manager PHPUnit requires the package disposable runner; direct owner-project execution is refused.',
            );
        }
        $projectRoot = self::directory($configuredRoot, 'disposable test project root');
        $expectedProjectRoot = '#^' . preg_quote(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR), '#')
            . '/report-manager-fixture-[a-f0-9]{16}$#';
        if (preg_match($expectedProjectRoot, $projectRoot) !== 1) {
            throw new \RuntimeException('The test project is outside the disposable fixture boundary.');
        }
        $vendorRoot = self::directory($projectRoot . '/vendor', 'test project vendor root');
        foreach ([
            $projectRoot . '/bootstrap.php',
            $vendorRoot . '/autoload.php',
            $vendorRoot . '/craftcms/cms/bootstrap/console.php',
            $vendorRoot . '/lindemannrock/craft-plugin-base/src/testing/bootstrap.php',
        ] as $required) {
            if (!is_file($required)) {
                throw new \RuntimeException("Required disposable test file is missing: {$required}");
            }
        }
        $loadedPackageRoot = dirname((new ReflectionClass(ReportManager::class))->getFileName(), 2);
        if (realpath($loadedPackageRoot) !== realpath($packageRoot)) {
            throw new \RuntimeException(
                "Disposable tests loaded Report Manager from {$loadedPackageRoot}, not the current candidate {$packageRoot}.",
            );
        }

        return new self($packageRoot, $projectRoot, $vendorRoot, true);
    }

    private static function directory(string $path, string $label): string
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0")) {
            throw new \InvalidArgumentException("The {$label} must be an absolute path.");
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \RuntimeException("The {$label} does not exist: {$path}");
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }
}

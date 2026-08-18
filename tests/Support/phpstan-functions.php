<?php
/**
 * PHPStan function declarations for package-local analysis.
 *
 * @internal
 */

declare(strict_types=1);

namespace lindemannrock\base\testing;

if (!function_exists(__NAMESPACE__ . '\\bootstrap')) {
    /** Static-analysis declaration for Base's test bootstrap function. */
    function bootstrap(?string $projectRoot = null): void
    {
    }
}

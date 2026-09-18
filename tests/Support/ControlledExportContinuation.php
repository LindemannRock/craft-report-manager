<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\tests\Support;

use lindemannrock\reportmanager\export\ExportContinuation;

/**
 * Fault and clock seams at the real durable commit boundaries.
 *
 * @since 5.6.1
 */
class ControlledExportContinuation extends ExportContinuation
{
    public static ?string $interruptAt = null;
    public static float $rowBudget = 90;
    public static array $boundaries = [];
    public static bool $terminateProcess = false;

    protected function boundary(string $name): void
    {
        self::$boundaries[] = $name;
        if (self::$interruptAt === $name) {
            if (self::$terminateProcess) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            self::$interruptAt = null;
            throw new \RuntimeException('Controlled interruption at ' . $name);
        }
    }

    protected function rowSeconds(): float
    {
        return self::$rowBudget;
    }
}

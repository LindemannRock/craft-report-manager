<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Stubs;

/**
 * File-like stream whose metadata resolves but whose content cannot be opened.
 *
 * This provides a deterministic failed-read seam without filesystem permission
 * assumptions or platform-specific chmod behavior.
 *
 * @since 5.6.0
 */
final class UnreadableProviderFileStreamWrapper
{
    /** @var resource|null Stream context injected by PHP. */
    public mixed $context = null;

    public static int $statCalls = 0;
    public static int $openCalls = 0;

    public static function reset(): void
    {
        self::$statCalls = 0;
        self::$openCalls = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$openCalls++;

        return false;
    }

    /** @return array<int|string, int> */
    public function url_stat(string $path, int $flags): array
    {
        self::$statCalls++;
        $mode = 0100644;
        $size = 19;

        return [
            2 => $mode,
            7 => $size,
            'mode' => $mode,
            'size' => $size,
        ];
    }
}

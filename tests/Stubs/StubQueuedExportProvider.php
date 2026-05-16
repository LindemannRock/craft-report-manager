<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Stubs;

use lindemannrock\reportmanager\export\BaseQueuedExportProvider;
use lindemannrock\reportmanager\export\QueuedExportContext;
use lindemannrock\reportmanager\export\QueuedExportResult;

/**
 * Test-only queued export provider stub.
 *
 * Records every call into {@see normalizePayload()}, {@see getPermissions()},
 * and {@see generate()} so tests can assert routing + payload roundtripping.
 * The {@see $nextResult} hook lets tests force success/failure paths
 * deterministically.
 *
 * State is held on STATIC properties because
 * {@see \lindemannrock\reportmanager\services\QueuedExportProvidersService::getProvider()}
 * caches a single instance per handle; tests interact with the stub via the
 * class, not whichever instance the service happens to hand back.
 *
 * Register the class by swapping the whole {@see QueuedExportProvidersService}
 * (see `TestCase::installStubProviderService()`) — the registry caches its
 * event-driven provider list, so a clean component swap is required to seed it.
 *
 * @since 5.4.0
 */
final class StubQueuedExportProvider extends BaseQueuedExportProvider
{
    /**
     * Result returned from {@see generate()}. If the value is a `\Throwable`,
     * {@see generate()} throws it instead — the test path covers
     * `ExportService::generateQueuedExport()`'s catch arm and the FAILED status
     * transition.
     *
     * @var QueuedExportResult|\Throwable|null
     */
    public static QueuedExportResult|\Throwable|null $nextResult = null;

    /**
     * Payload values passed to {@see generate()}, in order. Tests can assert
     * that `ExportService::generateQueuedExport()` round-tripped what
     * {@see normalizePayload()} produced through the DB column.
     *
     * @var list<array<string, mixed>>
     */
    public static array $generateCalls = [];

    /**
     * Permissions returned from {@see getPermissions()}.
     *
     * @var array<string, string>
     */
    public static array $permissions = [];

    /**
     * Extra key added to every normalized payload to prove the normalize hook
     * actually ran. Tests assert this key survives onto the persisted record.
     */
    public const NORMALIZED_MARKER = '__rm_test_normalized';

    public static function handle(): string
    {
        return '__rm_test_provider';
    }

    public static function displayName(): string
    {
        return 'Test Stub Provider';
    }

    public static function supportedFormats(): array
    {
        return ['csv', 'json', 'xlsx'];
    }

    public function normalizePayload(array $payload): array
    {
        $payload[self::NORMALIZED_MARKER] = true;

        return $payload;
    }

    public function getPermissions(array $payload): array
    {
        return self::$permissions;
    }

    public function generate(array $payload, QueuedExportContext $context): QueuedExportResult
    {
        self::$generateCalls[] = $payload;

        if (self::$nextResult instanceof \Throwable) {
            throw self::$nextResult;
        }

        if (self::$nextResult instanceof QueuedExportResult) {
            return self::$nextResult;
        }

        return QueuedExportResult::table(
            headers: ['col1', 'col2'],
            rows: [['a', 1], ['b', 2]],
        );
    }

    /**
     * Reset every static recorder + hook back to defaults. Called from
     * {@see \lindemannrock\reportmanager\tests\TestCase::installStubProviderService()}
     * at the start of every test that registers the stub.
     */
    public static function reset(): void
    {
        self::$nextResult = null;
        self::$generateCalls = [];
        self::$permissions = [];
    }
}

<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Integration;

use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\TestCase;

/**
 * {@see \lindemannrock\reportmanager\services\QueuedExportProvidersService}
 * registry lookup behaviour.
 *
 * Pins the contract that powers every queued export: the service caches
 * registered providers after firing
 * `EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS` once and a missing handle
 * returns null without throwing — `ExportService::createQueuedExport()`
 * relies on the null branch to convert "unknown provider" into a clean
 * `InvalidArgumentException` instead of a fatal type error.
 *
 * @since 5.4.0
 */
final class QueuedExportProvidersServiceTest extends TestCase
{
    public function testGetProviderReturnsStubWhenRegisteredViaEvent(): void
    {
        $this->installStubProviderService();

        $provider = $this->queuedExportProviders->getProvider(StubQueuedExportProvider::handle());

        self::assertInstanceOf(StubQueuedExportProvider::class, $provider);
        self::assertTrue($this->queuedExportProviders->isProviderAvailable(StubQueuedExportProvider::handle()));
    }

    public function testGetProviderReturnsNullForUnknownHandle(): void
    {
        $this->installStubProviderService();

        self::assertNull($this->queuedExportProviders->getProvider('__rm_test_missing_handle'));
        self::assertFalse($this->queuedExportProviders->isProviderAvailable('__rm_test_missing_handle'));
    }
}

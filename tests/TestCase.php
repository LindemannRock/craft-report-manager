<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests;

use Craft;
use craft\queue\BaseJob;
use craft\queue\Queue;
use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\reportmanager\models\Settings;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\services\ExportCleanupScheduler;
use lindemannrock\reportmanager\services\ExportService;
use lindemannrock\reportmanager\services\QueuedExportProvidersService;
use lindemannrock\reportmanager\services\ReportsService;
use lindemannrock\reportmanager\tests\Stubs\StubQueuedExportProvider;
use lindemannrock\reportmanager\tests\Support\IsolatedQueue;
use Throwable;

/**
 * Base test case for report-manager integration tests.
 *
 * Layers plugin-specific shorthand on top of {@see IntegrationTestCase}:
 *  - direct accessors for the `exports` / `queuedExportProviders` / `reports`
 *    services
 *  - a marker prefix that rides through both the export record's `dataSource`
 *    column AND every generated file's filename — one prefix cleans both
 *  - {@see installStubProviderService()} for tests that need a queued export
 *    provider registry isolated from anything other plugins may have wired in
 *    via the EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS event
 *  - {@see cleanupExternalState()} override that purges marker-tagged files
 *    from the local export storage directory between tests
 *
 * @since 5.4.0
 */
abstract class TestCase extends IntegrationTestCase
{
    private static ?self $activeTest = null;

    /**
     * Marker prefix used for every test-seeded row + generated file.
     *
     * Picked so `purgeRowsByMarker`'s LIKE wildcard handling won't trip on
     * unintended regex characters, and so the same prefix can be used as a
     * file-glob pattern in {@see cleanupExternalState()}. Plain ASCII +
     * underscores only.
     */
    protected const MARKER = '__rm_test_';

    protected ExportService $exports;

    protected QueuedExportProvidersService $queuedExportProviders;

    protected ReportsService $reports;
    protected ExportCleanupScheduler $exportCleanupScheduler;

    /** @var array<string, mixed>|null */
    private ?array $settingsSnapshot = null;
    /** @var array<string, object> */
    private array $appComponentSnapshots = [];
    private ?object $originalQueue = null;
    private bool $isolationFinished = false;
    private bool $baseStateInitialised = false;

    protected function setUp(): void
    {
        self::$activeTest = $this;
        $this->isolationFinished = false;

        try {
            parent::setUp();
            $this->baseStateInitialised = true;
            $this->snapshotAppComponents();
            $this->settingsSnapshot = ReportManager::$plugin->getSettings()->getAttributes();
            $this->isolateQueue();
            $plugin = ReportManager::$plugin;
            $this->exports = $plugin->exports;
            $this->queuedExportProviders = $plugin->queuedExportProviders;
            $this->reports = $plugin->reports;
            $this->exportCleanupScheduler = $plugin->exportCleanupScheduler;
            $this->purgeTestRows();
        } catch (Throwable $exception) {
            try {
                $this->finishIsolation();
            } catch (Throwable $cleanupException) {
                fwrite(STDERR, 'Report Manager setup cleanup failed: ' . $cleanupException->getMessage() . PHP_EOL);
            }
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        $this->finishIsolation();
    }

    /**
     * Runner fallback when child teardown exits before parent cleanup.
     *
     * @since 5.6.0
     */
    public static function finishActiveTestIsolation(): void
    {
        self::$activeTest?->finishIsolation();
    }

    /** Push one job into the connection-local shadow queue. */
    protected function pushOwnedJob(BaseJob $job, int $delay = 0): int
    {
        return (int)Craft::$app->getQueue()->delay($delay)->push($job);
    }

    /** Remove rows only from the connection-local queue shadow. */
    protected function clearIsolatedQueueRows(): void
    {
        if (!$this->originalQueue instanceof IsolatedQueue) {
            throw new \RuntimeException('The connection-local queue shadow is unavailable.');
        }

        $this->originalQueue->clearShadowRows();
    }

    /**
     * Plugin settings shorthand.
     */
    protected function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = ReportManager::$plugin->getSettings();
        return $settings;
    }

    /**
     * Swap the cached {@see QueuedExportProvidersService} for a fresh instance
     * with the {@see StubQueuedExportProvider} registered via the standard
     * EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS event. The service caches the
     * registered-providers list on first access, so a wholesale component
     * swap is the only reliable way to seed a known registry across tests.
     */
    protected function installStubProviderService(): StubQueuedExportProvider
    {
        StubQueuedExportProvider::reset();

        $service = new QueuedExportProvidersService();
        $service->on(
            QueuedExportProvidersService::EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS,
            static function($event): void {
                $event->providers[StubQueuedExportProvider::handle()] = [
                    'handle' => StubQueuedExportProvider::handle(),
                    'name' => StubQueuedExportProvider::displayName(),
                    'class' => StubQueuedExportProvider::class,
                ];
            }
        );

        $this->swapPluginComponent('report-manager', 'queuedExportProviders', $service);
        $this->queuedExportProviders = $service;
        $this->exports = ReportManager::$plugin->exports;

        return new StubQueuedExportProvider();
    }

    /**
     * Drain marker-tagged export rows. The `dataSource` column on
     * `{{%reportmanager_exports}}` mirrors `providerHandle` for queued exports,
     * so a marker-prefixed handle lands here, and an idle `LIKE` purge by that
     * prefix is enough to drain every test-owned row regardless of status.
     */
    protected function purgeTestRows(): void
    {
        $this->purgeRowsByMarker(ExportRecord::tableName(), 'dataSource', self::MARKER);
        $this->purgeRowsByMarker(ReportRecord::tableName(), 'handle', self::MARKER);
    }

    /**
     * Remove marker-tagged generated files from the local export directory.
     *
     * The export base path is whatever {@see Settings::getExportPath()}
     * resolved at boot — typically `@storage/report-manager/exports/`. Files
     * generated by the queued-export pipeline get filenames starting with the
     * provider handle, but `ExportService::ensureFilenameExtension()` runs
     * `trim($filename, '.-_')` before persisting, which strips the leading
     * underscores off the marker. The matching glob therefore looks for
     * `rm_test_*` (post-trim form) rather than `__rm_test_*` (DB-column form).
     * CP-generated exports never share either prefix.
     */
    protected function cleanupExternalState(): void
    {
        $basePath = $this->exports->getExportBasePath();

        if ($this->exports->isUsingVolume()) {
            return;
        }

        if (!is_dir($basePath)) {
            return;
        }

        $pattern = rtrim($basePath, '/') . '/rm_test_*';

        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function snapshotAppComponents(): void
    {
        foreach (['config', 'mutex'] as $id) {
            if (Craft::$app->has($id)) {
                $component = Craft::$app->get($id);
                if (is_object($component)) {
                    $this->appComponentSnapshots[$id] = $component;
                }
            }
        }
    }

    private function isolateQueue(): void
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof IsolatedQueue) {
            throw new \RuntimeException('Report Manager tests require the bootstrap-isolated Craft queue.');
        }

        $this->originalQueue = $queue;
        $queue->clearShadowRows();

        Craft::$app->set('queue', new Queue([
            'db' => $queue->db,
            'mutex' => $queue->mutex,
            'tableName' => $queue->tableName,
            'channel' => $queue->channel,
            'mutexTimeout' => $queue->mutexTimeout,
        ]));
    }

    private function finishIsolation(): void
    {
        if ($this->isolationFinished) {
            return;
        }
        $this->isolationFinished = true;
        $errors = [];

        $this->runCleanupStep($errors, fn() => $this->purgeTestRows());
        $this->runCleanupStep($errors, function(): void {
            foreach ($this->appComponentSnapshots as $id => $component) {
                Craft::$app->set($id, $component);
            }
            $this->appComponentSnapshots = [];
        });
        $this->runCleanupStep($errors, function(): void {
            if ($this->settingsSnapshot !== null) {
                ReportManager::$plugin->getSettings()->setAttributes($this->settingsSnapshot, false);
                $this->settingsSnapshot = null;
            }
        });
        $this->runCleanupStep($errors, function(): void {
            if ($this->originalQueue !== null) {
                Craft::$app->set('queue', $this->originalQueue);
                if ($this->originalQueue instanceof IsolatedQueue) {
                    $this->originalQueue->clearShadowRows();
                }
                $this->originalQueue = null;
            }
        });

        if ($this->baseStateInitialised) {
            $this->runCleanupStep($errors, fn() => parent::tearDown());
            $this->baseStateInitialised = false;
        }
        self::$activeTest = null;

        if ($errors !== []) {
            $messages = array_map(
                static fn(Throwable $error): string => $error::class . ': ' . $error->getMessage(),
                $errors,
            );
            throw new \RuntimeException(
                'Report Manager test isolation cleanup failed: ' . implode(' | ', $messages),
                0,
                $errors[0],
            );
        }
    }

    /** @param list<Throwable> $errors */
    private function runCleanupStep(array &$errors, callable $cleanup): void
    {
        try {
            $cleanup();
        } catch (Throwable $exception) {
            $errors[] = $exception;
        }
    }
}

<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
use lindemannrock\reportmanager\jobs\GenerateExportJob;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\services\DataSourcesService;
use lindemannrock\reportmanager\tests\Stubs\ResumableExportSource;
use lindemannrock\reportmanager\tests\Support\ControlledExportContinuation;
use lindemannrock\reportmanager\tests\Support\ControlledExportService;
use lindemannrock\reportmanager\tests\Support\ExportStepQueue;
use lindemannrock\reportmanager\tests\Support\TestProjectBoundary;

$vendorRoot = $_SERVER['REPORT_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT'] ?? null;
if (!is_string($vendorRoot) || !is_file($vendorRoot . '/autoload.php')) {
    throw new RuntimeException('The export worker requires the disposable fixture vendor.');
}
require $vendorRoot . '/autoload.php';
TestProjectBoundary::resolve();
require dirname(__DIR__, 2) . '/bootstrap.php';

$sources = new DataSourcesService();
$sources->on(DataSourcesService::EVENT_REGISTER_DATA_SOURCES, static function(RegisterDataSourcesEvent $event): void {
    $event->register(ResumableExportSource::handle(), ResumableExportSource::displayName(), ResumableExportSource::class);
});
ReportManager::getInstance()->set('dataSources', $sources);
ReportManager::getInstance()->set('exports', new ControlledExportService());
$queue = new ExportStepQueue(['spoolPath' => $argv[3]]);
Craft::$app->set('queue', $queue);
ControlledExportContinuation::$interruptAt = $argv[4] !== '-' ? $argv[4] : null;
ControlledExportContinuation::$terminateProcess = true;
$job = new GenerateExportJob(['exportId' => (int)$argv[1], 'sequence' => (int)$argv[2]]);
$queue->runReserved($queue->serializer->serialize($job), (int)($argv[5] ?? 1));

<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\reportmanager\tests\Support\DisposableCraftProject;

$packageRoot = dirname(__DIR__, 3);
$vendorRoot = resolveVendorRoot($packageRoot);
require $vendorRoot . '/autoload.php';

try {
    if (($argv[1] ?? null) === '--interrupt-child') {
        $readyPath = $argv[2] ?? null;
        if (!is_string($readyPath) || $readyPath === '') {
            throw new InvalidArgumentException('Interruption child requires a readiness path.');
        }
        (new DisposableCraftProject($packageRoot))->waitForInterruption($readyPath);
    }
    if (($argv[1] ?? null) === '--lifecycle-probe') {
        $result = lifecycleProbe($packageRoot, __FILE__, $vendorRoot);
    } else {
        $result = (new DisposableCraftProject($packageRoot))->run(array_slice($argv, 1));
    }
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    $status = $exception->getCode();
    exit(is_int($status) && $status > 0 && $status < 256 ? $status : 1);
}

function resolveVendorRoot(string $packageRoot): string
{
    $environmentName = 'REPORT_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT';
    $configured = $_SERVER[$environmentName] ?? null;
    $candidates = is_string($configured) && $configured !== ''
        ? [$configured]
        : [$packageRoot . '/vendor', dirname($packageRoot, 2) . '/vendor'];
    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false && is_file($resolved . '/autoload.php') && is_file($resolved . '/bin/phpunit')) {
            $_SERVER[$environmentName] = $resolved;
            return rtrim($resolved, DIRECTORY_SEPARATOR);
        }
    }
    throw new RuntimeException('Unable to resolve a package-local or documented workspace Composer vendor root.', 2);
}

/** @return array<string, mixed> */
function lifecycleProbe(string $packageRoot, string $runnerPath, string $vendorRoot): array
{
    $failure = (new DisposableCraftProject($packageRoot))->runFailureProbe();
    if ($failure['failure'] !== 'Synthetic disposable runner failure.'
        || $failure['status'] !== 73
        || $failure['cleanup'] !== ['projectRemoved' => true, 'databaseRemoved' => true, 'grantRemoved' => true]) {
        throw new RuntimeException('Failure cleanup probe did not preserve status and prove exact cleanup.');
    }
    $cleanupFailure = (new DisposableCraftProject($packageRoot))->runCleanupFailureProbe();
    if ($cleanupFailure['status'] !== 74
        || !str_contains($cleanupFailure['failure'], 'Synthetic cleanup failure')
        || $cleanupFailure['residue'] !== ['projectRemoved' => true, 'databaseRemoved' => true, 'grantRemoved' => true]) {
        throw new RuntimeException('Cleanup-only failure probe did not fail after exact cleanup.');
    }
    $combined = (new DisposableCraftProject($packageRoot))->runCombinedFailureProbe();
    if ($combined['status'] !== 73
        || !str_starts_with($combined['failure'], 'Synthetic disposable runner failure.')
        || !str_contains($combined['failure'], 'cleanup: Synthetic cleanup failure')
        || $combined['residue'] !== ['projectRemoved' => true, 'databaseRemoved' => true, 'grantRemoved' => true]) {
        throw new RuntimeException('Combined failure probe did not preserve the operational failure as primary.');
    }
    if (!function_exists('pcntl_signal')) {
        throw new RuntimeException('The disposable interruption contract requires the pcntl extension.');
    }

    $readyPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/report-manager-interrupt-' . bin2hex(random_bytes(8)) . '.json';
    $environment = [];
    foreach ($_SERVER as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $environment[$name] = $value;
        }
    }
    $environment[DisposableCraftProject::SOURCE_VENDOR_ENV] = $vendorRoot;
    $process = proc_open([PHP_BINARY, $runnerPath, '--interrupt-child', $readyPath], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $packageRoot, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start interruption cleanup probe.');
    }
    fclose($pipes[0]);
    try {
        $deadline = microtime(true) + 15.0;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(100000);
        }
        if (!is_file($readyPath)) {
            throw new RuntimeException('Interruption child did not become ready.');
        }
        $identity = json_decode((string)file_get_contents($readyPath), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($identity)) {
            throw new RuntimeException('Invalid interruption child identity.');
        }
        proc_terminate($process, SIGTERM);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status === 0) {
            throw new RuntimeException('Interrupted runner unexpectedly returned success.');
        }
        $databaseName = $identity['databaseName'] ?? null;
        $projectRoot = $identity['projectRoot'] ?? null;
        $expectedProjectRoot = '#^' . preg_quote(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR), '#')
            . '/report-manager-fixture-[a-f0-9]{16}$#';
        if (!is_string($databaseName) || !preg_match('/^rm_gate_[a-f0-9]{16}$/', $databaseName)
            || !is_string($projectRoot) || preg_match($expectedProjectRoot, $projectRoot) !== 1) {
            throw new RuntimeException('Interrupted runner reported an unsafe resource identity.');
        }
        $pdo = DisposableCraftProject::adminConnection();
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name');
        $statement->execute(['name' => $databaseName]);
        if ((int)$statement->fetchColumn() !== 0 || file_exists($projectRoot)) {
            throw new RuntimeException("Interruption cleanup left owned resources.\n{$stdout}\n{$stderr}");
        }

        return [
            'failureCleanup' => $failure['cleanup'],
            'cleanupFailure' => $cleanupFailure,
            'combinedFailure' => $combined,
            'interruptionCleanup' => ['exitCode' => $status, 'projectRemoved' => true, 'databaseRemoved' => true],
        ];
    } finally {
        if (is_resource($process)) {
            @proc_terminate($process, SIGKILL);
            @proc_close($process);
        }
        if (is_file($readyPath)) {
            @unlink($readyPath);
        }
    }
}

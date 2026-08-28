<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Support;

use PDO;

/**
 * Owns one disposable MySQL Craft project and its exact cleanup lifecycle.
 *
 * @since 5.6.0
 */
final class DisposableCraftProject
{
    public const SOURCE_VENDOR_ENV = 'REPORT_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT';
    public const FAILURE_STAGE_ENV = 'REPORT_MANAGER_FIXTURE_FAIL_STAGE';

    private const DATABASE_PREFIX = 'rm_gate_';
    private const PLUGIN_HANDLES = [
        'logging-library',
        'formie',
        'report-manager',
    ];

    private string $runId;
    private string $projectRoot;
    private string $databaseName;
    private string $vendorRoot;
    private string $securityKey;
    private bool $databaseCreated = false;
    private bool $grantCreated = false;
    private bool $projectCreated = false;
    private bool $cleanupComplete = false;
    private bool $simulateCleanupFailure = false;
    /** @var resource|null */
    private $activeProcess = null;
    /** @var list<array{command: list<string>, exitCode: int, stdout: string, stderr: string}> */
    private array $commands = [];

    public function __construct(private readonly string $packageRoot)
    {
        $this->runId = bin2hex(random_bytes(8));
        $this->projectRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/report-manager-fixture-' . $this->runId;
        $this->databaseName = self::DATABASE_PREFIX . $this->runId;
        $this->vendorRoot = $this->resolveVendorRoot();
        $this->securityKey = bin2hex(random_bytes(32));
    }

    /** @param list<string> $phpunitArguments @return array<string, mixed> */
    public function run(array $phpunitArguments = []): array
    {
        $this->installCleanupGuards();
        $failure = null;
        $phpunit = null;
        $fixture = null;
        try {
            $this->createDatabase();
            $this->createProject();
            $this->installCraft();
            $this->installPlugins();
            $fixture = $this->seedFixture();
            $phpunit = $this->runPhpunit($phpunitArguments);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        $cleanup = $this->cleanupWithFailure($failure);
        if ($failure !== null) {
            throw new \RuntimeException(
                $failure->getMessage() . "\nDisposable command evidence:\n"
                . json_encode($this->commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                . "\nDisposable cleanup evidence:\n"
                . json_encode($cleanup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $this->status($failure, 1),
                $failure,
            );
        }

        return [
            'runId' => $this->runId,
            'projectRoot' => $this->projectRoot,
            'databaseName' => $this->databaseName,
            'fixture' => $fixture,
            'phpunit' => $phpunit,
            'commands' => $this->commands,
            'cleanup' => $cleanup,
            'ownerBoundary' => [
                'ownerDatabaseNameRejected' => 'db',
                'ownerProjectRead' => false,
                'sourceVendorRoot' => $this->vendorRoot,
                'candidateRoot' => $this->packageRoot,
            ],
        ];
    }

    /** @return array{failure: string, status: int, cleanup: array{projectRemoved: bool, databaseRemoved: bool, grantRemoved: bool}} */
    public function runFailureProbe(): array
    {
        $this->installCleanupGuards();
        $failure = null;
        try {
            $this->createDatabase();
            $this->createProject();
            throw new \RuntimeException('Synthetic disposable runner failure.', 73);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $cleanup = $this->cleanupWithFailure(null);

        return ['failure' => $failure->getMessage(), 'status' => $this->status($failure, 1), 'cleanup' => $cleanup];
    }

    /** @return array{failure: string, status: int, residue: array{projectRemoved: bool, databaseRemoved: bool, grantRemoved: bool}} */
    public function runCleanupFailureProbe(): array
    {
        $this->installCleanupGuards();
        $this->createDatabase();
        $this->createProject();
        $this->simulateCleanupFailure = true;
        try {
            $this->cleanup();
            throw new \LogicException('Synthetic cleanup failure was not reported.');
        } catch (\Throwable $exception) {
            return [
                'failure' => $exception->getMessage(),
                'status' => $this->status($exception, 1),
                'residue' => $this->residueState(),
            ];
        }
    }

    /** @return array{failure: string, status: int, residue: array{projectRemoved: bool, databaseRemoved: bool, grantRemoved: bool}} */
    public function runCombinedFailureProbe(): array
    {
        $this->installCleanupGuards();
        $this->createDatabase();
        $this->createProject();
        $this->simulateCleanupFailure = true;
        $operational = new \RuntimeException('Synthetic disposable runner failure.', 73);
        try {
            $this->cleanupWithFailure($operational);
            throw new \LogicException('Combined failure was not reported.');
        } catch (\Throwable $exception) {
            return [
                'failure' => $exception->getMessage(),
                'status' => $this->status($exception, 1),
                'residue' => $this->residueState(),
            ];
        }
    }

    public function waitForInterruption(string $readyPath): never
    {
        $this->installCleanupGuards();
        $this->createDatabase();
        $this->createProject();
        $payload = json_encode([
            'databaseName' => $this->databaseName,
            'projectRoot' => $this->projectRoot,
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($readyPath, $payload) === false) {
            throw new \RuntimeException('Unable to write interruption readiness evidence.');
        }
        for (;;) {
            usleep(100000);
        }
    }

    /** @return array{projectRemoved: bool, databaseRemoved: bool, grantRemoved: bool} */
    public function cleanup(): array
    {
        if ($this->cleanupComplete) {
            return $this->residueState();
        }
        $errors = [];
        if (is_resource($this->activeProcess)) {
            @proc_terminate($this->activeProcess, SIGTERM);
            @proc_close($this->activeProcess);
            $this->activeProcess = null;
        }
        if ($this->grantCreated) {
            try {
                self::adminConnection()->exec("REVOKE ALL PRIVILEGES ON `{$this->databaseName}`.* FROM 'db'@'%'");
                $this->grantCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'grant: ' . $exception->getMessage();
            }
        }
        if ($this->databaseCreated) {
            try {
                self::adminConnection()->exec('DROP DATABASE `' . $this->databaseName . '`');
                $this->databaseCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'database: ' . $exception->getMessage();
            }
        }
        if ($this->projectCreated || is_dir($this->projectRoot)) {
            try {
                $this->removeOwnedProjectRoot();
                $this->projectCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'filesystem: ' . $exception->getMessage();
            }
        }
        $result = $this->residueState();
        foreach ($result as $label => $removed) {
            if (!$removed) {
                $errors[] = "{$label}: exact run-owned resource remains";
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException('Disposable cleanup failed: ' . implode('; ', $errors));
        }
        $this->cleanupComplete = true;
        if ($this->simulateCleanupFailure) {
            throw new \RuntimeException('Synthetic cleanup failure after exact resource removal.', 74);
        }

        return $result;
    }

    public static function adminConnection(): PDO
    {
        $host = self::environment('REPORT_MANAGER_FIXTURE_DB_HOST', 'db');
        $port = self::environment('REPORT_MANAGER_FIXTURE_DB_PORT', '3306');
        $user = self::environment('REPORT_MANAGER_FIXTURE_DB_ADMIN_USER', 'root');
        $password = self::environment('REPORT_MANAGER_FIXTURE_DB_ADMIN_PASSWORD', 'root');

        return new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /** @return array{projectRemoved: bool, databaseRemoved: bool, grantRemoved: bool} */
    private function cleanupWithFailure(?\Throwable $original): array
    {
        try {
            return $this->cleanup();
        } catch (\Throwable $cleanupFailure) {
            if ($original !== null) {
                throw new \RuntimeException(
                    $original->getMessage() . '; cleanup: ' . $cleanupFailure->getMessage(),
                    $this->status($original, 1),
                    $cleanupFailure,
                );
            }
            throw $cleanupFailure;
        }
    }

    private function installCleanupGuards(): void
    {
        register_shutdown_function(function(): void {
            try {
                $this->cleanup();
            } catch (\Throwable $exception) {
                fwrite(STDERR, 'Disposable shutdown cleanup failed: ' . $exception->getMessage() . PHP_EOL);
            }
        });
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGINT => 130, SIGTERM => 143, SIGHUP => 129] as $signal => $status) {
                pcntl_signal($signal, function() use ($status): never {
                    try {
                        $this->cleanup();
                    } catch (\Throwable $exception) {
                        fwrite(STDERR, 'Disposable signal cleanup failed: ' . $exception->getMessage() . PHP_EOL);
                        exit(1);
                    }
                    exit($status);
                });
            }
        }
    }

    private function createDatabase(): void
    {
        $this->injectFailure('database');
        if (!preg_match('/^' . self::DATABASE_PREFIX . '[a-f0-9]{16}$/', $this->databaseName)
            || $this->databaseName === 'db' || $this->databaseExists() || $this->grantExists()) {
            throw new \RuntimeException('Disposable database boundary is invalid or not fresh.');
        }
        $admin = self::adminConnection();
        $admin->exec('CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->databaseCreated = true;
        $admin->exec("GRANT ALL PRIVILEGES ON `{$this->databaseName}`.* TO 'db'@'%'");
        $this->grantCreated = true;
    }

    private function createProject(): void
    {
        $this->injectFailure('project');
        if (file_exists($this->projectRoot)) {
            throw new \RuntimeException('Disposable project root already exists.');
        }
        foreach (['config', 'storage/runtime/sessions', 'templates', 'web/cpresources'] as $relative) {
            $path = $this->projectRoot . '/' . $relative;
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException("Unable to create {$path}");
            }
        }
        $this->projectCreated = true;
        if (!symlink($this->vendorRoot, $this->projectRoot . '/vendor')) {
            throw new \RuntimeException('Unable to link the explicit fixture vendor root.');
        }
        $this->writeOwnedFile('bootstrap.php', <<<'PHP'
<?php
define('CRAFT_BASE_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
require_once CRAFT_VENDOR_PATH . '/autoload.php';
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createUnsafeMutable(CRAFT_BASE_PATH)->safeLoad();
}
PHP);
        $this->writeOwnedFile('craft', <<<'PHP'
#!/usr/bin/env php
<?php
require __DIR__ . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
exit($app->run());
PHP);
        chmod($this->projectRoot . '/craft', 0700);
        $this->writeOwnedFile('config/general.php', <<<'PHP'
<?php
use craft\config\GeneralConfig;
return GeneralConfig::create()->allowAdminChanges(true)->devMode(false)->omitScriptNameInUrls();
PHP);
        $this->writeOwnedFile('config/app.php', "<?php\nreturn [\n    'id' => 'report-manager-fixture-{$this->runId}',\n    'aliases' => [\n        '@root' => dirname(__DIR__),\n        '@webroot' => dirname(__DIR__) . '/web',\n        '@web' => '/',\n    ],\n    'components' => [\n        'session' => static function() {\n            \$config = craft\\helpers\\App::sessionConfig();\n            \$config['savePath'] = dirname(__DIR__) . '/storage/runtime/sessions';\n            return Craft::createObject(\$config);\n        },\n    ],\n];\n");
        $this->writeOwnedFile('config/db.php', <<<'PHP'
<?php
use craft\helpers\App;
return [
    'dsn' => App::env('CRAFT_DB_DSN'),
    'user' => App::env('CRAFT_DB_USER'),
    'password' => App::env('CRAFT_DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'schema' => App::env('CRAFT_DB_SCHEMA'),
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX'),
];
PHP);
        $this->writeOwnedFile('.env', implode("\n", [
            'CRAFT_APP_ID=report-manager-fixture-' . $this->runId,
            'CRAFT_ENVIRONMENT=test',
            'CRAFT_EDITION=pro',
            'CRAFT_SECURITY_KEY=' . $this->securityKey,
            'CRAFT_DB_DSN=' . $this->fixtureDsn(),
            'CRAFT_DB_USER=db',
            'CRAFT_DB_PASSWORD=db',
            'CRAFT_DB_SCHEMA=',
            'CRAFT_DB_TABLE_PREFIX=',
            'PRIMARY_SITE_URL=https://report-primary.example.test',
            '',
        ]));
    }

    private function installCraft(): void
    {
        $this->injectFailure('install');
        $this->runCommand([
            PHP_BINARY, $this->projectRoot . '/craft', 'install', '--interactive=0', '--silent-exit-on-exception=0',
            '--site-name=Report Manager Fixture', '--site-url=https://report-primary.example.test', '--language=en-US',
            '--username=fixture-admin', '--email=fixture-admin@example.test', '--password=Report-Fixture-Password-2026!',
        ], $this->projectRoot);
    }

    private function installPlugins(): void
    {
        $this->injectFailure('plugins');
        foreach (self::PLUGIN_HANDLES as $handle) {
            $this->runCommand([
                PHP_BINARY,
                $this->projectRoot . '/craft',
                'plugin/install',
                $handle,
                '--interactive=0',
                '--silent-exit-on-exception=0',
            ], $this->projectRoot);
        }
    }

    /** @return array<string, mixed> */
    private function seedFixture(): array
    {
        $this->injectFailure('seed');
        $result = $this->runCommand([PHP_BINARY, $this->packageRoot . '/tests/Fixtures/Project/seed.php'], $this->packageRoot);
        $identity = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($identity)) {
            throw new \RuntimeException('Fixture seeder returned an invalid identity.');
        }

        return $identity;
    }

    /** @param list<string> $arguments @return array<string, mixed> */
    private function runPhpunit(array $arguments): array
    {
        $this->injectFailure('phpunit');
        $reportPath = $this->projectRoot . '/phpunit-result.xml';
        $result = $this->runCommand([
            PHP_BINARY,
            $this->vendorRoot . '/bin/phpunit',
            '--configuration',
            $this->packageRoot . '/phpunit.xml.dist',
            '--colors=never',
            '--log-junit',
            $reportPath,
            ...$arguments,
        ], $this->packageRoot);
        if (!$this->isCompleteSuiteInvocation($arguments)) {
            $result['acceptedSuite'] = null;
            return $result;
        }

        $baseline = AcceptedSuiteAuthority::load($this->packageRoot . '/tests/accepted-suite.json');
        $actual = AcceptedSuiteAuthority::readJUnitSummary($reportPath);
        AcceptedSuiteAuthority::assertExecuted($baseline['executedMinimum'], $actual);
        $result['acceptedSuite'] = $actual;

        return $result;
    }

    /** @param list<string> $arguments */
    private function isCompleteSuiteInvocation(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '-')
                || preg_match('/^--(?:filter|testsuite|exclude-testsuite|group|exclude-group|list-tests|list-tests-xml|test-suffix)(?:=|$)/', $argument) === 1) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $command @return array{command: list<string>, exitCode: int, stdout: string, stderr: string} */
    private function runCommand(array $command, string $workingDirectory): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            $this->subprocessEnvironment(),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start disposable command.');
        }
        $this->activeProcess = $process;
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $this->activeProcess = null;
        $result = [
            'command' => $command,
            'exitCode' => $status,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
        $this->commands[] = $result;
        if ($status !== 0) {
            throw new \RuntimeException(
                'Disposable command failed (' . $status . '): ' . implode(' ', $command)
                . "\n{$result['stdout']}\n{$result['stderr']}",
                $status,
            );
        }

        return $result;
    }

    /** @return array<string, string> */
    private function subprocessEnvironment(): array
    {
        $environment = [
            'PATH' => is_string($_SERVER['PATH'] ?? null) ? $_SERVER['PATH'] : '/usr/local/bin:/usr/bin:/bin',
            'LANG' => is_string($_SERVER['LANG'] ?? null) && $_SERVER['LANG'] !== '' ? $_SERVER['LANG'] : 'C.UTF-8',
            'CRAFT_APP_ID' => 'report-manager-fixture-' . $this->runId,
            'CRAFT_ALLOW_SUPERUSER' => '1',
            'CRAFT_EDITION' => 'pro',
            'CRAFT_ENVIRONMENT' => 'test',
            'CRAFT_SECURITY_KEY' => $this->securityKey,
            'CRAFT_DB_DSN' => $this->fixtureDsn(),
            'CRAFT_DB_USER' => 'db',
            'CRAFT_DB_PASSWORD' => 'db',
            'CRAFT_DB_SCHEMA' => '',
            'CRAFT_DB_TABLE_PREFIX' => '',
            'PRIMARY_SITE_URL' => 'https://report-primary.example.test',
            TestProjectBoundary::PROJECT_ROOT_ENV => $this->projectRoot,
            TestProjectBoundary::DISPOSABLE_ENV => '1',
            self::SOURCE_VENDOR_ENV => $this->vendorRoot,
            'REPORT_MANAGER_TEST_RUN_ID' => $this->runId,
        ];
        foreach (['XDEBUG_MODE', 'PHP_IDE_CONFIG', 'REPORT_MANAGER_TEST_FORCE_FAILURE'] as $name) {
            $value = $_SERVER[$name] ?? null;
            if (is_scalar($value) && $value !== '') {
                $environment[$name] = (string)$value;
            }
        }

        return $environment;
    }

    private function resolveVendorRoot(): string
    {
        $configured = $_SERVER[self::SOURCE_VENDOR_ENV] ?? null;
        $candidates = is_string($configured) && $configured !== ''
            ? [$configured]
            : [$this->packageRoot . '/vendor', dirname($this->packageRoot, 2) . '/vendor'];
        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved . '/autoload.php') && is_file($resolved . '/bin/phpunit')) {
                return rtrim($resolved, DIRECTORY_SEPARATOR);
            }
        }
        throw new \RuntimeException(self::SOURCE_VENDOR_ENV . ' must resolve to a Composer vendor root with PHPUnit.', 2);
    }

    private function fixtureDsn(): string
    {
        $host = self::environment('REPORT_MANAGER_FIXTURE_DB_HOST', 'db');
        $port = self::environment('REPORT_MANAGER_FIXTURE_DB_PORT', '3306');

        return "mysql:host={$host};port={$port};dbname={$this->databaseName}";
    }

    private function databaseExists(): bool
    {
        $statement = self::adminConnection()->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name');
        $statement->execute(['name' => $this->databaseName]);
        return (int)$statement->fetchColumn() === 1;
    }

    private function grantExists(): bool
    {
        $statement = self::adminConnection()->prepare("SELECT COUNT(*) FROM mysql.db WHERE Host = '%' AND Db = :name AND User = 'db'");
        $statement->execute(['name' => $this->databaseName]);
        return (int)$statement->fetchColumn() === 1;
    }

    /** @return array{projectRemoved: bool, databaseRemoved: bool, grantRemoved: bool} */
    private function residueState(): array
    {
        return [
            'projectRemoved' => !file_exists($this->projectRoot),
            'databaseRemoved' => !$this->databaseExists(),
            'grantRemoved' => !$this->grantExists(),
        ];
    }

    private function writeOwnedFile(string $relativePath, string $contents): void
    {
        $path = $this->projectRoot . '/' . $relativePath;
        if (!str_starts_with($path, $this->projectRoot . '/')) {
            throw new \LogicException('Refusing to write outside the disposable project.');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write {$path}");
        }
    }

    private function removeOwnedProjectRoot(): void
    {
        $expected = '#^' . preg_quote(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR), '#')
            . '/report-manager-fixture-[a-f0-9]{16}$#';
        if (preg_match($expected, $this->projectRoot) !== 1) {
            throw new \LogicException('Refusing cleanup outside the disposable project boundary.');
        }
        if (!file_exists($this->projectRoot)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                if (!unlink($item->getPathname())) {
                    throw new \RuntimeException('Unable to remove ' . $item->getPathname());
                }
            } elseif (!rmdir($item->getPathname())) {
                throw new \RuntimeException('Unable to remove ' . $item->getPathname());
            }
        }
        if (!rmdir($this->projectRoot)) {
            throw new \RuntimeException('Unable to remove disposable project root.');
        }
    }

    private function injectFailure(string $stage): void
    {
        if (($_SERVER[self::FAILURE_STAGE_ENV] ?? null) === $stage) {
            throw new \RuntimeException("Synthetic disposable fixture {$stage} failure.", 75);
        }
    }

    private function status(\Throwable $exception, int $fallback): int
    {
        $status = $exception->getCode();
        return is_int($status) && $status > 0 && $status < 256 ? $status : $fallback;
    }

    private static function environment(string $name, string $default): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        return is_scalar($value) && (string)$value !== '' ? (string)$value : $default;
    }
}

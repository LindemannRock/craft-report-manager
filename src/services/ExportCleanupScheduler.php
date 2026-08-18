<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use DateTime;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\queue\PortableQueueScheduler;
use lindemannrock\reportmanager\jobs\CleanupExportsJob;
use lindemannrock\reportmanager\models\Settings;
use lindemannrock\reportmanager\ReportManager;
use yii\db\Expression;

/**
 * Owns generated-export cleanup eligibility and recurring queue lifecycle.
 *
 * @since 5.6.0
 */
final class ExportCleanupScheduler extends Component
{
    public const PLUGIN_TOKEN = 'reportmanager';
    public const RECURRING_OWNER = 'report-manager:export-cleanup:daily';
    public const LIFECYCLE_MUTEX = 'report-manager:export-cleanup:schedule';
    public const PORTABLE_MUTEX = 'report-manager:export-cleanup:portable';

    public int $mutexTimeout = 5;

    /** Synchronize the cleanup family during plugin bootstrap. */
    public function synchronize(?Settings $settings = null, ?DateTime $nextRun = null): void
    {
        $settings ??= ReportManager::$plugin->getSettings();
        $nextRun ??= $this->getNextRun($settings);

        $this->withQueueMutationLocks(function() use ($settings, $nextRun): void {
            if (!$this->isEnabled($settings) || $nextRun === null) {
                $this->cancelLocked();
                return;
            }

            $this->queueAtLocked($settings, $nextRun, true);
        });
    }

    /** Replace the cleanup family only when its effective policy changed. */
    public function replaceIfChanged(Settings $settings, bool $previouslyEnabled): bool
    {
        $enabled = $this->isEnabled($settings);
        if ($previouslyEnabled === $enabled) {
            return false;
        }

        $nextRun = $this->getNextRun($settings);
        $this->withQueueMutationLocks(function() use ($enabled, $settings, $nextRun): void {
            $this->cancelLocked();

            if ($enabled && $nextRun !== null) {
                $this->pushAtLocked($settings, $nextRun);
            }
        });

        return true;
    }

    /** Cancel every recurring cleanup consumer and deferred handoff. */
    public function cancel(): int
    {
        return $this->withQueueMutationLocks(fn(): int => $this->cancelLocked());
    }

    /**
     * Run one eligible cleanup occurrence and queue its successor on success.
     *
     * @param callable(): void $occurrence Performs cleanup and progress/result handling
     * @param callable(): void $disabled Handles the disabled occurrence result
     * @return bool Whether cleanup ran
     */
    public function runOccurrence(bool $reschedule, callable $occurrence, callable $disabled): bool
    {
        return $this->withLifecycleLock(function() use ($reschedule, $occurrence, $disabled): bool {
            $settings = ReportManager::$plugin->getSettings();
            if (!$this->isEnabled($settings)) {
                $disabled();
                return false;
            }

            $occurrence();

            if ($reschedule) {
                // Calculate the canonical target while the lifecycle lock is held,
                // before waiting for the portable queue-mutation lock.
                $nextRun = $this->getNextRun($settings);
                if ($nextRun !== null) {
                    $this->withPortableLock(fn() => $this->pushAtLocked($settings, $nextRun));
                }
            }

            return true;
        });
    }

    /** Queue a compatibility successor through the same locked family. */
    public function scheduleSuccessor(Settings $settings, DateTime $nextRun): void
    {
        $this->withQueueMutationLocks(fn() => $this->pushAtLocked($settings, $nextRun));
    }

    /** Resolve whether automatic cleanup is effectively enabled. */
    public function isEnabled(Settings $settings): bool
    {
        return $settings->autoCleanupExports && $settings->exportRetention > 0;
    }

    /** Resolve the next canonical daily wall-clock occurrence. */
    public function getNextRun(?Settings $settings = null, ?DateTime $from = null): ?DateTime
    {
        $settings ??= ReportManager::$plugin->getSettings();
        if (!$this->isEnabled($settings)) {
            return null;
        }

        return ScheduleHelper::calculateNext('daily', $from);
    }

    /** Format a canonical occurrence for Craft's queue UI. */
    public function getNextRunTime(Settings $settings, DateTime $nextRun): string
    {
        return DateFormatHelper::formatCompactDatetimeFromSettings(
            $nextRun,
            $settings,
            null,
            false,
            pluginHandle: 'report-manager',
        );
    }

    private function queueAtLocked(Settings $settings, DateTime $nextRun, bool $preserveHealthyLegacy): void
    {
        $legacyRows = $this->legacyRows();
        $healthyLegacyRows = $this->healthyRows($legacyRows);
        if ($preserveHealthyLegacy && $healthyLegacyRows !== []) {
            $keptId = (string)$healthyLegacyRows[0]['id'];
            $this->deleteRows($this->rowsExcept($legacyRows, $keptId));
            $this->deleteRows($this->ownedRows());
            return;
        }

        $ownedRows = $this->ownedRows();
        $healthyOwnedRows = $this->healthyRows($ownedRows);
        if ($healthyOwnedRows !== []) {
            $keptId = (string)$healthyOwnedRows[0]['id'];
            $this->deleteRows($this->rowsExcept($ownedRows, $keptId));
            $this->deleteRows($legacyRows);
            return;
        }

        $this->deleteRows($ownedRows);
        $this->deleteRows($legacyRows);
        $this->pushAtLocked($settings, $nextRun);
    }

    private function pushAtLocked(Settings $settings, DateTime $nextRun): void
    {
        $jobId = PortableQueueScheduler::pushAt(
            job: new CleanupExportsJob([
                'reschedule' => true,
                'recurringOwner' => self::RECURRING_OWNER,
                'nextRunTime' => $this->getNextRunTime($settings, $nextRun),
            ]),
            targetTimestamp: $nextRun->getTimestamp(),
            identityTokens: [self::PLUGIN_TOKEN, 'CleanupExportsJob', self::RECURRING_OWNER],
            mutexName: self::PORTABLE_MUTEX,
            mutexTimeout: $this->mutexTimeout,
            priority: 1024,
            ttr: 1800,
        );

        if ($jobId === null) {
            throw new \RuntimeException('Export-cleanup queue push did not return a job ID.');
        }
    }

    private function cancelLocked(): int
    {
        return $this->deleteRows($this->ownedRows()) + $this->deleteRows($this->legacyRows());
    }

    /**
     * @param list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, fail: int|string|bool|null}> $rows
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, fail: int|string|bool|null}>
     */
    private function healthyRows(array $rows): array
    {
        $healthy = array_values(array_filter(
            $rows,
            static fn(array $row): bool => !(bool)$row['fail'],
        ));

        usort($healthy, static function(array $left, array $right): int {
            $leftTarget = (int)$left['timePushed'] + (int)$left['delay'];
            $rightTarget = (int)$right['timePushed'] + (int)$right['delay'];

            return [$leftTarget, (int)$left['priority'], (int)$left['id']]
                <=> [$rightTarget, (int)$right['priority'], (int)$right['id']];
        });

        return $healthy;
    }

    /**
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, fail: int|string|bool|null}>
     */
    private function ownedRows(): array
    {
        $rows = $this->candidateQuery()
            ->andWhere(['like', 'job', self::RECURRING_OWNER])
            ->orderBy(new Expression('[[timePushed]] + [[delay]] ASC'))
            ->addOrderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $this->filterExactRows($rows, true);
    }

    /**
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, fail: int|string|bool|null}>
     */
    private function legacyRows(): array
    {
        $rows = $this->candidateQuery()
            ->andWhere(['not like', 'job', self::RECURRING_OWNER])
            ->orderBy(new Expression('[[timePushed]] + [[delay]] ASC'))
            ->addOrderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $this->filterExactRows($rows, false);
    }

    private function candidateQuery(): Query
    {
        return (new Query())
            ->from('{{%queue}}')
            ->select(['id', 'job', 'timePushed', 'delay', 'priority', 'fail'])
            ->where(['like', 'job', self::PLUGIN_TOKEN])
            ->andWhere(['like', 'job', 'CleanupExportsJob']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, fail: int|string|bool|null}>
     */
    private function filterExactRows(array $rows, bool $owned): array
    {
        $matches = [];
        foreach ($rows as $row) {
            $payload = (string)($row['job'] ?? '');
            if (!$this->hasExactIdentity($payload)) {
                continue;
            }

            $hasOwner = $this->hasRecurringOwner($payload);
            $isRecurring = $this->isRecurringPayload($payload);
            if (($owned && (!$hasOwner || !$isRecurring)) || (!$owned && ($hasOwner || !$isRecurring))) {
                continue;
            }

            /** @var array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, fail: int|string|bool|null} $row */
            $matches[] = $row;
        }

        return $matches;
    }

    private function hasExactIdentity(string $payload): bool
    {
        return $payload !== ''
            && preg_match('/(?<![A-Za-z0-9_-])reportmanager(?![A-Za-z0-9_-])/', $payload) === 1
            && preg_match('/(?<![A-Za-z0-9_])CleanupExportsJob(?![A-Za-z0-9_])/', $payload) === 1;
    }

    private function isRecurringPayload(string $payload): bool
    {
        return str_contains($payload, 's:10:"reschedule";b:1;')
            || preg_match('/"reschedule"\s*:\s*true/', $payload) === 1;
    }

    private function hasRecurringOwner(string $payload): bool
    {
        $owner = preg_quote(self::RECURRING_OWNER, '/');

        return preg_match('/(?<![A-Za-z0-9:_-])' . $owner . '(?![A-Za-z0-9:_-])/', $payload) === 1;
    }

    /**
     * @param list<array{id: int|string}> $rows
     * @return list<array{id: int|string}>
     */
    private function rowsExcept(array $rows, string $keptId): array
    {
        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => (string)$row['id'] !== $keptId,
        ));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function deleteRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $ids = array_map(static fn(array $row): string => (string)$row['id'], $rows);
        $deleted = Craft::$app->getDb()->createCommand()
            ->delete('{{%queue}}', ['id' => $ids])
            ->execute();

        if ($deleted !== count($ids)) {
            throw new \RuntimeException('Export-cleanup queue cancellation was incomplete.');
        }

        return $deleted;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLifecycleLock(callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::LIFECYCLE_MUTEX, $this->mutexTimeout)) {
            throw new \RuntimeException('Unable to acquire the export-cleanup lifecycle lock.');
        }

        try {
            return $callback();
        } finally {
            $mutex->release(self::LIFECYCLE_MUTEX);
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withPortableLock(callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::PORTABLE_MUTEX, $this->mutexTimeout)) {
            throw new \RuntimeException('Unable to acquire the export-cleanup portable lock.');
        }

        try {
            return $callback();
        } finally {
            $mutex->release(self::PORTABLE_MUTEX);
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withQueueMutationLocks(callable $callback): mixed
    {
        return $this->withLifecycleLock(fn() => $this->withPortableLock($callback));
    }
}

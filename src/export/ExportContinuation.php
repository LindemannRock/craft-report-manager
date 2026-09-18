<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\export;

use Craft;
use craft\helpers\Queue as QueueHelper;
use DateTime;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\reportmanager\datasources\ResumableDataSourceInterface;
use lindemannrock\reportmanager\jobs\GenerateExportJob;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\ReportManager;
use lindemannrock\reportmanager\storage\ExportWorkStorage;

/**
 * Durable finite-job orchestration shared by resumable standard data sources.
 *
 * @internal
 * @since 5.6.1
 */
class ExportContinuation
{
    use LoggingTrait;

    public const STATE_KEY = 'standardContinuation';

    public function __construct()
    {
        $this->setLoggingHandle('report-manager');
    }

    public static function supports(ExportRecord $export): bool
    {
        if ($export->isProviderExport()) {
            return false;
        }
        $source = ReportManager::getInstance()->dataSources->getDataSource($export->dataSource);

        return $source instanceof ResumableDataSourceInterface
            && $source::supportsExportContinuation();
    }

    public static function failureMessage(): string
    {
        return Craft::t('report-manager', 'Could not generate export.');
    }

    public static function locked(int $id, callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        $name = 'report-manager-export-' . $id;
        if (!$mutex->acquire($name, 5)) {
            throw new \RuntimeException('Export is busy; retry the operation.');
        }
        try {
            return $callback();
        } finally {
            $mutex->release($name);
        }
    }

    public function execute(int $id, int $sequence, $queue, callable $planFactory, callable $publish, ?callable $progressCallback = null): void
    {
        self::locked($id, function() use ($id, $sequence, $queue, $planFactory, $publish, $progressCallback): void {
            $export = ExportRecord::findOne($id);
            if ($export === null || (!$export->isPending() && !$export->isProcessing())) {
                return;
            }
            $source = ReportManager::getInstance()->dataSources->getDataSource($export->dataSource);
            if (!$source instanceof ResumableDataSourceInterface || !$source::supportsExportContinuation()) {
                throw new \RuntimeException('The export source no longer supports continuation.');
            }
            $state = $export->getMetadataArray()[self::STATE_KEY] ?? null;
            $work = new ExportWorkStorage($export);
            if ($state === null) {
                if ($sequence !== 0 || !$export->isPending()) {
                    return;
                }
                $state = [
                    'version' => 1, 'next' => 0, 'admitted' => 0,
                    'phase' => 'init', 'entity' => 0, 'part' => 0, 'offset' => 0,
                    'total' => 0, 'processed' => 0, 'written' => 0,
                ];
                $export->status = ExportRecord::STATUS_PROCESSING;
                $export->startedAt = new DateTime();
                $export->progress = 1;
                $this->save($export, $state);
            }
            $reportProgress = $progressCallback === null ? null : static function(int $progress) use ($progressCallback, $export): void {
                $progressCallback($progress, $export->getStatusLabel());
            };
            if ($reportProgress !== null) {
                $reportProgress((int)$export->progress);
            }
            if ($state['version'] !== 1) {
                throw new \RuntimeException('Unsupported export checkpoint version.');
            }
            if ($sequence !== $state['next']) {
                // A retry after checkpoint but before admission repairs the gap.
                // Replayed work with an admitted successor is otherwise a no-op.
                if ($sequence < $state['next'] && $state['admitted'] < $state['next']) {
                    $this->admit($export, $state, $queue);
                }
                return;
            }
            if ($state['phase'] === 'init') {
                $work->write('plan', $planFactory($export, $source));
                $state['phase'] = 'selection';
                $this->save($export, $state);
            }
            $plan = $state['phase'] === 'cleanup' ? [] : $work->read('plan');
            if ($plan !== [] && $plan['sourceClass'] !== $source::class) {
                throw new \RuntimeException('The export source changed during generation.');
            }
            $started = $this->now();
            $phase = $state['phase'];
            switch ($state['phase']) {
                case 'selection':
                    $this->capture($source, $plan, $state, $work, $started);
                    break;
                case 'rows':
                    $this->rows($source, $plan, $state, $work, $started, $reportProgress);
                    $export->progress = max((int)$export->progress, $this->rowProgress($state));
                    break;
                case 'assembly':
                    $this->assemble($export, $plan, $state, $work, $publish, $started, $reportProgress);
                    $export->progress = 99;
                    break;
                case 'cleanup':
                    $work->cleanup();
                    $this->boundary('cleanup');
                    $export->status = ExportRecord::STATUS_COMPLETED;
                    $export->progress = 100;
                    $export->completedAt = new DateTime();
                    $state['phase'] = 'done';
                    break;
                default:
                    throw new \RuntimeException('Unsupported export checkpoint phase.');
            }
            $state['next']++;
            $this->save($export, $state);
            $this->boundary('checkpoint');
            if (!$export->isCompleted()) {
                $this->admit($export, $state, $queue);
            }
            $this->logInfo('Export continuation step committed', [
                'exportId' => $id,
                'sequence' => $sequence,
                'phase' => $phase,
                'seconds' => round($this->now() - $started, 3),
                'peakMemoryBytes' => memory_get_peak_usage(true),
                'processed' => $state['processed'],
                'written' => $state['written'],
            ]);
        });
    }

    /**
     * Bounded retries are owned by Yii; exhaustion is also visible on the export.
     * Yii supplies no exception when rejecting a re-reserved aborted job.
     */
    public static function fail(int $id, ?\Throwable $error, int $sequence): void
    {
        self::locked($id, static function() use ($id, $error, $sequence): void {
            $export = ExportRecord::findOne($id);
            if ($export !== null && ($export->isPending() || $export->isProcessing())) {
                $state = $export->getMetadataArray()[self::STATE_KEY] ?? null;
                if ($state !== null && $state['next'] > $sequence && $state['admitted'] >= $state['next']) {
                    return;
                }
                $export->status = ExportRecord::STATUS_FAILED;
                $export->completedAt = new DateTime();
                $export->errorMessage = $error instanceof \lindemannrock\reportmanager\exceptions\ExportStorageUnavailableException
                    ? $error->getMessage()
                    : self::failureMessage();
                if (!$export->save(false, ['status', 'completedAt', 'errorMessage'])) {
                    throw new \RuntimeException('Unable to persist export failure.', previous: $error);
                }
            }
        });
    }

    private function capture(ResumableDataSourceInterface $source, array $plan, array &$state, ExportWorkStorage $work, float $started): void
    {
        $entity = $plan['entities'][$state['entity']];
        $part = 0;
        $count = 0;
        $identities = [];
        foreach ($source->getExportSelection($entity['id'], $plan['options']) as $identity) {
            $this->requireTime($started, 120);
            $identities[] = $identity;
            $count++;
            if (count($identities) === 100) {
                $work->write('selection-' . $state['entity'] . '-' . $part++, $identities);
                $identities = [];
            }
        }
        if ($identities !== []) {
            $work->write('selection-' . $state['entity'] . '-' . $part++, $identities);
        }
        $work->write('selection-' . $state['entity'], ['parts' => $part, 'count' => $count]);
        $this->boundary('selection');
        $state['total'] += $count;
        $state['entity']++;
        if ($state['entity'] === count($plan['entities'])) {
            $state['phase'] = 'rows';
            $state['entity'] = 0;
            $state['firstOutput'] = $state['next'] + 1;
        }
    }

    private function rows(ResumableDataSourceInterface $source, array $plan, array &$state, ExportWorkStorage $work, float $started, ?callable $progressCallback): void
    {
        $rows = [];
        $consumed = 0;
        do {
            $summary = $work->read('selection-' . $state['entity']);
            if ($state['part'] >= $summary['parts']) {
                $state['entity']++;
                $state['part'] = 0;
                $state['offset'] = 0;
                if ($state['entity'] === count($plan['entities'])) {
                    $state['phase'] = 'assembly';
                    $state['lastOutput'] = $state['next'];
                    break;
                }
                continue;
            }
            $entity = $plan['entities'][$state['entity']];
            $identities = $work->read('selection-' . $state['entity'] . '-' . $state['part']);
            while ($state['offset'] < count($identities)) {
                $data = $source->exportSelectedRecord($entity['id'], $identities[$state['offset']], $entity['handles'], $plan['options']);
                if (array_values($data['headers']) !== $entity['headers'] || count($data['rows']) > 1) {
                    throw new \RuntimeException('Export selection serialization violated its column or identity contract.');
                }
                foreach ($data['rows'] as $row) {
                    $aligned = array_fill(0, count($plan['headers']), '');
                    if ($plan['combined']) {
                        $aligned[0] = $entity['name'];
                    }
                    foreach ($entity['positions'] as $column => $position) {
                        $aligned[$position] = $row[$column] ?? '';
                    }
                    $rows[] = $aligned;
                    $state['written']++;
                }
                $state['offset']++;
                $state['processed']++;
                $consumed++;
                if ($progressCallback !== null) {
                    $progressCallback($this->rowProgress($state));
                }
                if ($consumed >= $this->rowLimit() || $this->now() - $started >= $this->rowSeconds()) {
                    break 2;
                }
            }
            $state['part']++;
            $state['offset'] = 0;
        } while (true);
        $work->write('output-' . $state['next'], $rows);
        $this->boundary('chunk');
    }

    private function assemble(ExportRecord $export, array $plan, array &$state, ExportWorkStorage $work, callable $publish, float $started, ?callable $progressCallback): void
    {
        $writer = null;
        $path = null;
        try {
            $writer = new StreamedExportWriter($plan['format'], $plan['headers'], array_merge($plan['writer'], [
                'tempDirectory' => $work->prepareStaging(),
            ]));
            for ($part = $state['firstOutput']; $part <= $state['lastOutput']; $part++) {
                $this->requireTime($started, 300);
                $writer->writeRows($work->read('output-' . $part));
                if ($progressCallback !== null) {
                    $progressCallback(95 + (int)(3 * ($part - $state['firstOutput'] + 1) / ($state['lastOutput'] - $state['firstOutput'] + 1)));
                }
            }
            $path = $writer->finish();
            $this->requireTime($started, 300);
            $result = $publish($export, $path);
            $this->boundary('publication');
            $export->fileSize = $result['size'];
            $export->recordCount = $state['written'];
            $state['phase'] = 'cleanup';
        } finally {
            $writer?->abort();
            if ($path !== null) {
                @unlink($path);
            }
            $work->clearStaging();
        }
    }

    private function rowProgress(array $state): int
    {
        return min(95, 5 + (int)(90 * $state['processed'] / max(1, $state['total'])));
    }

    private function admit(ExportRecord $export, array &$state, $queue): void
    {
        $jobId = QueueHelper::push(new GenerateExportJob([
            'exportId' => (int)$export->id,
            'combined' => $export->isCombinedExport(),
            'sequence' => $state['next'],
        ]), queue: $queue);
        if ($jobId === null || $jobId === '' || $jobId === '0') {
            throw new \RuntimeException('The queue rejected the export continuation job.');
        }
        $this->boundary('admission');
        $state['admitted'] = $state['next'];
        $this->save($export, $state);
        $this->logInfo('Export continuation admitted', ['exportId' => (int)$export->id, 'sequence' => $state['next'], 'jobId' => $jobId]);
    }

    private function save(ExportRecord $export, array $state): void
    {
        $metadata = $export->getMetadataArray();
        $metadata[self::STATE_KEY] = $state;
        $export->setMetadataArray($metadata);
        if (!$export->save(false)) {
            throw new \RuntimeException('Unable to persist export checkpoint.');
        }
    }

    private function requireTime(float $started, int $seconds): void
    {
        if ($this->now() - $started >= $seconds) {
            throw new \RuntimeException('Export selection or assembly exceeded its execution budget.');
        }
    }

    protected function rowLimit(): int
    {
        return max(1, min(100, ReportManager::getInstance()->getSettings()->maxExportBatchSize));
    }

    protected function rowSeconds(): float
    {
        return 90;
    }

    protected function now(): float
    {
        return microtime(true);
    }

    /** Process-boundary seam for disposable interruption and fault tests. */
    protected function boundary(string $name): void
    {
    }
}

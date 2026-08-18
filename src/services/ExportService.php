<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\services;

use Craft;
use craft\base\Component;
use craft\base\FsInterface;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use DateTime;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\base\helpers\SafeSegmentHelper;
use lindemannrock\base\helpers\StorageVolumeHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\reportmanager\datasources\DataSourceInterface;
use lindemannrock\reportmanager\export\QueuedExportContext;
use lindemannrock\reportmanager\export\QueuedExportResult;
use lindemannrock\reportmanager\export\StreamedExportWriter;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\records\ReportRecord;
use lindemannrock\reportmanager\ReportManager;
use yii\db\Expression;

/**
 * Export Service
 *
 * Handles export generation, file management, and cleanup.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class ExportService extends Component
{
    use LoggingTrait;

    /**
     * Hydrated Craft/Formie elements are substantially heavier than export
     * rows, so keep the runtime batch bounded even when a legacy setting still
     * contains the old 10,000-record default.
     */
    private const SAFE_MAX_BATCH_SIZE = 1000;

    /**
     * @var string Base path for export files (local storage)
     */
    private string $exportBasePath;

    /**
     * @var bool Whether using volume storage
     */
    private bool $_useVolume = false;

    /**
     * @var FsInterface|null The volume filesystem
     */
    private ?FsInterface $_volumeFs = null;

    /**
     * @var string Volume subpath for exports
     */
    private string $_volumeSubPath = 'report-manager/exports';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(ReportManager::$plugin->id);
        $this->_initializeStorage();
    }

    /**
     * Initialize storage based on settings
     */
    private function _initializeStorage(): void
    {
        $settings = ReportManager::getInstance()->getSettings();

        // Check if a volume is configured
        if (!empty($settings->exportVolumeUid)) {
            $volumeErrors = StorageVolumeHelper::validateVolume($settings->exportVolumeUid);
            if ($volumeErrors !== []) {
                $this->logWarning('Export volume failed validation. Falling back to local storage.', [
                    'exportVolumeUid' => $settings->exportVolumeUid,
                    'errors' => $volumeErrors,
                ]);
            } else {
                $volume = Craft::$app->getVolumes()->getVolumeByUid($settings->exportVolumeUid);
                if ($volume) {
                    try {
                        $this->_volumeFs = $volume->getFs();
                        $this->_useVolume = true;
                        $this->logInfo('Using volume for export storage', ['volume' => $volume->name]);
                        return;
                    } catch (\Exception $e) {
                        $this->logError('Failed to initialize volume filesystem, falling back to local', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Fall back to local path
        $this->_useVolume = false;
        $this->exportBasePath = $settings->getExportPath();

        // Ensure the path ends with /
        if (!str_ends_with($this->exportBasePath, '/')) {
            $this->exportBasePath .= '/';
        }
    }

    /**
     * Get the export base path (for display purposes)
     *
     * @return string
     */
    public function getExportBasePath(): string
    {
        if ($this->_useVolume) {
            return $this->_volumeSubPath;
        }
        return $this->exportBasePath;
    }

    /**
     * Check if using volume storage
     *
     * @return bool
     */
    public function isUsingVolume(): bool
    {
        return $this->_useVolume;
    }

    /**
     * Get all exports
     *
     * @param int|null $limit Maximum number to return
     * @return ExportRecord[]
     */
    public function getAllExports(?int $limit = null): array
    {
        $query = ExportRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var ExportRecord[] */
        return $query->all();
    }

    /**
     * Get filtered and paginated exports
     *
     * @param array $params Filter parameters
     * @return array{exports: ExportRecord[], totalCount: int, totalPages: int, offset: int}
     */
    public function getFilteredExports(array $params = []): array
    {
        $search = $params['search'] ?? '';
        $status = $params['status'] ?? null;
        $format = $params['format'] ?? null;
        $triggeredBy = $params['triggeredBy'] ?? null;
        $sort = $params['sort'] ?? 'dateCreated';
        $dir = $params['dir'] ?? 'desc';
        $page = max(1, $params['page'] ?? 1);
        $limit = $params['limit'] ?? 20;

        // Build query
        $query = ExportRecord::find();

        // Apply filters
        if (!empty($status)) {
            $query->andWhere(['status' => $status]);
        }

        if (!empty($format)) {
            $query->andWhere(['format' => $format]);
        }

        if (!empty($triggeredBy)) {
            $query->andWhere(['triggeredBy' => $triggeredBy]);
        }

        if (!empty($search)) {
            $query->andWhere([
                'or',
                ['like', 'entityName', $search],
                ['like', 'dataSource', $search],
                ['like', 'filename', $search],
            ]);
        }

        // Get total count before pagination
        $totalCount = (int) $query->count();

        // Apply sorting
        $validSortFields = [
            'entityName',
            'dataSource',
            'format',
            'status',
            'recordCount',
            'fileSize',
            'triggeredBy',
            'dateCreated',
        ];

        if (in_array($sort, $validSortFields, true)) {
            $sortDirection = strtolower($dir) === 'asc' ? SORT_ASC : SORT_DESC;
            $query->orderBy([$sort => $sortDirection]);
        } else {
            $query->orderBy(['dateCreated' => SORT_DESC]);
        }

        // Calculate pagination
        $offset = ($page - 1) * $limit;
        $totalPages = max(1, (int) ceil($totalCount / $limit));

        // Apply pagination
        $query->offset($offset)->limit($limit);

        /** @var ExportRecord[] $exports */
        $exports = $query->all();

        return [
            'exports' => $exports,
            'totalCount' => $totalCount,
            'totalPages' => $totalPages,
            'offset' => $offset,
        ];
    }

    /**
     * Get exports for a report
     *
     * @param int $reportId Report ID
     * @param int|null $limit Maximum number to return
     * @return ExportRecord[]
     */
    public function getExportsByReport(int $reportId, ?int $limit = null): array
    {
        $query = ExportRecord::find()
            ->where(['reportId' => $reportId])
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var ExportRecord[] */
        return $query->all();
    }

    /**
     * Get paginated exports for a report
     *
     * @param int $reportId Report ID
     * @param array $params Pagination parameters
     * @return array{exports: ExportRecord[], totalCount: int, totalPages: int, offset: int}
     */
    public function getExportsForReport(int $reportId, array $params = []): array
    {
        $page = max(1, $params['page'] ?? 1);
        $limit = $params['limit'] ?? 20;
        $sort = $params['sort'] ?? 'dateCreated';
        $dir = $params['dir'] ?? 'desc';

        // Defence-in-depth: allowlist the sort column. Controllers should
        // already gate this, but a service that exposes ORDER BY through a
        // string param validates again rather than trusting upstream.
        $validSortFields = [
            'filename',
            'format',
            'status',
            'recordCount',
            'fileSize',
            'dateCreated',
        ];

        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'dateCreated';
        }
        $sortDirection = strtolower((string) $dir) === 'asc' ? SORT_ASC : SORT_DESC;

        $query = ExportRecord::find()
            ->where(['reportId' => $reportId])
            ->orderBy([$sort => $sortDirection]);

        $totalCount = (int) $query->count();
        $offset = ($page - 1) * $limit;
        $totalPages = max(1, (int) ceil($totalCount / $limit));

        $query->offset($offset)->limit($limit);

        /** @var ExportRecord[] $exports */
        $exports = $query->all();

        return [
            'exports' => $exports,
            'totalCount' => $totalCount,
            'totalPages' => $totalPages,
            'offset' => $offset,
        ];
    }

    /**
     * Get export counts for reports.
     *
     * @param int[] $reportIds Report IDs
     * @return array<int, int> Map of report ID to export count
     */
    public function getExportCountsForReports(array $reportIds): array
    {
        $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds))));

        if (empty($reportIds)) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = array_fill_keys($reportIds, 0);

        $rows = ExportRecord::find()
            ->select([
                'reportId',
                'count' => new Expression('COUNT(*)'),
            ])
            ->where(['reportId' => $reportIds])
            ->groupBy(['reportId'])
            ->asArray()
            ->all();

        foreach ($rows as $row) {
            $reportId = (int) ($row['reportId'] ?? 0);

            if ($reportId > 0) {
                $counts[$reportId] = (int) ($row['count'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Get an export by ID
     *
     * @param int $id Export ID
     * @return ExportRecord|null
     */
    public function getExportById(int $id): ?ExportRecord
    {
        /** @var ExportRecord|null */
        return ExportRecord::findOne($id);
    }

    /**
     * Create a new export record (pending status)
     *
     * @param string $dataSource Data source handle
     * @param int $entityId Entity ID
     * @param string $format Export format
     * @param array $options Additional options
     * @return ExportRecord
     */
    public function createExport(
        string $dataSource,
        int $entityId,
        string $format,
        array $options = [],
    ): ExportRecord {
        $dataSourceInstance = ReportManager::getInstance()->dataSources->getDataSource($dataSource);
        $entity = $dataSourceInstance?->getEntity($entityId);

        $export = new ExportRecord();
        $export->dataSource = $dataSource;
        $export->entityId = $entityId;
        $export->entityName = $entity['name'] ?? null;
        $export->format = $format;
        $export->status = ExportRecord::STATUS_PENDING;
        $export->progress = 0;
        $export->triggeredBy = $options['triggeredBy'] ?? ExportRecord::TRIGGER_MANUAL;
        $export->triggeredByUserId = $options['triggeredByUserId'] ?? Craft::$app->getUser()->getId();
        $export->reportId = $options['reportId'] ?? null;

        // Date range
        $export->dateRangeUsed = $options['dateRange'] ?? null;
        $export->dateStartUsed = isset($options['dateStart'])
            ? ($options['dateStart'] instanceof DateTime
                ? $options['dateStart']
                : (DateTimeHelper::toDateTime($options['dateStart']) ?: null))
            : null;
        $export->dateEndUsed = isset($options['dateEnd'])
            ? ($options['dateEnd'] instanceof DateTime
                ? $options['dateEnd']
                : (DateTimeHelper::toDateTime($options['dateEnd']) ?: null))
            : null;
        $export->dateFieldUsed = $options['dateField'] ?? null;

        // Field handles
        if (!empty($options['fieldHandles'])) {
            $export->setFieldHandlesUsedArray($options['fieldHandles']);
        }

        // Site IDs filter
        if (!empty($options['siteIds']) && is_array($options['siteIds'])) {
            $export->setSiteIdsUsedArray($options['siteIds']);
        }

        // Generate filename
        $export->filename = $this->createStandardExportFilename(
            $export,
            (string)($entity['handle'] ?? 'export'),
        );

        // Set file path based on storage type
        if ($this->_useVolume) {
            // For volume storage, store relative path
            $export->filePath = $this->_volumeSubPath . '/' . $export->filename;
        } else {
            // For local storage, store full path
            $export->filePath = $this->exportBasePath . $export->filename;
        }

        $export->save();

        return $export;
    }

    /**
     * Create a queued provider export record.
     *
     * @param string $providerHandle Queued export provider handle
     * @param string $format Export format
     * @param array $payload Provider payload
     * @param array $options Additional options
     * @return ExportRecord
     */
    public function createQueuedExport(
        string $providerHandle,
        string $format,
        array $payload = [],
        array $options = [],
    ): ExportRecord {
        $provider = ReportManager::getInstance()->queuedExportProviders->getProvider($providerHandle);

        if ($provider === null) {
            throw new \InvalidArgumentException("Queued export provider '{$providerHandle}' not found or unavailable");
        }

        $format = $this->normalizeExportFormat($format);
        $supportedFormats = array_map(
            fn(string $supportedFormat) => $this->normalizeExportFormat($supportedFormat),
            $provider::supportedFormats()
        );

        if (!in_array($format, $supportedFormats, true)) {
            throw new \InvalidArgumentException("Queued export provider '{$providerHandle}' does not support {$format} exports");
        }

        $payload = $provider->normalizePayload($payload);
        $metadata = $options['metadata'] ?? [];
        $metadata = is_array($metadata) ? $metadata : [];
        $permissions = array_filter(
            $provider->getPermissions($payload),
            static fn($permission) => is_string($permission) && $permission !== ''
        );

        if (!empty($permissions)) {
            $metadata['permissions'] = $permissions;
        }

        $metadata['provider'] = [
            'handle' => $providerHandle,
            'name' => $provider::displayName(),
        ];

        $filename = $options['filename'] ?? $provider->getFilename($payload, $format);
        $filename = $this->ensureFilenameExtension((string) $filename, $format);

        $export = new ExportRecord();
        $export->dataSource = mb_substr($providerHandle, 0, 64);
        $export->entityId = 0;
        $export->entityName = $options['entityName'] ?? $provider->getExportName($payload);
        $export->providerHandle = $providerHandle;
        $export->setPayloadArray($payload);
        $export->setMetadataArray($metadata);
        $export->format = $format;
        $export->filename = $filename;
        $export->filePath = $this->getExportFilePath($filename);
        $export->status = ExportRecord::STATUS_PENDING;
        $export->progress = 0;
        $export->triggeredBy = $options['triggeredBy'] ?? ExportRecord::TRIGGER_API;
        $export->triggeredByUserId = $options['triggeredByUserId'] ?? Craft::$app->getUser()->getId();
        $export->reportId = $options['reportId'] ?? null;
        $export->save();

        return $export;
    }

    /**
     * Generate an export
     *
     * @param ExportRecord $export Export record
     * @param callable(int): void|null $progressCallback Progress callback receiving an integer percentage
     * @return bool
     * @since 5.6.0 Added the optional progress callback.
     */
    public function generateExport(ExportRecord $export, ?callable $progressCallback = null): bool
    {
        // Update status to processing
        $export->status = ExportRecord::STATUS_PROCESSING;
        $export->startedAt = new DateTime();
        $export->progress = max(1, (int)$export->progress);
        $export->save();

        try {
            // Get data source
            $dataSource = ReportManager::getInstance()->dataSources->getDataSource($export->dataSource);

            if ($dataSource === null) {
                throw new \Exception("Data source '{$export->dataSource}' not found");
            }

            // Build query options
            $options = [];

            if ($export->dateRangeUsed) {
                $options['dateRange'] = $export->dateRangeUsed;
            }

            if ($export->dateStartUsed) {
                $options['dateStart'] = $export->dateStartUsed;
            }

            if ($export->dateEndUsed) {
                $options['dateEnd'] = $export->dateEndUsed;
            }

            if ($export->dateFieldUsed) {
                $options['dateField'] = $export->dateFieldUsed;
            }

            // Site IDs filter
            $siteIds = $export->getSiteIdsUsedArray();
            if (!empty($siteIds)) {
                $options['siteIds'] = $siteIds;
            }

            // Stream export data in bounded batches.
            $fieldHandles = $export->getFieldHandlesUsedArray();
            $result = $this->generateStreamedEntityFile(
                $export,
                $dataSource,
                $export->entityId,
                $fieldHandles,
                $options,
                $progressCallback,
            );

            // Update export record
            $export->filePath = $result['path'];
            $export->fileSize = $result['size'];
            $export->recordCount = $result['recordCount'];
            $export->status = ExportRecord::STATUS_COMPLETED;
            $export->progress = 100;
            $export->completedAt = new DateTime();
            $export->save();

            $this->logInfo('Export generated successfully', [
                'id' => $export->id,
                'format' => $export->format,
                'recordCount' => $export->recordCount,
                'fileSize' => $export->fileSize,
            ]);

            return true;
        } catch (\Throwable $e) {
            $export->status = ExportRecord::STATUS_FAILED;
            $export->errorMessage = $e->getMessage();
            $export->completedAt = new DateTime();
            $export->save();

            $this->logError('Export generation failed', [
                'id' => $export->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Generate a queued provider export.
     *
     * @param ExportRecord $export Export record
     * @param callable(int): void|null $progressCallback Progress callback receiving an integer percentage
     * @return bool
     * @since 5.6.0 Added the optional progress callback.
     */
    public function generateQueuedExport(ExportRecord $export, ?callable $progressCallback = null): bool
    {
        $export->status = ExportRecord::STATUS_PROCESSING;
        $export->startedAt = new DateTime();
        $export->progress = max(1, (int) $export->progress);
        $export->save();

        try {
            if (empty($export->providerHandle)) {
                throw new \Exception('Export record is missing a queued export provider handle');
            }

            $provider = ReportManager::getInstance()->queuedExportProviders->getProvider($export->providerHandle);

            if ($provider === null) {
                throw new \Exception("Queued export provider '{$export->providerHandle}' not found or unavailable");
            }

            $format = $this->normalizeExportFormat($export->format);
            $supportedFormats = array_map(
                fn(string $supportedFormat) => $this->normalizeExportFormat($supportedFormat),
                $provider::supportedFormats()
            );

            if (!in_array($format, $supportedFormats, true)) {
                throw new \Exception("Queued export provider '{$export->providerHandle}' does not support {$format} exports");
            }

            $export->format = $format;
            $export->filename = $this->ensureFilenameExtension($export->filename, $format);
            $export->filePath = $this->getExportFilePath($export->filename);
            $export->save();

            $context = new QueuedExportContext(
                $export,
                fn(int $progress, ?string $message = null) => $this->updateQueuedExportProgress(
                    $export,
                    $progress,
                    $message,
                    $progressCallback,
                )
            );

            $result = $provider->generate($export->getPayloadArray(), $context);

            if (!$this->_useVolume) {
                FileHelper::createDirectory($this->exportBasePath);
            }

            $fileResult = match ($result->getType()) {
                QueuedExportResult::TYPE_TABLE => $this->generateProviderTableFile($export, $result),
                QueuedExportResult::TYPE_WORKBOOK => $this->generateProviderWorkbookFile($export, $result),
                QueuedExportResult::TYPE_FILES => $this->generateProviderZipFile($export, $result),
                default => throw new \Exception("Unsupported queued export result type: {$result->getType()}"),
            };

            $export->filePath = $fileResult['path'];
            $export->fileSize = $fileResult['size'];
            $export->recordCount = $result->getRecordCount();
            $export->setWarningsArray($result->getWarnings());
            $export->status = ExportRecord::STATUS_COMPLETED;
            $export->progress = 100;
            $export->completedAt = new DateTime();
            $export->save();

            $this->logInfo('Queued provider export generated successfully', [
                'id' => $export->id,
                'providerHandle' => $export->providerHandle,
                'format' => $export->format,
                'recordCount' => $export->recordCount,
                'fileSize' => $export->fileSize,
            ]);

            return true;
        } catch (\Throwable $e) {
            $export->status = ExportRecord::STATUS_FAILED;
            $export->errorMessage = $e->getMessage();
            $export->completedAt = new DateTime();
            $export->save();

            $this->logError('Queued provider export generation failed', [
                'id' => $export->id,
                'providerHandle' => $export->providerHandle,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Generate one standard data-source export without retaining every row.
     *
     * @param ExportRecord $export Export record
     * @param DataSourceInterface $dataSource Data source
     * @param int $entityId Entity ID
     * @param string[] $fieldHandles Selected field handles
     * @param array $options Query options
     * @param callable(int): void|null $progressCallback Progress callback receiving an integer percentage
     * @return array{path: string, size: int, recordCount: int}
     */
    private function generateStreamedEntityFile(
        ExportRecord $export,
        DataSourceInterface $dataSource,
        int $entityId,
        array $fieldHandles,
        array $options,
        ?callable $progressCallback = null,
    ): array {
        $writer = null;

        try {
            $recordCount = $this->forEachExportBatch(
                $dataSource,
                $entityId,
                $fieldHandles,
                $options,
                function(array $headers, array $rows) use (&$writer, $export): void {
                    if ($writer === null) {
                        $writer = $this->createStreamedWriter($export, $headers);
                    }

                    $writer->writeRows($rows);
                },
                fn(int $processed, int $total) => $this->updateStandardExportProgress(
                    $export,
                    $processed,
                    $total,
                    $progressCallback,
                ),
            );

            if (!$writer instanceof StreamedExportWriter) {
                throw new \RuntimeException('The export data source did not provide column headers.');
            }

            $tempPath = $writer->finish();

            try {
                $file = $this->_writeExportTempFile($export, $tempPath);
            } finally {
                @unlink($tempPath);
            }

            return [
                'path' => $file['path'],
                'size' => $file['size'],
                'recordCount' => $recordCount,
            ];
        } finally {
            $writer?->abort();
        }
    }

    /**
     * Generate a combined export while retaining only one source batch and its
     * aligned output rows at a time.
     *
     * @param ExportRecord $export Export record
     * @param DataSourceInterface $dataSource Data source
     * @param int[] $entityIds Entity IDs
     * @param string[] $fieldHandles Selected field handles
     * @param array $options Query options
     * @param array<string, string> $labels Data-source UI labels
     * @param callable(int): void|null $progressCallback Progress callback receiving an integer percentage
     * @return array{path: string, size: int, recordCount: int}
     */
    private function generateStreamedCombinedFile(
        ExportRecord $export,
        DataSourceInterface $dataSource,
        array $entityIds,
        array $fieldHandles,
        array $options,
        array $labels,
        ?callable $progressCallback = null,
    ): array {
        $allHeaders = [$labels['combinedPrimaryColumnLabel'] ?? Craft::t('report-manager', 'Item Name')];
        $entityNames = [];

        foreach ($entityIds as $entityId) {
            $entity = $dataSource->getEntity($entityId);
            $entityNames[$entityId] = $entity['name'] ?? ($labels['entitySingular'] ?? Craft::t('report-manager', 'Item')) . " {$entityId}";
            $fields = $dataSource->getEntityFields($entityId);

            if ($fieldHandles !== []) {
                $fields = array_filter(
                    $fields,
                    static fn(array $field): bool => in_array($field['handle'], $fieldHandles, true),
                );
            }

            foreach ($fields as $field) {
                $label = (string)$field['label'];
                if (!in_array($label, $allHeaders, true)) {
                    $allHeaders[] = $label;
                }
            }
        }

        $writer = $this->createStreamedWriter($export, $allHeaders);
        $recordCount = 0;

        try {
            $entityCount = count($entityIds);
            foreach ($entityIds as $entityIndex => $entityId) {
                $entityName = $entityNames[$entityId];
                $recordCount += $this->forEachExportBatch(
                    $dataSource,
                    $entityId,
                    $fieldHandles,
                    $options,
                    function(array $headers, array $rows) use ($writer, $allHeaders, $entityName): void {
                        $headerPositions = [];
                        foreach ($headers as $index => $header) {
                            $position = array_search($header, $allHeaders, true);
                            if ($position !== false) {
                                $headerPositions[$index] = $position;
                            }
                        }

                        $combinedRows = [];
                        foreach ($rows as $row) {
                            $combinedRow = array_fill(0, count($allHeaders), '');
                            $combinedRow[0] = $entityName;

                            foreach ($headerPositions as $sourceIndex => $targetIndex) {
                                if (array_key_exists($sourceIndex, $row)) {
                                    $combinedRow[$targetIndex] = $row[$sourceIndex];
                                }
                            }

                            $combinedRows[] = $combinedRow;
                        }

                        $writer->writeRows($combinedRows);
                    },
                    fn(int $processed, int $total) => $this->updateStandardExportProgress(
                        $export,
                        $processed,
                        $total,
                        $progressCallback,
                        $entityIndex,
                        $entityCount,
                    ),
                );
            }

            $tempPath = $writer->finish();

            try {
                $file = $this->_writeExportTempFile($export, $tempPath);
            } finally {
                @unlink($tempPath);
            }

            return [
                'path' => $file['path'],
                'size' => $file['size'],
                'recordCount' => $recordCount,
            ];
        } finally {
            $writer->abort();
        }
    }

    /**
     * Read and release one bounded data-source batch at a time.
     *
     * The `limit`/`offset` options already belong to the data-source contract.
     * Third-party sources that return more rows than requested fail with a
     * controlled error instead of producing duplicate output.
     *
     * @param DataSourceInterface $dataSource Data source
     * @param int $entityId Entity ID
     * @param string[] $fieldHandles Selected field handles
     * @param array $options Query options
     * @param callable(string[], array<int, array<int, mixed>>): void $consumer
     * @param callable(int, int): void|null $progressCallback Progress callback receiving processed and total rows
     * @return int Number of rows written
     */
    private function forEachExportBatch(
        DataSourceInterface $dataSource,
        int $entityId,
        array $fieldHandles,
        array $options,
        callable $consumer,
        ?callable $progressCallback = null,
    ): int {
        $settings = ReportManager::getInstance()->getSettings();
        $batchSize = max(1, min($settings->maxExportBatchSize, self::SAFE_MAX_BATCH_SIZE));
        $total = max(0, $dataSource->getRecordCount($entityId, $options));
        $offset = 0;
        $recordCount = 0;
        $expectedHeaders = null;

        do {
            $batchOptions = array_merge($options, [
                'limit' => $batchSize,
                'offset' => $offset,
            ]);
            $data = $dataSource->exportToArray($entityId, $fieldHandles, $batchOptions);
            $headers = array_values(array_map('strval', is_array($data['headers'] ?? null) ? $data['headers'] : []));
            $rows = is_array($data['rows'] ?? null) ? array_values($data['rows']) : [];
            $batchCount = count($rows);

            if ($batchCount > $batchSize) {
                throw new \RuntimeException(sprintf(
                    'Data source "%s" returned %d rows for an export batch limited to %d.',
                    $dataSource::handle(),
                    $batchCount,
                    $batchSize,
                ));
            }

            if ($expectedHeaders === null) {
                $expectedHeaders = $headers;
            } elseif ($headers !== $expectedHeaders) {
                throw new \RuntimeException('Export columns changed between data-source batches.');
            }

            $consumer($headers, $rows);

            $recordCount += $batchCount;
            if ($progressCallback !== null) {
                $progressCallback($recordCount, $total);
            }

            unset($data, $rows);
            gc_collect_cycles();

            if ($total === 0 || $batchCount === 0) {
                break;
            }

            $offset += $batchSize;
        } while ($offset < $total);

        return $recordCount;
    }

    /**
     * Persist standard export progress and forward it to the queue job.
     *
     * Combined exports weight each selected entity equally so progress moves
     * throughout every source without adding duplicate count queries.
     *
     * @param callable(int): void|null $progressCallback
     */
    private function updateStandardExportProgress(
        ExportRecord $export,
        int $processed,
        int $total,
        ?callable $progressCallback,
        int $entityIndex = 0,
        int $entityCount = 1,
    ): void {
        $entityCount = max(1, $entityCount);
        $entityProgress = $total > 0 ? min(1, $processed / $total) : 1;
        $progress = (int)floor((($entityIndex + $entityProgress) / $entityCount) * 99);
        $progress = max(1, min(99, $progress));

        if ($progress <= (int)$export->progress) {
            return;
        }

        $export->progress = $progress;
        $export->save(false, ['progress']);
        if ($progressCallback !== null) {
            $progressCallback($progress);
        }
    }

    /**
     * Create a disk-backed writer using the export's configured format.
     *
     * @param ExportRecord $export Export record
     * @param string[] $headers Export column labels
     */
    private function createStreamedWriter(ExportRecord $export, array $headers): StreamedExportWriter
    {
        $settings = ReportManager::getInstance()->getSettings();

        return new StreamedExportWriter($export->format, $headers, [
            'delimiter' => $settings->csvDelimiter,
            'enclosure' => $settings->csvEnclosure,
            'includeBom' => $settings->csvIncludeBom,
            'sheetTitle' => $export->entityName ?? 'Export',
        ]);
    }

    /**
     * Copy a completed temporary file into local or volume storage.
     *
     * @param ExportRecord $export Export record
     * @param string $tempPath Completed temporary file
     * @return array{path: string, size: int}
     */
    private function _writeExportTempFile(ExportRecord $export, string $tempPath): array
    {
        $size = filesize($tempPath);

        if ($size === false) {
            throw new \RuntimeException('Unable to determine the generated export file size.');
        }

        if ($this->_useVolume && $this->_volumeFs !== null) {
            $volumePath = $this->_volumeSubPath . '/' . $export->filename;
            $stream = fopen($tempPath, 'rb');

            if ($stream === false) {
                throw new \RuntimeException('Unable to open the generated export file for storage.');
            }

            try {
                $this->_volumeFs->writeFileFromStream($volumePath, $stream);
            } finally {
                fclose($stream);
            }

            return [
                'path' => $volumePath,
                'size' => $size,
            ];
        }

        FileHelper::createDirectory($this->exportBasePath);
        $localPath = $this->exportBasePath . $export->filename;

        if (!copy($tempPath, $localPath)) {
            throw new \RuntimeException("Unable to store the generated export file at {$localPath}.");
        }

        return [
            'path' => $localPath,
            'size' => $size,
        ];
    }

    /**
     * Generate CSV file
     *
     * @param ExportRecord $export Export record
     * @param array $data Export data with headers and rows
     * @return array{path: string, size: int} File path and size
     */
    private function generateCsvFile(ExportRecord $export, array $data): array
    {
        $settings = ReportManager::getInstance()->getSettings();

        $content = ExportHelper::csvContent(
            $data['rows'],
            $data['headers'],
            [],
            $settings->csvDelimiter,
            $settings->csvEnclosure,
        );

        if ($settings->csvIncludeBom) {
            $content = "\xEF\xBB\xBF" . $content;
        }

        return $this->_writeExportFile($export, $content);
    }

    /**
     * Generate JSON file
     *
     * @param ExportRecord $export Export record
     * @param array $data Export data with headers and rows
     * @return array{path: string, size: int} File path and size
     */
    private function generateJsonFile(ExportRecord $export, array $data): array
    {
        // Convert rows to associative arrays
        $headers = $data['headers'];
        $jsonData = [];

        foreach ($data['rows'] as $row) {
            $jsonRow = [];
            foreach ($headers as $index => $header) {
                $jsonRow[$header] = $row[$index] ?? null;
            }
            $jsonData[] = $jsonRow;
        }

        $content = ExportHelper::jsonContent($jsonData);

        return $this->_writeExportFile($export, $content);
    }

    /**
     * Generate XLSX file
     *
     * @param ExportRecord $export Export record
     * @param array $data Export data with headers and rows
     * @return array{path: string, size: int} File path and size
     */
    private function generateXlsxFile(ExportRecord $export, array $data): array
    {
        $content = ExportHelper::excelContent(
            $data['rows'],
            $data['headers'],
            [],
            ['sheetTitle' => $export->entityName ?? 'Export'],
        );

        return $this->_writeExportFile($export, $content);
    }

    /**
     * Generate a provider table export.
     *
     * @param ExportRecord $export Export record
     * @param QueuedExportResult $result Provider result
     * @return array{path: string, size: int} File path and size
     */
    private function generateProviderTableFile(ExportRecord $export, QueuedExportResult $result): array
    {
        $data = $result->getTableData();

        return match ($export->format) {
            'csv' => $this->generateCsvFile($export, $data),
            'json' => $this->generateJsonFile($export, $data),
            'xlsx' => $this->generateXlsxFile($export, $data),
            default => throw new \Exception("Unsupported table export format: {$export->format}"),
        };
    }

    /**
     * Generate a provider workbook export.
     *
     * @param ExportRecord $export Export record
     * @param QueuedExportResult $result Provider result
     * @return array{path: string, size: int} File path and size
     */
    private function generateProviderWorkbookFile(ExportRecord $export, QueuedExportResult $result): array
    {
        if ($export->format !== 'xlsx') {
            throw new \Exception("Workbook export results require xlsx format, {$export->format} requested");
        }

        $sheets = $result->getSheets();
        if (empty($sheets)) {
            throw new \Exception('Workbook export result did not include any sheets');
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $usedTitles = [];

        foreach ($sheets as $index => $sheetData) {
            $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet($index);
            $title = $this->sanitizeSheetTitle($sheetData['name'], $usedTitles);
            $usedTitles[] = $title;
            $sheet->setTitle($title);
            $this->writeWorksheet(
                $sheet,
                $sheetData['headers'],
                $sheetData['rows']
            );
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempFile);

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $this->_writeExportFile($export, $content);
    }

    /**
     * Generate a provider ZIP export.
     *
     * @param ExportRecord $export Export record
     * @param QueuedExportResult $result Provider result
     * @return array{path: string, size: int} File path and size
     */
    private function generateProviderZipFile(ExportRecord $export, QueuedExportResult $result): array
    {
        if ($export->format !== 'zip') {
            throw new \Exception("File manifest export results require zip format, {$export->format} requested");
        }

        $files = $result->getFiles();
        if (empty($files)) {
            throw new \Exception('File manifest export result did not include any files');
        }

        $zipFiles = [];
        foreach ($files as $file) {
            $filename = (string)$file['filename'];

            if (array_key_exists('contents', $file)) {
                $zipFiles[] = [
                    'name' => $filename,
                    'content' => (string) $file['contents'],
                ];
                continue;
            }

            $path = $file['path'] ?? null;
            if (!is_string($path) || !is_file($path)) {
                throw new \Exception("Queued export file '{$filename}' is missing readable contents or path");
            }

            $content = file_get_contents($path);
            if ($content === false) {
                throw new \Exception("Queued export file '{$filename}' could not be read");
            }

            $zipFiles[] = [
                'name' => $filename,
                'content' => $content,
            ];
        }

        $content = ExportHelper::zipContent($zipFiles);

        return $this->_writeExportFile($export, $content);
    }

    /**
     * Write a worksheet from headers and rows.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Worksheet
     * @param string[] $headers Column headers
     * @param array<int, array<int, mixed>> $rows Row values
     */
    private function writeWorksheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers, array $rows): void
    {
        $headerCount = count($headers);

        if ($headerCount > 0) {
            foreach ($headers as $index => $header) {
                $sheet->setCellValue([$index + 1, 1], $header);
            }

            $headerRange = 'A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($headerCount) . '1';
            $sheet->getStyle($headerRange)->applyFromArray([
                'font' => [
                    'bold' => true,
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => [
                        'rgb' => 'E5E7EB',
                    ],
                ],
            ]);
        }

        $rowIndex = $headerCount > 0 ? 2 : 1;
        foreach ($rows as $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                if (ExportHelper::isDangerousValue($value)) {
                    $sheet->setCellValueExplicit(
                        [$columnIndex + 1, $rowIndex],
                        $value,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                    );
                } else {
                    $sheet->setCellValue([$columnIndex + 1, $rowIndex], $value);
                }
            }
            $rowIndex++;
        }

        if ($headerCount > 0) {
            foreach (range(1, $headerCount) as $columnIndex) {
                $sheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true);
            }
            $sheet->freezePane('A2');
        }
    }

    /**
     * Sanitize and de-duplicate an XLSX sheet title.
     *
     * @param string $title Sheet title
     * @param string[] $usedTitles Titles already used in the workbook
     * @return string
     */
    private function sanitizeSheetTitle(string $title, array $usedTitles): string
    {
        $title = preg_replace('/[\\\\\/\*\?\[\]\:]/', '_', $title) ?: 'Sheet';
        $title = trim($title) !== '' ? trim($title) : 'Sheet';
        $title = mb_substr($title, 0, 31);
        $candidate = $title;
        $suffix = 2;

        while (in_array($candidate, $usedTitles, true)) {
            $suffixText = ' ' . $suffix;
            $candidate = mb_substr($title, 0, 31 - mb_strlen($suffixText)) . $suffixText;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Write export file to storage (volume or local)
     *
     * @param ExportRecord $export Export record
     * @param string $content File content
     * @return array{path: string, size: int} File path and size
     */
    private function _writeExportFile(ExportRecord $export, string $content): array
    {
        $size = strlen($content);

        if ($this->_useVolume && $this->_volumeFs !== null) {
            // Write to volume
            $volumePath = $this->_volumeSubPath . '/' . $export->filename;
            $this->_volumeFs->write($volumePath, $content);

            return [
                'path' => $volumePath,
                'size' => $size,
            ];
        }

        // Write to local filesystem
        $localPath = $this->exportBasePath . $export->filename;
        FileHelper::writeToFile($localPath, $content);

        return [
            'path' => $localPath,
            'size' => $size,
        ];
    }

    /**
     * Create a combined export record (multiple entities in one file)
     *
     * @param string $dataSource Data source handle
     * @param int[] $entityIds Entity IDs
     * @param string $format Export format
     * @param array $options Additional options
     * @return ExportRecord
     */
    public function createCombinedExport(
        string $dataSource,
        array $entityIds,
        string $format,
        array $options = [],
    ): ExportRecord {
        $export = new ExportRecord();
        $export->dataSource = $dataSource;
        $export->setEntityIdsArray($entityIds);
        $export->entityName = Craft::t('report-manager', 'Combined ({count} sources)', ['count' => count($entityIds)]);
        $export->format = $format;
        $export->status = ExportRecord::STATUS_PENDING;
        $export->progress = 0;
        $export->triggeredBy = $options['triggeredBy'] ?? ExportRecord::TRIGGER_MANUAL;
        $export->triggeredByUserId = $options['triggeredByUserId'] ?? Craft::$app->getUser()->getId();
        $export->reportId = $options['reportId'] ?? null;

        // Date range
        $export->dateRangeUsed = $options['dateRange'] ?? null;
        $export->dateStartUsed = isset($options['dateStart'])
            ? ($options['dateStart'] instanceof DateTime
                ? $options['dateStart']
                : (DateTimeHelper::toDateTime($options['dateStart']) ?: null))
            : null;
        $export->dateEndUsed = isset($options['dateEnd'])
            ? ($options['dateEnd'] instanceof DateTime
                ? $options['dateEnd']
                : (DateTimeHelper::toDateTime($options['dateEnd']) ?: null))
            : null;
        $export->dateFieldUsed = $options['dateField'] ?? null;

        // Field handles
        if (!empty($options['fieldHandles'])) {
            $export->setFieldHandlesUsedArray($options['fieldHandles']);
        }

        // Site IDs filter
        if (!empty($options['siteIds']) && is_array($options['siteIds'])) {
            $export->setSiteIdsUsedArray($options['siteIds']);
        }

        // Generate filename
        $export->filename = $this->createStandardExportFilename($export, 'combined');

        // Set file path based on storage type
        if ($this->_useVolume) {
            // For volume storage, store relative path
            $export->filePath = $this->_volumeSubPath . '/' . $export->filename;
        } else {
            // For local storage, store full path
            $export->filePath = $this->exportBasePath . $export->filename;
        }

        $export->save();

        return $export;
    }

    /**
     * Generate a combined export (multiple entities in one file)
     *
     * @param ExportRecord $export Export record
     * @param callable(int): void|null $progressCallback Progress callback receiving an integer percentage
     * @return bool
     * @since 5.6.0 Added the optional progress callback.
     */
    public function generateCombinedExport(ExportRecord $export, ?callable $progressCallback = null): bool
    {
        $export->status = ExportRecord::STATUS_PROCESSING;
        $export->startedAt = new DateTime();
        $export->progress = max(1, (int)$export->progress);
        $export->save();

        try {
            $dataSource = ReportManager::getInstance()->dataSources->getDataSource($export->dataSource);

            if ($dataSource === null) {
                throw new \Exception("Data source '{$export->dataSource}' not found");
            }

            $labels = $dataSource::uiLabels();
            $entityIds = $export->getEntityIdsArray();

            if (empty($entityIds)) {
                throw new \Exception(Craft::t('report-manager', 'No items selected for combined export'));
            }

            // Build query options
            $options = [];

            if ($export->dateRangeUsed) {
                $options['dateRange'] = $export->dateRangeUsed;
            }

            if ($export->dateStartUsed) {
                $options['dateStart'] = $export->dateStartUsed;
            }

            if ($export->dateEndUsed) {
                $options['dateEnd'] = $export->dateEndUsed;
            }

            if ($export->dateFieldUsed) {
                $options['dateField'] = $export->dateFieldUsed;
            }

            // Site IDs filter
            $siteIds = $export->getSiteIdsUsedArray();
            if (!empty($siteIds)) {
                $options['siteIds'] = $siteIds;
            }

            $result = $this->generateStreamedCombinedFile(
                $export,
                $dataSource,
                $entityIds,
                $export->getFieldHandlesUsedArray(),
                $options,
                $labels,
                $progressCallback,
            );

            // Update export record
            $export->filePath = $result['path'];
            $export->fileSize = $result['size'];
            $export->recordCount = $result['recordCount'];
            $export->status = ExportRecord::STATUS_COMPLETED;
            $export->progress = 100;
            $export->completedAt = new DateTime();
            $export->save();

            $this->logInfo('Combined export generated successfully', [
                'id' => $export->id,
                'format' => $export->format,
                'recordCount' => $export->recordCount,
                'entityCount' => count($entityIds),
            ]);

            return true;
        } catch (\Throwable $e) {
            $export->status = ExportRecord::STATUS_FAILED;
            $export->errorMessage = $e->getMessage();
            $export->completedAt = new DateTime();
            $export->save();

            $this->logError('Combined export generation failed', [
                'id' => $export->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete an export
     *
     * @param int $id Export ID
     * @return bool
     */
    public function deleteExport(int $id): bool
    {
        $export = $this->getExportById($id);

        if (!$export) {
            return false;
        }

        // Delete the file if it exists
        if (!empty($export->filePath)) {
            $this->_deleteExportFile($export->filePath);
        }

        if (!$export->delete()) {
            $this->logError('Failed to delete export', [
                'id' => $id,
                'errors' => $export->getErrors(),
            ]);
            return false;
        }

        $this->logInfo('Export deleted', ['id' => $id]);

        return true;
    }

    /**
     * Delete an export file from storage
     *
     * @param string $filePath The file path (relative for volume, absolute for local)
     */
    private function _deleteExportFile(string $filePath): void
    {
        try {
            if ($this->_useVolume && $this->_volumeFs !== null) {
                // Delete from volume
                if ($this->_volumeFs->fileExists($filePath)) {
                    $this->_volumeFs->deleteFile($filePath);
                }
            } else {
                // Delete from local filesystem
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
        } catch (\Exception $e) {
            $this->logWarning('Failed to delete export file', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get file content from storage for download
     *
     * @param ExportRecord $export Export record
     * @return string|null File content or null if not found
     */
    public function getFileContent(ExportRecord $export): ?string
    {
        if (empty($export->filePath)) {
            return null;
        }

        try {
            if ($this->_useVolume && $this->_volumeFs !== null) {
                // Read from volume
                if ($this->_volumeFs->fileExists($export->filePath)) {
                    return $this->_volumeFs->read($export->filePath);
                }
            } else {
                // Read from local filesystem
                if (file_exists($export->filePath)) {
                    return file_get_contents($export->filePath);
                }
            }
        } catch (\Exception $e) {
            $this->logError('Failed to read export file', [
                'path' => $export->filePath,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Check if export file exists in storage
     *
     * @param ExportRecord $export Export record
     * @return bool
     */
    public function fileExists(ExportRecord $export): bool
    {
        if (empty($export->filePath)) {
            return false;
        }

        try {
            if ($this->_useVolume && $this->_volumeFs !== null) {
                return $this->_volumeFs->fileExists($export->filePath);
            }
            return file_exists($export->filePath);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build a file availability map for visible exports.
     *
     * This is intended for listing pages and should only be called for the
     * exports already loaded for the current page.
     *
     * @param ExportRecord[] $exports Export records
     * @return array<int, bool> Map of export ID to file availability
     */
    public function getFileAvailabilityMap(array $exports): array
    {
        $availability = [];

        foreach ($exports as $export) {
            if (!$export->isCompleted()) {
                $availability[$export->id] = false;
                continue;
            }

            $availability[$export->id] = $this->fileExists($export);
        }

        return $availability;
    }

    /**
     * Get download URL for an export
     *
     * @param ExportRecord $export Export record
     * @return string|null
     */
    public function getDownloadUrl(ExportRecord $export): ?string
    {
        if ($export->status !== ExportRecord::STATUS_COMPLETED) {
            return null;
        }

        return Craft::$app->getUrlManager()->createUrl(
            'report-manager/exports/download/' . $export->id
        );
    }

    /**
     * Update progress for a queued provider export.
     *
     * @param ExportRecord $export Export record
     * @param int $progress Progress percentage
     * @param string|null $message Optional progress message
     * @param callable(int): void|null $progressCallback Progress callback receiving an integer percentage
     */
    private function updateQueuedExportProgress(
        ExportRecord $export,
        int $progress,
        ?string $message = null,
        ?callable $progressCallback = null,
    ): void {
        $progress = max(0, min(99, $progress));
        $export->progress = $progress;

        if ($message !== null && $message !== '') {
            $metadata = $export->getMetadataArray();
            $metadata['progressMessage'] = $message;
            $export->setMetadataArray($metadata);
        }

        $export->save();
        if ($progressCallback !== null) {
            $progressCallback($progress);
        }
    }

    /**
     * Normalize export format aliases.
     *
     * @param string $format Export format
     * @return string
     */
    private function normalizeExportFormat(string $format): string
    {
        return match (strtolower(trim($format))) {
            'excel', 'xls' => 'xlsx',
            default => strtolower(trim($format)),
        };
    }

    /**
     * Ensure a filename has the expected extension.
     *
     * @param string $filename Filename from caller/provider
     * @param string $format Normalized export format
     * @return string
     */
    private function ensureFilenameExtension(string $filename, string $format): string
    {
        $extension = strtolower($format);
        $filename = SafeSegmentHelper::filenamePart(basename(str_replace('\\', '/', trim($filename))), 'export', [
            'allowDots' => true,
        ]);

        if (!str_ends_with(strtolower($filename), '.' . $extension)) {
            $dotPosition = strrpos($filename, '.');
            if ($dotPosition !== false) {
                $filename = substr($filename, 0, $dotPosition) ?: $filename;
            }
            $filename .= '.' . $extension;
        }

        return $filename;
    }

    /**
     * Build a descriptive filename for a standard data-source export.
     *
     * Saved reports use their unique handle as the prefix. Ad-hoc exports fall
     * back to the data-source handle. The scope is an entity handle or the
     * literal `combined`, followed by the configured date range when present.
     */
    private function createStandardExportFilename(ExportRecord $export, string $scope): string
    {
        $report = $export->reportId !== null ? ReportRecord::findOne($export->reportId) : null;
        $prefix = $report?->handle ?: $export->dataSource;
        $parts = [$scope];
        $dateRangePart = $this->getDateRangeFilenamePart($export);

        if ($dateRangePart !== null) {
            $parts[] = $dateRangePart;
        }

        return ExportHelper::filename($prefix, $parts, $export->format);
    }

    /**
     * Convert the export's date selection into a stable filename segment.
     */
    private function getDateRangeFilenamePart(ExportRecord $export): ?string
    {
        $dateRange = trim((string)$export->dateRangeUsed);

        if ($dateRange === '') {
            return null;
        }

        if ($dateRange === 'custom') {
            $startDate = DateTimeHelper::toDateTime($export->dateStartUsed);
            $endDate = DateTimeHelper::toDateTime($export->dateEndUsed);
            $start = $startDate !== false ? $startDate->format('Y-m-d') : null;
            $end = $endDate !== false ? $endDate->format('Y-m-d') : null;

            if ($start !== null && $end !== null) {
                return "{$start}-to-{$end}";
            }

            if ($start !== null) {
                return "from-{$start}";
            }

            if ($end !== null) {
                return "through-{$end}";
            }

            return 'custom';
        }

        $dateRange = DateRangeHelper::normalize($dateRange, $dateRange);
        $dateRange = preg_replace('/([a-z])([A-Z])/', '$1-$2', $dateRange) ?? $dateRange;
        $dateRange = preg_replace('/([A-Za-z])(\d)/', '$1-$2', $dateRange) ?? $dateRange;
        $dateRange = preg_replace('/(\d)([A-Za-z])/', '$1-$2', $dateRange) ?? $dateRange;

        return SafeSegmentHelper::filenamePart($dateRange, 'range');
    }

    /**
     * Get the storage path for an export filename.
     *
     * @param string $filename Export filename
     * @return string
     */
    private function getExportFilePath(string $filename): string
    {
        if ($this->_useVolume) {
            return $this->_volumeSubPath . '/' . $filename;
        }

        return $this->exportBasePath . $filename;
    }

    /**
     * Cleanup old exports based on retention settings
     *
     * @return int Number of exports deleted
     */
    public function cleanupOldExports(): int
    {
        $settings = ReportManager::getInstance()->getSettings();

        if (!$settings->autoCleanupExports || $settings->exportRetention <= 0) {
            return 0;
        }

        $cutoffDate = (new DateTime())->modify("-{$settings->exportRetention} days");
        $deletedCount = 0;

        /** @var ExportRecord[] $oldExports */
        $oldExports = ExportRecord::find()
            ->where(['<', 'dateCreated', Db::prepareDateForDb($cutoffDate)])
            ->all();

        foreach ($oldExports as $export) {
            if ($this->deleteExport($export->id)) {
                $deletedCount++;
            }
        }

        if ($deletedCount > 0) {
            $this->logInfo('Cleaned up old exports', ['count' => $deletedCount]);
        }

        return $deletedCount;
    }

    /**
     * Get export statistics
     *
     * @return array
     */
    public function getExportStats(): array
    {
        $row = (new \craft\db\Query())
            ->from(ExportRecord::tableName())
            ->select([
                'total' => 'COUNT(*)',
                'completed' => 'SUM(CASE WHEN [[status]] = :completed THEN 1 ELSE 0 END)',
                'failed' => 'SUM(CASE WHEN [[status]] = :failed THEN 1 ELSE 0 END)',
                'pending' => 'SUM(CASE WHEN [[status]] = :pending THEN 1 ELSE 0 END)',
                'processing' => 'SUM(CASE WHEN [[status]] = :processing THEN 1 ELSE 0 END)',
                'totalFileSize' => 'SUM(CASE WHEN [[status]] = :completed THEN [[fileSize]] ELSE 0 END)',
            ])
            ->params([
                ':completed' => ExportRecord::STATUS_COMPLETED,
                ':failed' => ExportRecord::STATUS_FAILED,
                ':pending' => ExportRecord::STATUS_PENDING,
                ':processing' => ExportRecord::STATUS_PROCESSING,
            ])
            ->one() ?: [];

        $totalFileSize = (int) ($row['totalFileSize'] ?? 0);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'totalFileSize' => $totalFileSize,
            'formattedFileSize' => $this->formatFileSize($totalFileSize),
        ];
    }

    /**
     * Format file size for display
     *
     * @param int $bytes File size in bytes
     * @return string
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }
}

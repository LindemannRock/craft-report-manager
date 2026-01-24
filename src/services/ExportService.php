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
use craft\helpers\Db;
use craft\helpers\FileHelper;
use DateTime;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\ReportManager;

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
        $this->setLoggingHandle('report-manager');
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
     */
    public function getFilteredExports(array $params = []): array
    {
        $search = $params['search'] ?? '';
        $status = $params['status'] ?? null;
        $format = $params['format'] ?? null;
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
     * @since 5.0.0
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
     * @since 5.0.0
     */
    public function getExportsForReport(int $reportId, array $params = []): array
    {
        $page = max(1, $params['page'] ?? 1);
        $limit = $params['limit'] ?? 20;

        $query = ExportRecord::find()
            ->where(['reportId' => $reportId])
            ->orderBy(['dateCreated' => SORT_DESC]);

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
     * Get an export by ID
     *
     * @param int $id Export ID
     * @return ExportRecord|null
     * @since 5.0.0
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
     * @since 5.0.0
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
            ? ($options['dateStart'] instanceof DateTime ? $options['dateStart'] : new DateTime($options['dateStart']))
            : null;
        $export->dateEndUsed = isset($options['dateEnd'])
            ? ($options['dateEnd'] instanceof DateTime ? $options['dateEnd'] : new DateTime($options['dateEnd']))
            : null;

        // Field handles
        if (!empty($options['fieldHandles'])) {
            $export->setFieldHandlesUsedArray($options['fieldHandles']);
        }

        // Site IDs filter
        if (!empty($options['siteIds']) && is_array($options['siteIds'])) {
            $export->setSiteIdsUsedArray($options['siteIds']);
        }

        // Generate filename
        $timestamp = (new DateTime())->format('Y-m-d_H-i-s');
        $entityHandle = $entity['handle'] ?? 'export';
        $export->filename = "{$dataSource}_{$entityHandle}_{$timestamp}.{$format}";

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
     * Generate an export
     *
     * @param ExportRecord $export Export record
     * @return bool
     * @since 5.0.0
     */
    public function generateExport(ExportRecord $export): bool
    {
        // Update status to processing
        $export->status = ExportRecord::STATUS_PROCESSING;
        $export->startedAt = new DateTime();
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

            // Site IDs filter
            $siteIds = $export->getSiteIdsUsedArray();
            if (!empty($siteIds)) {
                $options['siteIds'] = $siteIds;
            }

            // Get export data
            $fieldHandles = $export->getFieldHandlesUsedArray();
            $exportData = $dataSource->exportToArray($export->entityId, $fieldHandles, $options);

            // Ensure export directory exists (for local storage)
            if (!$this->_useVolume) {
                FileHelper::createDirectory($this->exportBasePath);
            }

            // Generate file based on format
            $result = match ($export->format) {
                'csv' => $this->generateCsvFile($export, $exportData),
                'json' => $this->generateJsonFile($export, $exportData),
                'xlsx' => $this->generateXlsxFile($export, $exportData),
                default => throw new \Exception("Unsupported export format: {$export->format}"),
            };

            // Update export record
            $export->filePath = $result['path'];
            $export->fileSize = $result['size'];
            $export->recordCount = count($exportData['rows']);
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
     * Generate CSV file
     *
     * @param ExportRecord $export Export record
     * @param array $data Export data with headers and rows
     * @return array{path: string, size: int} File path and size
     */
    private function generateCsvFile(ExportRecord $export, array $data): array
    {
        $settings = ReportManager::getInstance()->getSettings();

        // Build CSV content in memory
        $handle = fopen('php://temp', 'r+');

        // Add BOM for Excel compatibility
        if ($settings->csvIncludeBom) {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        // Write headers
        fputcsv($handle, $data['headers'], $settings->csvDelimiter, $settings->csvEnclosure);

        // Write rows
        foreach ($data['rows'] as $row) {
            fputcsv($handle, $row, $settings->csvDelimiter, $settings->csvEnclosure);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

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

        $content = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

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
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set sheet title (max 31 characters)
        $sheetTitle = $export->entityName ?? 'Export';
        $sheetTitle = mb_substr($sheetTitle, 0, 31);
        // Remove invalid characters for sheet name
        $sheetTitle = preg_replace('/[\\\\\/\*\?\[\]\:]/', '_', $sheetTitle);
        $sheet->setTitle($sheetTitle);

        // Write headers (row 1)
        $colIndex = 1;
        foreach ($data['headers'] as $header) {
            $sheet->setCellValue([$colIndex, 1], $header);
            $colIndex++;
        }

        // Style headers - bold and background color
        $headerRange = 'A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($data['headers'])) . '1';
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

        // Write data rows (starting at row 2)
        $rowIndex = 2;
        foreach ($data['rows'] as $row) {
            $colIndex = 1;
            foreach ($row as $value) {
                $sheet->setCellValue([$colIndex, $rowIndex], $value);
                $colIndex++;
            }
            $rowIndex++;
        }

        // Auto-size columns
        foreach (range(1, count($data['headers'])) as $colIdx) {
            $sheet->getColumnDimensionByColumn($colIdx)->setAutoSize(true);
        }

        // Freeze header row
        $sheet->freezePane('A2');

        // Write to temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempFile);

        // Get content from temp file
        $content = file_get_contents($tempFile);
        unlink($tempFile);

        // Clean up spreadsheet memory
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $this->_writeExportFile($export, $content);
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
     * Create a combined export record (multiple forms in one file)
     *
     * @param string $dataSource Data source handle
     * @param int[] $entityIds Entity IDs
     * @param string $format Export format
     * @param array $options Additional options
     * @return ExportRecord
     * @since 5.0.0
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
        $export->format = $format;
        $export->status = ExportRecord::STATUS_PENDING;
        $export->progress = 0;
        $export->triggeredBy = $options['triggeredBy'] ?? ExportRecord::TRIGGER_MANUAL;
        $export->triggeredByUserId = $options['triggeredByUserId'] ?? Craft::$app->getUser()->getId();
        $export->reportId = $options['reportId'] ?? null;

        // Date range
        $export->dateRangeUsed = $options['dateRange'] ?? null;
        $export->dateStartUsed = isset($options['dateStart'])
            ? ($options['dateStart'] instanceof DateTime ? $options['dateStart'] : new DateTime($options['dateStart']))
            : null;
        $export->dateEndUsed = isset($options['dateEnd'])
            ? ($options['dateEnd'] instanceof DateTime ? $options['dateEnd'] : new DateTime($options['dateEnd']))
            : null;

        // Site IDs filter
        if (!empty($options['siteIds']) && is_array($options['siteIds'])) {
            $export->setSiteIdsUsedArray($options['siteIds']);
        }

        // Generate filename
        $timestamp = (new DateTime())->format('Y-m-d_H-i-s');
        $export->filename = "{$dataSource}_combined_{$timestamp}.{$format}";

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
     * Generate a combined export (multiple forms in one file)
     *
     * @param ExportRecord $export Export record
     * @return bool
     * @since 5.0.0
     */
    public function generateCombinedExport(ExportRecord $export): bool
    {
        $export->status = ExportRecord::STATUS_PROCESSING;
        $export->startedAt = new DateTime();
        $export->save();

        try {
            $dataSource = ReportManager::getInstance()->dataSources->getDataSource($export->dataSource);

            if ($dataSource === null) {
                throw new \Exception("Data source '{$export->dataSource}' not found");
            }

            $entityIds = $export->getEntityIdsArray();

            if (empty($entityIds)) {
                throw new \Exception('No forms selected for combined export');
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

            // Site IDs filter
            $siteIds = $export->getSiteIdsUsedArray();
            if (!empty($siteIds)) {
                $options['siteIds'] = $siteIds;
            }

            // Collect all unique headers and data from all forms
            $allHeaders = ['Form Name']; // First column is always form name
            $allRows = [];
            $formFields = []; // Track fields per form

            // First pass: collect all unique field headers
            foreach ($entityIds as $entityId) {
                $entity = $dataSource->getEntity($entityId);
                $formName = $entity['name'] ?? "Form {$entityId}";

                $fields = $dataSource->getEntityFields($entityId);
                $formFields[$entityId] = [
                    'name' => $formName,
                    'fields' => $fields,
                ];

                foreach ($fields as $field) {
                    if (!in_array($field['label'], $allHeaders, true)) {
                        $allHeaders[] = $field['label'];
                    }
                }
            }

            // Second pass: collect data with proper column alignment
            foreach ($entityIds as $entityId) {
                $formName = $formFields[$entityId]['name'];
                $fields = $formFields[$entityId]['fields'];

                // Create a map of field handle to header position
                $fieldToHeader = [];
                foreach ($fields as $field) {
                    $fieldToHeader[$field['handle']] = $field['label'];
                }

                // Get export data for this form
                $exportData = $dataSource->exportToArray($entityId, [], $options);

                // Map each row to the combined headers
                foreach ($exportData['rows'] as $row) {
                    $combinedRow = array_fill(0, count($allHeaders), '');
                    $combinedRow[0] = $formName; // Form name in first column

                    // Map values to correct header positions
                    foreach ($exportData['headers'] as $index => $header) {
                        $headerPosition = array_search($header, $allHeaders, true);

                        if ($headerPosition !== false && isset($row[$index])) {
                            $combinedRow[$headerPosition] = $row[$index];
                        }
                    }

                    $allRows[] = $combinedRow;
                }
            }

            $combinedData = [
                'headers' => $allHeaders,
                'rows' => $allRows,
            ];

            // Ensure export directory exists (for local storage)
            if (!$this->_useVolume) {
                FileHelper::createDirectory($this->exportBasePath);
            }

            // Generate file based on format
            $result = match ($export->format) {
                'csv' => $this->generateCsvFile($export, $combinedData),
                'json' => $this->generateJsonFile($export, $combinedData),
                'xlsx' => $this->generateXlsxFile($export, $combinedData),
                default => throw new \Exception("Unsupported export format: {$export->format}"),
            };

            // Update export record
            $export->filePath = $result['path'];
            $export->fileSize = $result['size'];
            $export->recordCount = count($allRows);
            $export->status = ExportRecord::STATUS_COMPLETED;
            $export->progress = 100;
            $export->completedAt = new DateTime();
            $export->save();

            $this->logInfo('Combined export generated successfully', [
                'id' => $export->id,
                'format' => $export->format,
                'recordCount' => $export->recordCount,
                'formCount' => count($entityIds),
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * Get download URL for an export
     *
     * @param ExportRecord $export Export record
     * @return string|null
     * @since 5.0.0
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
     * Cleanup old exports based on retention settings
     *
     * @return int Number of exports deleted
     * @since 5.0.0
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
     * @since 5.0.0
     */
    public function getExportStats(): array
    {
        $totalExports = ExportRecord::find()->count();
        $completedExports = ExportRecord::find()->where(['status' => ExportRecord::STATUS_COMPLETED])->count();
        $failedExports = ExportRecord::find()->where(['status' => ExportRecord::STATUS_FAILED])->count();
        $pendingExports = ExportRecord::find()->where(['status' => ExportRecord::STATUS_PENDING])->count();
        $processingExports = ExportRecord::find()->where(['status' => ExportRecord::STATUS_PROCESSING])->count();

        // Calculate total file size
        $totalFileSize = ExportRecord::find()
            ->where(['status' => ExportRecord::STATUS_COMPLETED])
            ->sum('fileSize') ?? 0;

        return [
            'total' => $totalExports,
            'completed' => $completedExports,
            'failed' => $failedExports,
            'pending' => $pendingExports,
            'processing' => $processingExports,
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

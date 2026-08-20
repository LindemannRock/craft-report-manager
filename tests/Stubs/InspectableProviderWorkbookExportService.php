<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Stubs;

use Closure;
use lindemannrock\reportmanager\records\ExportRecord;
use lindemannrock\reportmanager\services\ExportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\IWriter;
use Throwable;

/**
 * Failure-injection seam for provider workbook staging lifecycle tests.
 *
 * @since 5.6.0
 */
final class InspectableProviderWorkbookExportService extends ExportService
{
    public bool $allocationFails = false;
    public bool $readFails = false;
    public bool $repeatCleanup = false;
    public ?Throwable $writerConstructionFailure = null;
    public ?Throwable $writerSaveFailure = null;
    public ?Throwable $cleanupFailure = null;
    public ?Throwable $finalWriteFailure = null;

    /** @var Closure(string): void|null */
    public ?Closure $beforeWriterSave = null;

    /** @var string[] */
    public array $allocatedTempFiles = [];

    /** @var string[] */
    public array $cleanupAttempts = [];

    /** @var Spreadsheet[] */
    public array $spreadsheets = [];

    /** @var string[] */
    public array $finalWriteStorageTypes = [];

    protected function createProviderWorkbookSpreadsheet(): Spreadsheet
    {
        $spreadsheet = parent::createProviderWorkbookSpreadsheet();
        $this->spreadsheets[] = $spreadsheet;

        return $spreadsheet;
    }

    protected function allocateProviderWorkbookTempFile(): string|false
    {
        if ($this->allocationFails) {
            return false;
        }

        $tempFile = parent::allocateProviderWorkbookTempFile();
        if ($tempFile !== false) {
            $this->allocatedTempFiles[] = $tempFile;
        }

        return $tempFile;
    }

    protected function createProviderWorkbookWriter(Spreadsheet $spreadsheet): IWriter
    {
        if ($this->writerConstructionFailure !== null) {
            throw $this->writerConstructionFailure;
        }

        return parent::createProviderWorkbookWriter($spreadsheet);
    }

    protected function saveProviderWorkbook(IWriter $writer, string $tempFile): void
    {
        if ($this->beforeWriterSave !== null) {
            $callback = $this->beforeWriterSave;
            $this->beforeWriterSave = null;
            $callback($tempFile);
        }

        if ($this->writerSaveFailure !== null) {
            throw $this->writerSaveFailure;
        }

        parent::saveProviderWorkbook($writer, $tempFile);
    }

    protected function readProviderWorkbookTempFile(string $tempFile): string|false
    {
        if ($this->readFails) {
            return false;
        }

        return parent::readProviderWorkbookTempFile($tempFile);
    }

    protected function removeProviderWorkbookTempFile(string $tempFile): void
    {
        $this->cleanupAttempts[] = $tempFile;
        parent::removeProviderWorkbookTempFile($tempFile);

        if ($this->repeatCleanup) {
            parent::removeProviderWorkbookTempFile($tempFile);
        }

        if ($this->cleanupFailure !== null) {
            throw $this->cleanupFailure;
        }
    }

    /** @return array{path: string, size: int} */
    protected function writeProviderWorkbookFile(ExportRecord $export, string $content): array
    {
        $this->finalWriteStorageTypes[] = (string)$export->storageType;

        if ($this->finalWriteFailure !== null) {
            throw $this->finalWriteFailure;
        }

        return parent::writeProviderWorkbookFile($export, $content);
    }
}

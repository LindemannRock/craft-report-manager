<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\export;

use lindemannrock\base\helpers\ExportHelper;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Disk-backed writer for bounded standard exports.
 *
 * Rows are written as they are produced so CSV, JSON, and XLSX generation do
 * not retain the complete export in PHP memory.
 *
 * @internal
 * @since 5.5.2
 */
final class StreamedExportWriter
{
    /** @var resource|null */
    private $stream = null;

    private ?XlsxWriter $xlsxWriter = null;

    /** @var string[] */
    private array $headers;

    /** @var int[] */
    private array $columnWidths = [];

    private string $tempPath;

    private bool $firstJsonRow = true;

    private bool $finished = false;

    /**
     * @param string[] $headers Export column labels
     * @param array{delimiter?: string, enclosure?: string, includeBom?: bool, sheetTitle?: string} $options
     */
    public function __construct(
        private readonly string $format,
        array $headers,
        private readonly array $options = [],
    ) {
        $this->headers = array_values(array_map('strval', $headers));
        $tempPath = tempnam(sys_get_temp_dir(), 'report-manager-export-');

        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create a temporary export file.');
        }

        $this->tempPath = $tempPath;

        try {
            match ($this->format) {
                'csv' => $this->openCsv(),
                'json' => $this->openJson(),
                'xlsx' => $this->openXlsx(),
                default => throw new \InvalidArgumentException("Unsupported export format: {$this->format}"),
            };
        } catch (\Throwable $e) {
            $this->abort();
            throw $e;
        }
    }

    public function __destruct()
    {
        $this->abort();
    }

    /**
     * Append one bounded group of rows.
     *
     * @param array<int, array<int, mixed>> $rows
     */
    public function writeRows(array $rows): void
    {
        foreach ($rows as $row) {
            match ($this->format) {
                'csv' => $this->writeCsvRow($row),
                'json' => $this->writeJsonRow($row),
                'xlsx' => $this->writeXlsxRow($row),
                default => throw new \LogicException("Unsupported export format: {$this->format}"),
            };
        }
    }

    /**
     * Finalize the output and return its temporary path.
     */
    public function finish(): string
    {
        if ($this->finished) {
            return $this->tempPath;
        }

        match ($this->format) {
            'csv' => $this->closeStream(),
            'json' => $this->closeJson(),
            'xlsx' => $this->closeXlsx(),
            default => throw new \LogicException("Unsupported export format: {$this->format}"),
        };

        $this->finished = true;

        return $this->tempPath;
    }

    /**
     * Release open resources and remove incomplete output.
     */
    public function abort(): void
    {
        if ($this->finished) {
            return;
        }

        if ($this->xlsxWriter !== null) {
            try {
                $this->xlsxWriter->close();
            } catch (\Throwable) {
                // The original generation exception is more useful.
            }
            $this->xlsxWriter = null;
        }

        $this->closeStream();

        if (isset($this->tempPath) && is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }

    private function openCsv(): void
    {
        $this->stream = $this->openFileStream();

        if ($this->options['includeBom'] ?? false) {
            $this->writeBytes("\xEF\xBB\xBF");
        }

        $this->writeCsvRow($this->headers);
    }

    private function openJson(): void
    {
        $this->stream = $this->openFileStream();
        $this->writeBytes('[');
    }

    private function openXlsx(): void
    {
        $this->xlsxWriter = new XlsxWriter();
        $this->xlsxWriter->openToFile($this->tempPath);

        $sheet = $this->xlsxWriter->getCurrentSheet();
        $sheet->setName($this->sanitizeSheetTitle((string)($this->options['sheetTitle'] ?? 'Export')));
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));

        $headerStyle = (new Style())
            ->setFontBold()
            ->setBackgroundColor('E5E7EB');

        $this->updateColumnWidths($this->headers);
        $this->xlsxWriter->addRow($this->createXlsxRow($this->headers, $headerStyle));
    }

    /** @return resource */
    private function openFileStream()
    {
        $stream = fopen($this->tempPath, 'wb');

        if ($stream === false) {
            throw new \RuntimeException('Unable to open the temporary export file.');
        }

        return $stream;
    }

    /** @param array<int, mixed> $row */
    private function writeCsvRow(array $row): void
    {
        if (!is_resource($this->stream)) {
            throw new \RuntimeException('CSV export stream is not open.');
        }

        $delimiter = (string)($this->options['delimiter'] ?? ',');
        $enclosure = (string)($this->options['enclosure'] ?? '"');
        $values = array_map(
            static fn(mixed $value): mixed => ExportHelper::isDangerousValue($value) ? "'" . $value : $value,
            array_values($row),
        );

        if (fputcsv($this->stream, $values, $delimiter, $enclosure, '') === false) {
            throw new \RuntimeException('Unable to write a CSV export row.');
        }
    }

    /** @param array<int, mixed> $row */
    private function writeJsonRow(array $row): void
    {
        $jsonRow = [];

        foreach ($this->headers as $index => $header) {
            $jsonRow[$header] = $row[$index] ?? null;
        }

        $encoded = json_encode(
            $jsonRow,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode an export row as JSON: ' . json_last_error_msg());
        }

        if (!$this->firstJsonRow) {
            $this->writeBytes(',');
        }

        $this->writeBytes("\n    " . str_replace("\n", "\n    ", $encoded));
        $this->firstJsonRow = false;
    }

    /** @param array<int, mixed> $row */
    private function writeXlsxRow(array $row): void
    {
        if ($this->xlsxWriter === null) {
            throw new \RuntimeException('XLSX export writer is not open.');
        }

        $values = array_values($row);
        $this->updateColumnWidths($values);
        $this->xlsxWriter->addRow($this->createXlsxRow($values));
    }

    /**
     * Keep string cells explicit so values beginning with `=` are never
     * interpreted as formulas by the spreadsheet writer.
     *
     * @param array<int, mixed> $values
     */
    private function createXlsxRow(array $values, ?Style $style = null): Row
    {
        $cells = array_map(
            static fn(mixed $value): Cell => is_string($value)
                ? new StringCell($value, null)
                : Cell::fromValue(is_scalar($value) || $value === null ? $value : (string)$value),
            $values,
        );

        return new Row($cells, $style);
    }

    /** @param array<int, mixed> $values */
    private function updateColumnWidths(array $values): void
    {
        foreach ($values as $index => $value) {
            $length = mb_strlen((string)$value);
            $this->columnWidths[$index] = max($this->columnWidths[$index] ?? 0, $length);
        }
    }

    private function closeJson(): void
    {
        $this->writeBytes($this->firstJsonRow ? ']' : "\n]");
        $this->closeStream();
    }

    private function closeXlsx(): void
    {
        if ($this->xlsxWriter === null) {
            return;
        }

        $sheet = $this->xlsxWriter->getCurrentSheet();
        foreach ($this->columnWidths as $index => $length) {
            $sheet->setColumnWidth((float)min(60, max(10, $length + 2)), $index + 1);
        }

        $this->xlsxWriter->close();
        $this->xlsxWriter = null;
    }

    private function closeStream(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    private function writeBytes(string $bytes): void
    {
        if (!is_resource($this->stream) || fwrite($this->stream, $bytes) === false) {
            throw new \RuntimeException('Unable to write to the temporary export file.');
        }
    }

    private function sanitizeSheetTitle(string $title): string
    {
        $title = preg_replace('/[\\\\\/\*\?\[\]\:]/', '_', $title) ?: 'Export';
        $title = trim($title) !== '' ? trim($title) : 'Export';

        return mb_substr($title, 0, 31);
    }
}

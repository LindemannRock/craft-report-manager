<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\export;

/**
 * Queued Export Result
 *
 * Describes generated export data before Report Manager writes it to storage.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.3.0
 */
class QueuedExportResult
{
    public const TYPE_TABLE = 'table';
    public const TYPE_WORKBOOK = 'workbook';
    public const TYPE_FILES = 'files';

    /**
     * @param string $type Result type
     * @param array $data Result data
     * @param int $recordCount Number of exported records
     * @param string[] $warnings Non-fatal warnings
     */
    private function __construct(
        private string $type,
        private array $data,
        private int $recordCount,
        private array $warnings = [],
    ) {
    }

    /**
     * Create a single table export result.
     *
     * @param string[] $headers Column headers
     * @param array<int, array<int, mixed>> $rows Row values
     * @param int|null $recordCount Override record count
     * @param string[] $warnings Non-fatal warnings
     * @return self
     */
    public static function table(array $headers, array $rows, ?int $recordCount = null, array $warnings = []): self
    {
        return new self(self::TYPE_TABLE, [
            'headers' => array_values($headers),
            'rows' => array_values($rows),
        ], $recordCount ?? count($rows), $warnings);
    }

    /**
     * Create a multi-sheet workbook result.
     *
     * Each sheet must contain `name`, `headers`, and `rows` keys.
     *
     * @param array<int, array{name: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}> $sheets Sheet data
     * @param int|null $recordCount Override record count
     * @param string[] $warnings Non-fatal warnings
     * @return self
     */
    public static function workbook(array $sheets, ?int $recordCount = null, array $warnings = []): self
    {
        $count = $recordCount;
        if ($count === null) {
            $count = 0;
            foreach ($sheets as $sheet) {
                $count += count($sheet['rows']);
            }
        }

        return new self(self::TYPE_WORKBOOK, [
            'sheets' => array_values($sheets),
        ], $count, $warnings);
    }

    /**
     * Create a multi-file ZIP result.
     *
     * Each file must provide `filename` plus either `contents` or `path`.
     *
     * @param array<int, array{filename: string, contents?: string, path?: string}> $files File manifest
     * @param int|null $recordCount Override record count
     * @param string[] $warnings Non-fatal warnings
     * @return self
     */
    public static function files(array $files, ?int $recordCount = null, array $warnings = []): self
    {
        return new self(self::TYPE_FILES, [
            'files' => array_values($files),
        ], $recordCount ?? count($files), $warnings);
    }

    /**
     * Get the result type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get table data.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function getTableData(): array
    {
        return $this->data;
    }

    /**
     * Get workbook sheets.
     *
     * @return array<int, array{name: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}>
     */
    public function getSheets(): array
    {
        return $this->data['sheets'] ?? [];
    }

    /**
     * Get file manifest.
     *
     * @return array<int, array{filename: string, contents?: string, path?: string}>
     */
    public function getFiles(): array
    {
        return $this->data['files'] ?? [];
    }

    /**
     * Get exported record count.
     *
     * @return int
     */
    public function getRecordCount(): int
    {
        return $this->recordCount;
    }

    /**
     * Get non-fatal warnings.
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}

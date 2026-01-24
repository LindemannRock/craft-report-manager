<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\records;

use craft\db\ActiveRecord;

/**
 * Export Record
 *
 * Represents a generated export file in the database.
 *
 * @property int $id
 * @property int|null $reportId
 * @property string $dataSource
 * @property int $entityId
 * @property string|null $entityName
 * @property string|null $dateRangeUsed
 * @property \DateTime|null $dateStartUsed
 * @property \DateTime|null $dateEndUsed
 * @property string|null $fieldHandlesUsed JSON array
 * @property string|null $siteIdsUsed JSON array of site IDs
 * @property string $format
 * @property string $filename
 * @property string $filePath
 * @property int $fileSize
 * @property int $recordCount
 * @property string $status
 * @property int $progress
 * @property string|null $errorMessage
 * @property string $triggeredBy
 * @property int|null $triggeredByUserId
 * @property \DateTime|null $startedAt
 * @property \DateTime|null $completedAt
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class ExportRecord extends ActiveRecord
{
    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Trigger constants
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_API = 'api';

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%reportmanager_exports}}';
    }

    /**
     * Get report relation
     *
     * @return \yii\db\ActiveQuery
     * @since 5.0.0
     */
    public function getReport(): \yii\db\ActiveQuery
    {
        return $this->hasOne(ReportRecord::class, ['id' => 'reportId']);
    }

    /**
     * @var array|null Cached entity IDs for combined exports
     */
    private ?array $entityIdsArray = null;

    /**
     * Get field handles used as array
     *
     * @return array
     * @since 5.0.0
     */
    public function getFieldHandlesUsedArray(): array
    {
        if (empty($this->fieldHandlesUsed)) {
            return [];
        }

        $decoded = json_decode($this->fieldHandlesUsed, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set field handles used from array
     *
     * @param array $handles
     * @since 5.0.0
     */
    public function setFieldHandlesUsedArray(array $handles): void
    {
        $this->fieldHandlesUsed = json_encode($handles);
    }

    /**
     * Get site IDs used as array
     *
     * @return int[]
     * @since 5.0.0
     */
    public function getSiteIdsUsedArray(): array
    {
        if (empty($this->siteIdsUsed)) {
            return [];
        }

        $decoded = json_decode($this->siteIdsUsed, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /**
     * Set site IDs used from array
     *
     * @param int[] $siteIds
     * @since 5.0.0
     */
    public function setSiteIdsUsedArray(array $siteIds): void
    {
        $this->siteIdsUsed = !empty($siteIds) ? json_encode(array_map('intval', $siteIds)) : null;
    }

    /**
     * Check if this is a combined export (multiple forms)
     *
     * @return bool
     * @since 5.0.0
     */
    public function isCombinedExport(): bool
    {
        return $this->entityId === 0 && !empty($this->entityName);
    }

    /**
     * Get entity IDs for combined exports
     *
     * @return int[]
     * @since 5.0.0
     */
    public function getEntityIdsArray(): array
    {
        if ($this->entityIdsArray !== null) {
            return $this->entityIdsArray;
        }

        if (!$this->isCombinedExport() || empty($this->entityName)) {
            return $this->entityId > 0 ? [$this->entityId] : [];
        }

        // Entity IDs are stored as JSON in entityName for combined exports
        $decoded = json_decode($this->entityName, true);

        $this->entityIdsArray = is_array($decoded) ? array_map('intval', $decoded) : [];

        return $this->entityIdsArray;
    }

    /**
     * Set entity IDs for combined exports
     *
     * @param int[] $entityIds
     * @since 5.0.0
     */
    public function setEntityIdsArray(array $entityIds): void
    {
        $this->entityId = 0;
        $this->entityName = json_encode(array_map('intval', $entityIds));
        $this->entityIdsArray = array_map('intval', $entityIds);
    }

    /**
     * Check if export is pending
     *
     * @return bool
     * @since 5.0.0
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if export is processing
     *
     * @return bool
     * @since 5.0.0
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if export is completed
     *
     * @return bool
     * @since 5.0.0
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if export failed
     *
     * @return bool
     * @since 5.0.0
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Get formatted file size
     *
     * @return string
     * @since 5.0.0
     */
    public function getFormattedFileSize(): string
    {
        $bytes = $this->fileSize;

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

    /**
     * Get status label
     *
     * @return string
     * @since 5.0.0
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get status color for UI
     *
     * @return string
     * @since 5.0.0
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'grey',
            self::STATUS_PROCESSING => 'blue',
            self::STATUS_COMPLETED => 'green',
            self::STATUS_FAILED => 'red',
            default => 'grey',
        };
    }

    /**
     * Get trigger label
     *
     * @return string
     * @since 5.0.0
     */
    public function getTriggerLabel(): string
    {
        return match ($this->triggeredBy) {
            self::TRIGGER_MANUAL => 'Manual',
            self::TRIGGER_SCHEDULED => 'Scheduled',
            self::TRIGGER_API => 'API',
            default => ucfirst($this->triggeredBy),
        };
    }
}

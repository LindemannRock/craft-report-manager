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
 * Report Record
 *
 * Represents a saved report configuration in the database.
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property string $dataSource
 * @property string|null $entityIds JSON array of form IDs
 * @property string $dateRange
 * @property \DateTime|null $customDateStart
 * @property \DateTime|null $customDateEnd
 * @property string|null $fieldHandles JSON array
 * @property string $exportFormat
 * @property string $exportMode separate or combined
 * @property int|null $siteId null = all sites
 * @property bool $enableSchedule
 * @property string|null $schedule
 * @property \DateTime|null $lastGeneratedAt
 * @property \DateTime|null $nextScheduledAt
 * @property bool $enabled
 * @property int $sortOrder
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class ReportRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%reportmanager_reports}}';
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules[] = [['name', 'dataSource'], 'required'];
        $rules[] = [['name'], 'string', 'max' => 255];
        $rules[] = [['handle'], 'string', 'max' => 64];
        $rules[] = [['entityIds'], 'validateEntityIds'];

        return $rules;
    }

    /**
     * Validate that at least one entity (form) is selected
     *
     * @since 5.0.0
     */
    public function validateEntityIds(): void
    {
        $ids = $this->getEntityIdsArray();

        if (empty($ids)) {
            $this->addError('entityIds', \Craft::t('report-manager', 'Please select at least one form.'));
        }
    }

    /**
     * Get entity IDs as array
     *
     * @return int[]
     * @since 5.0.0
     */
    public function getEntityIdsArray(): array
    {
        if (empty($this->entityIds)) {
            return [];
        }

        $decoded = json_decode($this->entityIds, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /**
     * Set entity IDs from array
     *
     * @param int[] $ids
     * @since 5.0.0
     */
    public function setEntityIdsArray(array $ids): void
    {
        $this->entityIds = json_encode(array_map('intval', $ids));
    }

    /**
     * Get field handles as array
     *
     * @return array
     * @since 5.0.0
     */
    public function getFieldHandlesArray(): array
    {
        if (empty($this->fieldHandles)) {
            return [];
        }

        $decoded = json_decode($this->fieldHandles, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set field handles from array
     *
     * @param array $handles
     * @since 5.0.0
     */
    public function setFieldHandlesArray(array $handles): void
    {
        $this->fieldHandles = json_encode($handles);
    }

    /**
     * Check if this is a combined export (multiple forms in one file)
     *
     * @return bool
     * @since 5.0.0
     */
    public function isCombined(): bool
    {
        return $this->exportMode === 'combined';
    }

    /**
     * Get exports relation (generated files)
     *
     * @return \yii\db\ActiveQuery
     * @since 5.0.0
     */
    public function getExports(): \yii\db\ActiveQuery
    {
        return $this->hasMany(ExportRecord::class, ['reportId' => 'id'])
            ->orderBy(['dateCreated' => SORT_DESC]);
    }

    /**
     * Get the latest export for this report
     *
     * @return ExportRecord|null
     * @since 5.0.0
     */
    public function getLatestExport(): ?ExportRecord
    {
        /** @var ExportRecord|null */
        return ExportRecord::find()
            ->where(['reportId' => $this->id])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();
    }

    /**
     * Get export count for this report
     *
     * @return int
     * @since 5.0.0
     */
    public function getExportCount(): int
    {
        return (int) ExportRecord::find()
            ->where(['reportId' => $this->id])
            ->count();
    }

    /**
     * Get human-readable schedule label
     *
     * @return string
     * @since 5.0.0
     */
    public function getScheduleLabel(): string
    {
        return match ($this->schedule) {
            'disabled' => \Craft::t('report-manager', 'Disabled'),
            'every6hours' => \Craft::t('report-manager', 'Every 6 Hours'),
            'every12hours' => \Craft::t('report-manager', 'Every 12 Hours'),
            'daily' => \Craft::t('report-manager', 'Daily'),
            'daily2am' => \Craft::t('report-manager', 'Daily at 2:00 AM'),
            'weekly' => \Craft::t('report-manager', 'Weekly'),
            default => $this->schedule ?? '',
        };
    }
}

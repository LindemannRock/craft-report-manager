<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\records;

use Craft;
use craft\db\ActiveRecord;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\reportmanager\ReportManager;

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
 * @property string|null $entityIds JSON array of data-source entity IDs
 * @property string $dateRange
 * @property \DateTime|null $customDateStart
 * @property \DateTime|null $customDateEnd
 * @property string|null $dateField Which date column the date range filters on (null = data-source default)
 * @property string|null $fieldHandles JSON array
 * @property string $exportFormat
 * @property string $exportMode separate or combined
 * @property int|null $siteId Legacy single-site filter; null = all sites
 * @property string|null $siteIds JSON array of site IDs; null/empty = all sites
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
    private const SCHEDULE_OPTIONS = [
        'disabled',
        'every6hours',
        'every12hours',
        'daily',
        'daily2am',
        'weekly',
        'monthly',
        'every2months',
        'quarterly',
        'every6months',
        'yearly',
    ];

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
        $rules[] = [['handle'], 'validateUniqueHandle'];
        $rules[] = [['entityIds'], 'validateEntityIds'];
        $rules[] = [['customDateEnd'], 'validateCustomDateRange'];
        $rules[] = [['dateField'], 'validateDateField'];

        return $rules;
    }

    /**
     * Validate that the chosen date field is one the data source supports.
     *
     * Empty is valid — it means "use the data source default" at query time.
     *
     * @since 5.4.0
     */
    public function validateDateField(): void
    {
        if (empty($this->dateField)) {
            return;
        }

        $source = ReportManager::getInstance()?->dataSources->getDataSource($this->dataSource);
        if ($source === null) {
            return;
        }

        $allowed = array_column($source::dateFieldOptions(), 'value');
        if (!in_array($this->dateField, $allowed, true)) {
            $this->addError('dateField', Craft::t('report-manager', 'Invalid date field for the selected data source.'));
        }
    }

    /**
     * Validate the custom date range.
     *
     * An inverted range (start after end) matches no records, so the export
     * silently comes back empty. Reject it instead of producing a confusing
     * empty file. Open-ended ranges (only one bound set) stay valid.
     *
     * @since 5.4.0
     */
    public function validateCustomDateRange(): void
    {
        if ($this->dateRange !== 'custom') {
            return;
        }

        if ($this->customDateStart && $this->customDateEnd
            && $this->customDateStart > $this->customDateEnd) {
            $this->addError('customDateEnd', Craft::t('report-manager', 'End date must be on or after the start date.'));
        }
    }

    /**
     * Validate that the report handle is unique.
     *
     * @since 5.4.0
     */
    public function validateUniqueHandle(string $attribute): void
    {
        if ($this->handle === '') {
            return;
        }

        if (SlugHandleHelper::exists(self::tableName(), 'handle', $this->handle, [
            'excludeId' => $this->id,
        ])) {
            $this->addError($attribute, Craft::t('report-manager', 'Handle must be unique.'));
        }
    }

    /**
     * Validate that at least one data-source entity is selected.
     */
    public function validateEntityIds(): void
    {
        $ids = $this->getEntityIdsArray();

        if (empty($ids)) {
            $this->addError('entityIds', \Craft::t('report-manager', 'Please select at least one item.'));
        }
    }

    /**
     * Get entity IDs as array
     *
     * @return int[]
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
     */
    public function setEntityIdsArray(array $ids): void
    {
        $this->entityIds = json_encode(array_map('intval', $ids));
    }

    /**
     * Get site IDs as array.
     *
     * Empty array means all sites.
     *
     * @return int[]
     */
    public function getSiteIdsArray(): array
    {
        if (!empty($this->siteIds)) {
            $decoded = json_decode($this->siteIds, true);

            return is_array($decoded) ? array_values(array_unique(array_map('intval', $decoded))) : [];
        }

        return !empty($this->siteId) ? [(int) $this->siteId] : [];
    }

    /**
     * Set site IDs from array.
     *
     * Empty array means all sites.
     *
     * @param int[] $ids
     */
    public function setSiteIdsArray(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $this->siteIds = !empty($ids) ? json_encode($ids) : null;
        $this->siteId = count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Get field handles as array
     *
     * @return array
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
     */
    public function setFieldHandlesArray(array $handles): void
    {
        $this->fieldHandles = json_encode($handles);
    }

    /**
     * Check if this is a combined export (multiple entities in one file)
     *
     * @return bool
     */
    public function isCombined(): bool
    {
        return $this->exportMode === 'combined';
    }

    /**
     * Get exports relation (generated files)
     *
     * @return \yii\db\ActiveQuery
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
     */
    public function getScheduleLabel(): string
    {
        $options = ScheduleHelper::getOptions(self::SCHEDULE_OPTIONS, 'assoc');

        return $options[$this->schedule] ?? ($this->schedule ?? '');
    }
}

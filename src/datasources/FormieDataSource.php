<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\datasources;

use Craft;
use craft\helpers\Db;
use DateTime;
use lindemannrock\base\helpers\PluginHelper;

/**
 * Formie Data Source
 *
 * Data source integration for Verbb Formie plugin.
 * Provides access to forms and submissions for reporting.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class FormieDataSource extends BaseDataSource
{
    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'formie';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return PluginHelper::getPluginName('formie', 'Formie');
    }

    /**
     * @inheritdoc
     */
    public static function description(): string
    {
        return Craft::t('report-manager', 'Generate reports from Formie form submissions');
    }

    /**
     * @inheritdoc
     */
    public static function iconUrl(): ?string
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public static function isAvailable(): bool
    {
        return PluginHelper::isPluginInstalled('formie')
            && PluginHelper::isPluginEnabled('formie');
    }

    /**
     * @inheritdoc
     */
    public function getAvailableEntities(): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $forms = \verbb\formie\elements\Form::find()->all();
        $entities = [];

        foreach ($forms as $form) {
            if (!$form instanceof \verbb\formie\elements\Form) {
                continue;
            }

            $submissionCount = \verbb\formie\elements\Submission::find()
                ->formId($form->id)
                ->isIncomplete(false)
                ->isSpam(false)
                ->count();

            $entities[] = [
                'id' => $form->id,
                'name' => $form->title,
                'handle' => $form->handle,
                'submissionCount' => $submissionCount,
            ];
        }

        return $entities;
    }

    /**
     * @inheritdoc
     */
    public function getEntity(int $entityId): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }

        $form = \verbb\formie\elements\Form::find()->id($entityId)->one();

        if (!$form instanceof \verbb\formie\elements\Form) {
            return null;
        }

        return [
            'id' => $form->id,
            'name' => $form->title,
            'handle' => $form->handle,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getEntityFields(int $entityId): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $form = \verbb\formie\elements\Form::find()->id($entityId)->one();

        if (!$form instanceof \verbb\formie\elements\Form) {
            return [];
        }

        $fields = [];

        // Add system fields
        $fields[] = [
            'handle' => 'id',
            'label' => Craft::t('report-manager', 'Submission ID'),
            'type' => 'system',
            'exportable' => true,
        ];

        $fields[] = [
            'handle' => 'dateCreated',
            'label' => Craft::t('report-manager', 'Date Created'),
            'type' => 'system',
            'exportable' => true,
        ];

        $fields[] = [
            'handle' => 'status',
            'label' => Craft::t('report-manager', 'Status'),
            'type' => 'system',
            'exportable' => true,
        ];

        $fields[] = [
            'handle' => 'ipAddress',
            'label' => Craft::t('report-manager', 'IP Address'),
            'type' => 'system',
            'exportable' => true,
        ];

        // Add form fields
        foreach ($form->getFields() as $field) {
            $fields[] = [
                'handle' => $field->handle,
                'label' => $field->label ?? $field->handle,
                'type' => (new \ReflectionClass($field))->getShortName(),
                'exportable' => true,
            ];
        }

        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function getSubmissions(int $entityId, array $options = []): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $form = \verbb\formie\elements\Form::find()->id($entityId)->one();

        if (!$form instanceof \verbb\formie\elements\Form) {
            return [];
        }

        $query = \verbb\formie\elements\Submission::find()
            ->formId($entityId)
            ->isIncomplete(false)
            ->isSpam(false)
            ->orderBy(['dateCreated' => SORT_DESC]);

        // Apply site filter
        if (!empty($options['siteIds']) && is_array($options['siteIds'])) {
            $query->siteId($options['siteIds']);
        } else {
            // Include all sites by default
            $query->siteId('*');
        }

        // Apply date range filters
        if (!empty($options['dateStart'])) {
            $dateStart = $options['dateStart'] instanceof DateTime
                ? $options['dateStart']
                : new DateTime($options['dateStart']);
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($dateStart)]);
        }

        if (!empty($options['dateEnd'])) {
            $dateEnd = $options['dateEnd'] instanceof DateTime
                ? $options['dateEnd']
                : new DateTime($options['dateEnd']);
            $query->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($dateEnd)]);
        }

        // Apply date range shorthand
        if (!empty($options['dateRange'])) {
            $dateStart = $this->getDateRangeStart($options['dateRange']);
            $dateEnd = $this->getDateRangeEnd($options['dateRange']);

            if ($dateStart) {
                $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($dateStart)]);
            }
            if ($dateEnd) {
                $query->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($dateEnd)]);
            }
        }

        // Apply status filter
        if (!empty($options['statusId'])) {
            $query->statusId($options['statusId']);
        }

        // Apply limit and offset
        if (!empty($options['limit'])) {
            $query->limit($options['limit']);
        }

        if (!empty($options['offset'])) {
            $query->offset($options['offset']);
        }

        return $query->all();
    }

    /**
     * @inheritdoc
     */
    public function getSubmissionCount(int $entityId, array $options = []): int
    {
        if (!self::isAvailable()) {
            return 0;
        }

        $query = \verbb\formie\elements\Submission::find()
            ->formId($entityId)
            ->isIncomplete(false)
            ->isSpam(false);

        // Apply site filter
        if (!empty($options['siteIds']) && is_array($options['siteIds'])) {
            $query->siteId($options['siteIds']);
        } else {
            // Include all sites by default
            $query->siteId('*');
        }

        // Apply date range filters
        if (!empty($options['dateStart'])) {
            $dateStart = $options['dateStart'] instanceof DateTime
                ? $options['dateStart']
                : new DateTime($options['dateStart']);
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($dateStart)]);
        }

        if (!empty($options['dateEnd'])) {
            $dateEnd = $options['dateEnd'] instanceof DateTime
                ? $options['dateEnd']
                : new DateTime($options['dateEnd']);
            $query->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($dateEnd)]);
        }

        // Apply date range shorthand
        if (!empty($options['dateRange'])) {
            $dateStart = $this->getDateRangeStart($options['dateRange']);
            $dateEnd = $this->getDateRangeEnd($options['dateRange']);

            if ($dateStart) {
                $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($dateStart)]);
            }
            if ($dateEnd) {
                $query->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($dateEnd)]);
            }
        }

        // Apply status filter
        if (!empty($options['statusId'])) {
            $query->statusId($options['statusId']);
        }

        return $query->count();
    }

    /**
     * @inheritdoc
     */
    public function getAnalytics(int $entityId, string $dateRange = 'last30days'): array
    {
        if (!self::isAvailable()) {
            return $this->getEmptyAnalytics();
        }

        $form = \verbb\formie\elements\Form::find()->id($entityId)->one();

        if (!$form instanceof \verbb\formie\elements\Form) {
            return $this->getEmptyAnalytics();
        }

        // Current period submissions
        $currentCount = $this->getSubmissionCount($entityId, ['dateRange' => $dateRange]);

        // Previous period for comparison
        $previousDateRange = $this->getPreviousDateRange($dateRange);
        $previousCount = $previousDateRange
            ? $this->getSubmissionCount($entityId, [
                'dateStart' => $previousDateRange['start'],
                'dateEnd' => $previousDateRange['end'],
            ])
            : 0;

        // All time total
        $totalCount = $this->getSubmissionCount($entityId);

        // Spam count
        $spamCount = \verbb\formie\elements\Submission::find()
            ->formId($entityId)
            ->isSpam(true)
            ->count();

        // Incomplete count
        $incompleteCount = \verbb\formie\elements\Submission::find()
            ->formId($entityId)
            ->isIncomplete(true)
            ->count();

        return [
            'formId' => $entityId,
            'formName' => $form->title,
            'dateRange' => $dateRange,
            'currentPeriod' => [
                'submissions' => $currentCount,
                'change' => $this->calculatePercentageChange($currentCount, $previousCount),
            ],
            'totals' => [
                'submissions' => $totalCount,
                'spam' => $spamCount,
                'incomplete' => $incompleteCount,
            ],
            'averagePerDay' => $this->calculateAveragePerDay($entityId, $dateRange),
        ];
    }

    /**
     * @inheritdoc
     */
    public function getTrendData(int $entityId, string $dateRange = 'last30days'): array
    {
        if (!self::isAvailable()) {
            return ['labels' => [], 'values' => []];
        }

        $submissions = $this->getSubmissions($entityId, ['dateRange' => $dateRange]);
        $dateFormat = $this->getDateFormatForRange($dateRange);
        $trendData = [];

        foreach ($submissions as $submission) {
            if (!$submission instanceof \verbb\formie\elements\Submission) {
                continue;
            }

            $date = $submission->dateCreated->format($dateFormat);

            if (!isset($trendData[$date])) {
                $trendData[$date] = 0;
            }

            $trendData[$date]++;
        }

        // Sort by date
        ksort($trendData);

        return [
            'labels' => array_keys($trendData),
            'values' => array_values($trendData),
        ];
    }

    /**
     * @inheritdoc
     */
    public function exportToArray(int $entityId, array $fieldHandles = [], array $options = []): array
    {
        if (!self::isAvailable()) {
            return ['headers' => [], 'rows' => []];
        }

        $form = \verbb\formie\elements\Form::find()->id($entityId)->one();

        if (!$form instanceof \verbb\formie\elements\Form) {
            return ['headers' => [], 'rows' => []];
        }

        // Get all available fields
        $allFields = $this->getEntityFields($entityId);

        // Filter to requested fields or use all
        $fieldsToExport = empty($fieldHandles)
            ? $allFields
            : array_filter($allFields, fn($f) => in_array($f['handle'], $fieldHandles, true));

        // Build headers
        $headers = array_map(fn($f) => $f['label'], $fieldsToExport);
        $fieldHandlesToExport = array_map(fn($f) => $f['handle'], $fieldsToExport);

        // Get submissions
        $submissions = $this->getSubmissions($entityId, $options);

        // Build rows
        $rows = [];
        foreach ($submissions as $submission) {
            if (!$submission instanceof \verbb\formie\elements\Submission) {
                continue;
            }

            $row = [];
            foreach ($fieldHandlesToExport as $handle) {
                $row[] = $this->getSubmissionFieldValue($submission, $handle);
            }
            $rows[] = $row;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * Get a field value from a submission
     *
     * @param \verbb\formie\elements\Submission $submission
     * @param string $handle Field handle
     * @return string
     */
    private function getSubmissionFieldValue(\verbb\formie\elements\Submission $submission, string $handle): string
    {
        // Handle system fields
        switch ($handle) {
            case 'id':
                return (string) $submission->id;

            case 'dateCreated':
                return $submission->dateCreated?->format('Y-m-d H:i:s') ?? '';

            case 'status':
                $status = $submission->getStatus();

                if (is_string($status)) {
                    return $status;
                }

                return '';

            case 'ipAddress':
                return $submission->ipAddress ?? '';
        }

        // Handle form fields
        $value = $submission->getFieldValue($handle);

        if ($value === null) {
            return '';
        }

        // Handle different value types
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        if (is_object($value)) {
            // Handle element fields (entries, categories, etc.)
            if (property_exists($value, 'title')) {
                return (string) $value->title;
            }

            // Handle name fields, address fields, etc.
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return json_encode($value) ?: '';
        }

        return (string) $value;
    }

    /**
     * Get empty analytics structure
     *
     * @return array
     */
    private function getEmptyAnalytics(): array
    {
        return [
            'formId' => 0,
            'formName' => '',
            'dateRange' => '',
            'currentPeriod' => [
                'submissions' => 0,
                'change' => 0,
            ],
            'totals' => [
                'submissions' => 0,
                'spam' => 0,
                'incomplete' => 0,
            ],
            'averagePerDay' => 0,
        ];
    }

    /**
     * Calculate average submissions per day
     *
     * @param int $entityId Form ID
     * @param string $dateRange Date range
     * @return float
     */
    private function calculateAveragePerDay(int $entityId, string $dateRange): float
    {
        $count = $this->getSubmissionCount($entityId, ['dateRange' => $dateRange]);
        $days = $this->getDateRangeDays($dateRange);

        if ($days === 0) {
            return 0;
        }

        return round($count / $days, 1);
    }

    /**
     * Get number of days in a date range
     *
     * @param string $dateRange Date range identifier
     * @return int
     */
    private function getDateRangeDays(string $dateRange): int
    {
        return match ($dateRange) {
            'today' => 1,
            'yesterday' => 1,
            'last7days' => 7,
            'last30days' => 30,
            'last90days' => 90,
            'last365days', 'lastyear' => 365,
            default => 30,
        };
    }

    /**
     * Get previous date range for comparison
     *
     * @param string $dateRange Current date range
     * @return array{start: DateTime, end: DateTime}|null
     */
    private function getPreviousDateRange(string $dateRange): ?array
    {
        $days = $this->getDateRangeDays($dateRange);
        $now = new DateTime();

        if ($dateRange === 'all') {
            return null;
        }

        $end = $this->getDateRangeStart($dateRange);
        if ($end === null) {
            return null;
        }

        $start = (clone $end)->modify("-{$days} days");

        return [
            'start' => $start,
            'end' => (clone $end)->modify('-1 second'),
        ];
    }
}

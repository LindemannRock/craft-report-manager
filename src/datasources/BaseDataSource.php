<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\datasources;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use DateTimeInterface;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\reportmanager\ReportManager;

/**
 * Base Data Source
 *
 * Abstract base class for data sources with common functionality.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
abstract class BaseDataSource implements DataSourceInterface
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public static function uiLabels(): array
    {
        return [
            'entitySingular' => Craft::t('report-manager', 'Item'),
            'entityPlural' => Craft::t('report-manager', 'Items'),
            'recordSingular' => Craft::t('report-manager', 'Record'),
            'recordPlural' => Craft::t('report-manager', 'Records'),
            'entitySelectionLabel' => Craft::t('report-manager', 'Items to Export'),
            'entitySelectionInstructions' => Craft::t('report-manager', 'Select one or more items to include in this report.'),
            'quickExportEntitySelectionInstructions' => Craft::t('report-manager', 'Select one or more items to export. Each item will generate a separate export file.'),
            'emptyEntitiesMessage' => Craft::t('report-manager', 'No items available.'),
            'selectedEntitiesLabel' => Craft::t('report-manager', 'Selected Items'),
            'combinedPrimaryColumnLabel' => Craft::t('report-manager', 'Item Name'),
            'dateRangeInstructions' => Craft::t('report-manager', 'Filter records by date range.'),
            'exportModeInstructions' => Craft::t('report-manager', 'How to handle multiple items.'),
            'dateFilterInfo' => Craft::t('report-manager', 'Records are included when their Filter by date value falls within the Date Range.'),
        ];
    }

    /**
     * @inheritdoc
     */
    public static function capabilities(): array
    {
        return [
            'fields' => true,
            'dateRanges' => true,
            'analytics' => true,
            'combinedExport' => true,
            'siteFiltering' => true,
            'scheduling' => true,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function dateFieldOptions(): array
    {
        return [
            ['value' => 'dateCreated', 'label' => Craft::t('report-manager', 'Date Created')],
            ['value' => 'dateUpdated', 'label' => Craft::t('report-manager', 'Date Updated')],
        ];
    }

    /**
     * @inheritdoc
     */
    public static function defaultDateField(): string
    {
        return 'dateCreated';
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
    public function __construct()
    {
        $this->setLoggingHandle(ReportManager::$plugin->id);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return null;
    }

    /**
     * Backward-compatible alias for older integrations.
     *
     * @param int $entityId The entity ID
     * @param array $options Query options
     * @return array
     */
    public function getSubmissions(int $entityId, array $options = []): array
    {
        return $this->getRecords($entityId, $options);
    }

    /**
     * Backward-compatible alias for older integrations.
     *
     * @param int $entityId The entity ID
     * @param array $options Query options
     * @return int
     */
    public function getSubmissionCount(int $entityId, array $options = []): int
    {
        return $this->getRecordCount($entityId, $options);
    }

    /**
     * Get date range start date
     *
     * @param string $dateRange Date range identifier
     * @return DateTime|null Start date or null for 'all'
     */
    protected function getDateRangeStart(string $dateRange): ?DateTime
    {
        return DateRangeHelper::getBounds($dateRange)['start'];
    }

    /**
     * Get date range end date
     *
     * @param string $dateRange Date range identifier
     * @return DateTime|null End date or null for current time
     */
    protected function getDateRangeEnd(string $dateRange): ?DateTime
    {
        return DateRangeHelper::getBounds($dateRange)['end'];
    }

    /**
     * Resolve which date field a query should filter on.
     *
     * Falls back to the source default when the report has not chosen one or
     * chose a value this source does not support.
     *
     * @param array $options Query options
     * @return string A value guaranteed to be in dateFieldOptions()
     * @since 5.4.0
     */
    protected function resolveDateField(array $options): string
    {
        $field = $options['dateField'] ?? '';
        $allowed = array_column(static::dateFieldOptions(), 'value');

        return in_array($field, $allowed, true) ? $field : static::defaultDateField();
    }

    /**
     * Map a date field to the query column it filters on.
     *
     * Element-backed sources qualify the column with the elements table; override
     * for fields stored elsewhere (e.g. entries.postDate) or unqualified columns.
     *
     * @param string $field A dateFieldOptions() value
     * @return string Column reference for andWhere()
     * @since 5.4.0
     */
    protected function dateColumn(string $field): string
    {
        return 'elements.' . $field;
    }

    /**
     * Resolve the effective [start, end] bounds for a query from its options.
     *
     * A custom range arrives as an explicit dateStart/dateEnd pair; a named
     * range (anything but 'custom') supplies its own bounds and takes priority.
     *
     * @param array $options Query options (dateStart, dateEnd, dateRange)
     * @return array{0: \DateTime|null, 1: \DateTime|null}
     * @since 5.4.0
     */
    protected function resolveDateBounds(array $options): array
    {
        $start = null;
        $end = null;

        if (!empty($options['dateStart'])) {
            $start = $options['dateStart'] instanceof DateTime
                ? $options['dateStart']
                : (DateTimeHelper::toDateTime($options['dateStart']) ?: null);
        }

        if (!empty($options['dateEnd'])) {
            $end = $options['dateEnd'] instanceof DateTime
                ? $options['dateEnd']
                : (DateTimeHelper::toDateTime($options['dateEnd']) ?: null);
        }

        if (!empty($options['dateRange'])) {
            $rangeStart = $this->getDateRangeStart($options['dateRange']);
            $rangeEnd = $this->getDateRangeEnd($options['dateRange']);

            if ($rangeStart) {
                $start = $rangeStart;
            }
            if ($rangeEnd) {
                $end = $rangeEnd;
            }
        }

        return [$start, $end];
    }

    /**
     * Apply the report's date-range filter to a query against the given column.
     *
     * Suitable for date columns present on the element query's main table
     * (elements.dateCreated/dateUpdated).
     * Columns living on a sub-table (e.g. entries.postDate) must instead use the
     * element query's native date param — see EntriesDataSource.
     *
     * @param ElementQuery $query Element query to constrain
     * @param array $options Query options (dateStart, dateEnd, dateRange)
     * @param string $column Column reference returned by dateColumn()
     * @since 5.4.0
     */
    protected function applyDateFilter(ElementQuery $query, array $options, string $column): void
    {
        [$start, $end] = $this->resolveDateBounds($options);

        if ($start) {
            $query->andWhere(['>=', $column, Db::prepareDateForDb($start)]);
        }
        if ($end) {
            $query->andWhere(['<=', $column, Db::prepareDateForDb($end)]);
        }
    }

    /**
     * Get appropriate date format based on range for aggregation
     *
     * @param string $dateRange Date range identifier
     * @return string PHP date format string
     */
    protected function getDateFormatForRange(string $dateRange): string
    {
        return match ($dateRange) {
            'today', 'yesterday' => 'Y-m-d H:00',
            'last7days' => 'Y-m-d',
            'last30days', 'last90days', 'thisMonth', 'lastMonth' => 'Y-m-d',
            'thisYear', 'lastYear' => 'Y-m',
            default => 'Y-W',
        };
    }

    /**
     * Get date range label options
     *
     * @return array<array{value: string, label: string}>
     */
    public static function getDateRangeOptions(): array
    {
        return DateRangeHelper::getOptions();
    }

    /**
     * Render a Twig template
     *
     * @param string $template Template path
     * @param array $variables Template variables
     * @return string Rendered HTML
     */
    protected function renderTemplate(string $template, array $variables = []): string
    {
        return Craft::$app->getView()->renderTemplate($template, $variables);
    }

    /**
     * Calculate percentage change between two values
     *
     * @param int|float $current Current value
     * @param int|float $previous Previous value
     * @return float Percentage change
     */
    protected function calculatePercentageChange(int|float $current, int|float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Format a number for display
     *
     * @param int|float $number Number to format
     * @param int $decimals Decimal places
     * @return string Formatted number
     */
    protected function formatNumber(int|float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals);
    }

    /**
     * Format a date for export.
     *
     * @param DateTimeInterface|null $date Date value
     * @return string
     */
    protected function formatExportDate(?DateTimeInterface $date): string
    {
        return $date?->format('Y-m-d H:i:s') ?? '';
    }

    /**
     * Format a field value for export.
     *
     * @param mixed $value Field value
     * @return string
     */
    protected function formatExportValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $this->formatExportDate($value);
        }

        if ($value instanceof ElementInterface) {
            return $value->getUiLabel();
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn($item) => $this->formatExportValue($item), $value));
        }

        if (is_object($value)) {
            if (method_exists($value, 'all')) {
                return implode(', ', array_map(fn($item) => $this->formatExportValue($item), $value->all()));
            }

            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return json_encode($value) ?: '';
        }

        return (string) $value;
    }

    /**
     * Get value from nested array using dot notation
     *
     * @param array $array Source array
     * @param string $key Dot-notated key
     * @param mixed $default Default value
     * @return mixed
     */
    protected function getNestedValue(array $array, string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $current = $array;

        foreach ($keys as $k) {
            if (!is_array($current) || !array_key_exists($k, $current)) {
                return $default;
            }
            $current = $current[$k];
        }

        return $current;
    }
}

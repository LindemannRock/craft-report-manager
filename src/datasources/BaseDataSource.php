<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\datasources;

use Craft;
use DateTime;
use lindemannrock\logginglibrary\traits\LoggingTrait;

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
    public static function iconUrl(): ?string
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function __construct()
    {
        $this->setLoggingHandle('report-manager');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return null;
    }

    /**
     * Get date range start date
     *
     * @param string $dateRange Date range identifier
     * @return DateTime|null Start date or null for 'all'
     */
    protected function getDateRangeStart(string $dateRange): ?DateTime
    {
        $now = new DateTime();

        return match ($dateRange) {
            'today' => (clone $now)->setTime(0, 0, 0),
            'yesterday' => (clone $now)->modify('-1 day')->setTime(0, 0, 0),
            'last7days' => (clone $now)->modify('-7 days')->setTime(0, 0, 0),
            'last30days' => (clone $now)->modify('-30 days')->setTime(0, 0, 0),
            'last90days' => (clone $now)->modify('-90 days')->setTime(0, 0, 0),
            'last365days', 'lastyear' => (clone $now)->modify('-365 days')->setTime(0, 0, 0),
            default => null,
        };
    }

    /**
     * Get date range end date
     *
     * @param string $dateRange Date range identifier
     * @return DateTime|null End date or null for current time
     */
    protected function getDateRangeEnd(string $dateRange): ?DateTime
    {
        if ($dateRange === 'yesterday') {
            return (new DateTime())->modify('-1 day')->setTime(23, 59, 59);
        }

        return null;
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
            'last30days', 'last90days' => 'Y-m-d',
            default => 'Y-W',
        };
    }

    /**
     * Get date range label options
     *
     * @return array<array{value: string, label: string}>
     * @since 5.0.0
     */
    public static function getDateRangeOptions(): array
    {
        return [
            ['value' => 'today', 'label' => Craft::t('report-manager', 'Today')],
            ['value' => 'yesterday', 'label' => Craft::t('report-manager', 'Yesterday')],
            ['value' => 'last7days', 'label' => Craft::t('report-manager', 'Last 7 Days')],
            ['value' => 'last30days', 'label' => Craft::t('report-manager', 'Last 30 Days')],
            ['value' => 'last90days', 'label' => Craft::t('report-manager', 'Last 90 Days')],
            ['value' => 'last365days', 'label' => Craft::t('report-manager', 'Last Year')],
            ['value' => 'all', 'label' => Craft::t('report-manager', 'All Time')],
        ];
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

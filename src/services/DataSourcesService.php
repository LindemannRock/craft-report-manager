<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\services;

use craft\base\Component;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\reportmanager\datasources\DataSourceInterface;
use lindemannrock\reportmanager\datasources\FormieDataSource;
use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;

/**
 * Data Sources Service
 *
 * Manages registered data sources and provides access to them.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class DataSourcesService extends Component
{
    use LoggingTrait;

    /**
     * Event fired when collecting registered data sources
     */
    public const EVENT_REGISTER_DATA_SOURCES = 'registerDataSources';

    /**
     * @var array<string, array{handle: string, name: string, class: class-string}>|null Cached data sources
     */
    private ?array $dataSources = null;

    /**
     * @var array<string, DataSourceInterface> Cached data source instances
     */
    private array $instances = [];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('report-manager');
    }

    /**
     * Get all registered data sources
     *
     * @return array<string, array{handle: string, name: string, class: class-string}>
     * @since 5.0.0
     */
    public function getRegisteredDataSources(): array
    {
        if ($this->dataSources !== null) {
            return $this->dataSources;
        }

        // Start with built-in data sources
        $event = new RegisterDataSourcesEvent();

        // Register Formie data source by default
        $event->register(
            FormieDataSource::handle(),
            FormieDataSource::displayName(),
            FormieDataSource::class
        );

        // Fire event to allow other plugins to register data sources
        $this->trigger(self::EVENT_REGISTER_DATA_SOURCES, $event);

        $this->dataSources = $event->dataSources;

        return $this->dataSources;
    }

    /**
     * Get available (installed and enabled) data sources
     *
     * @return array<string, array{handle: string, name: string, class: class-string, available: bool}>
     * @since 5.0.0
     */
    public function getAvailableDataSources(): array
    {
        $available = [];

        foreach ($this->getRegisteredDataSources() as $handle => $dataSource) {
            $class = $dataSource['class'];

            if (!class_exists($class)) {
                continue;
            }

            /** @var DataSourceInterface $class */
            if ($class::isAvailable()) {
                $available[$handle] = array_merge($dataSource, ['available' => true]);
            }
        }

        return $available;
    }

    /**
     * Get a data source instance by handle
     *
     * @param string $handle Data source handle
     * @return DataSourceInterface|null
     * @since 5.0.0
     */
    public function getDataSource(string $handle): ?DataSourceInterface
    {
        if (isset($this->instances[$handle])) {
            return $this->instances[$handle];
        }

        $dataSources = $this->getRegisteredDataSources();

        if (!isset($dataSources[$handle])) {
            $this->logWarning('Data source not found', ['handle' => $handle]);
            return null;
        }

        $class = $dataSources[$handle]['class'];

        if (!class_exists($class)) {
            $this->logWarning('Data source class not found', [
                'handle' => $handle,
                'class' => $class,
            ]);
            return null;
        }

        $instance = new $class();

        if (!$instance instanceof DataSourceInterface) {
            $this->logWarning('Data source class does not implement DataSourceInterface', [
                'handle' => $handle,
                'class' => $class,
            ]);
            return null;
        }

        $this->instances[$handle] = $instance;

        return $instance;
    }

    /**
     * Check if a data source is available
     *
     * @param string $handle Data source handle
     * @return bool
     * @since 5.0.0
     */
    public function isDataSourceAvailable(string $handle): bool
    {
        $dataSources = $this->getRegisteredDataSources();

        if (!isset($dataSources[$handle])) {
            return false;
        }

        $class = $dataSources[$handle]['class'];

        if (!class_exists($class)) {
            return false;
        }

        /** @var DataSourceInterface $class */
        return $class::isAvailable();
    }

    /**
     * Get data source options for dropdowns
     *
     * @param bool $onlyAvailable Only include available data sources
     * @return array<array{value: string, label: string, disabled: bool}>
     * @since 5.0.0
     */
    public function getDataSourceOptions(bool $onlyAvailable = true): array
    {
        $options = [];

        foreach ($this->getRegisteredDataSources() as $handle => $dataSource) {
            $class = $dataSource['class'];
            $available = class_exists($class) && $class::isAvailable();

            if ($onlyAvailable && !$available) {
                continue;
            }

            $options[] = [
                'value' => $handle,
                'label' => $dataSource['name'],
                'disabled' => !$available,
            ];
        }

        return $options;
    }

    /**
     * Get all entities from all available data sources
     *
     * @return array<string, array{dataSource: string, dataSourceName: string, entities: array}>
     * @since 5.0.0
     */
    public function getAllEntities(): array
    {
        $result = [];

        foreach ($this->getAvailableDataSources() as $handle => $dataSource) {
            $instance = $this->getDataSource($handle);

            if ($instance === null) {
                continue;
            }

            $result[$handle] = [
                'dataSource' => $handle,
                'dataSourceName' => $dataSource['name'],
                'entities' => $instance->getAvailableEntities(),
            ];
        }

        return $result;
    }
}

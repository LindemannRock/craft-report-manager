<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\events;

use yii\base\Event;

/**
 * Register Data Sources Event
 *
 * Allows plugins to register themselves as Report Manager data sources.
 *
 * Usage in other plugins:
 * ```php
 * use lindemannrock\reportmanager\events\RegisterDataSourcesEvent;
 * use lindemannrock\reportmanager\services\DataSourcesService;
 * use yii\base\Event;
 *
 * Event::on(
 *     DataSourcesService::class,
 *     DataSourcesService::EVENT_REGISTER_DATA_SOURCES,
 *     function(RegisterDataSourcesEvent $event) {
 *         $event->register('my-source', 'My Data Source', MyDataSource::class);
 *     }
 * );
 * ```
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class RegisterDataSourcesEvent extends Event
{
    /**
     * @var array<string, array{handle: string, name: string, class: class-string}> Registered data sources
     */
    public array $dataSources = [];

    /**
     * Register a data source
     *
     * @param string $handle Unique handle (e.g., 'formie', 'survey-campaigns')
     * @param string $name Display name (e.g., 'Formie', 'Survey Campaigns')
     * @param string $class Data source class that implements DataSourceInterface
     * @since 5.0.0
     */
    public function register(string $handle, string $name, string $class): void
    {
        $this->dataSources[$handle] = [
            'handle' => $handle,
            'name' => $name,
            'class' => $class,
        ];
    }
}

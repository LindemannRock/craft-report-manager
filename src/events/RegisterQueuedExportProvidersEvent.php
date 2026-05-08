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
 * Register Queued Export Providers Event
 *
 * Allows plugins to register generic queued export providers.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.3.0
 */
class RegisterQueuedExportProvidersEvent extends Event
{
    /**
     * @var array<string, array{handle: string, name: string, class: class-string}> Registered providers
     */
    public array $providers = [];

    /**
     * Register a queued export provider.
     *
     * @param string $handle Unique handle, e.g. `search-manager.analytics`
     * @param string $name Display name
     * @param string $class Provider class that implements QueuedExportProviderInterface
     */
    public function register(string $handle, string $name, string $class): void
    {
        $this->providers[$handle] = [
            'handle' => $handle,
            'name' => $name,
            'class' => $class,
        ];
    }
}

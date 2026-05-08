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
use lindemannrock\reportmanager\events\RegisterQueuedExportProvidersEvent;
use lindemannrock\reportmanager\export\QueuedExportProviderInterface;
use lindemannrock\reportmanager\ReportManager;

/**
 * Queued Export Providers Service
 *
 * Manages generic queued export providers registered by other plugins.
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.3.0
 */
class QueuedExportProvidersService extends Component
{
    use LoggingTrait;

    /**
     * Event fired when collecting queued export providers.
     */
    public const EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS = 'registerQueuedExportProviders';

    /**
     * @var array<string, array{handle: string, name: string, class: class-string}>|null Cached providers
     */
    private ?array $providers = null;

    /**
     * @var array<string, QueuedExportProviderInterface> Cached provider instances
     */
    private array $instances = [];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(ReportManager::$plugin->id);
    }

    /**
     * Get all registered providers.
     *
     * @return array<string, array{handle: string, name: string, class: class-string}>
     */
    public function getRegisteredProviders(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $event = new RegisterQueuedExportProvidersEvent();
        $this->trigger(self::EVENT_REGISTER_QUEUED_EXPORT_PROVIDERS, $event);

        $this->providers = $event->providers;

        return $this->providers;
    }

    /**
     * Get available providers.
     *
     * @return array<string, array{handle: string, name: string, class: class-string, available: bool}>
     */
    public function getAvailableProviders(): array
    {
        $available = [];

        foreach ($this->getRegisteredProviders() as $handle => $provider) {
            $class = $provider['class'];

            if (!class_exists($class) || !is_subclass_of($class, QueuedExportProviderInterface::class)) {
                continue;
            }

            /** @var QueuedExportProviderInterface $class */
            if ($class::isAvailable()) {
                $available[$handle] = array_merge($provider, ['available' => true]);
            }
        }

        return $available;
    }

    /**
     * Get a provider instance by handle.
     *
     * @param string $handle Provider handle
     * @return QueuedExportProviderInterface|null
     */
    public function getProvider(string $handle): ?QueuedExportProviderInterface
    {
        if (isset($this->instances[$handle])) {
            return $this->instances[$handle];
        }

        $providers = $this->getRegisteredProviders();

        if (!isset($providers[$handle])) {
            $this->logWarning('Queued export provider not found', ['handle' => $handle]);
            return null;
        }

        $class = $providers[$handle]['class'];

        if (!class_exists($class)) {
            $this->logWarning('Queued export provider class not found', [
                'handle' => $handle,
                'class' => $class,
            ]);
            return null;
        }

        $instance = new $class();

        if (!$instance instanceof QueuedExportProviderInterface) {
            $this->logWarning('Queued export provider class does not implement QueuedExportProviderInterface', [
                'handle' => $handle,
                'class' => $class,
            ]);
            return null;
        }

        if (!$instance::isAvailable()) {
            $this->logWarning('Queued export provider is not available', ['handle' => $handle]);
            return null;
        }

        $this->instances[$handle] = $instance;

        return $instance;
    }

    /**
     * Check if a provider is available.
     *
     * @param string $handle Provider handle
     * @return bool
     */
    public function isProviderAvailable(string $handle): bool
    {
        return $this->getProvider($handle) !== null;
    }
}

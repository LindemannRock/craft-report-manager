<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\presenters;

use craft\base\LocalFsInterface;
use craft\helpers\App;
use lindemannrock\reportmanager\models\Settings;
use lindemannrock\reportmanager\storage\ExportStorage;

/**
 * Classifies effective export storage for the Craft Cloud settings warning.
 *
 * @since 5.6.0
 */
final class StorageWarningPresentation
{
    public const STATE_DURABLE_HOST = 'durable-host';
    public const STATE_LOCAL = 'local';
    public const STATE_NON_LOCAL = 'non-local';
    public const STATE_UNAVAILABLE = 'unavailable';

    private function __construct(public readonly string $state)
    {
    }

    public static function forSettings(Settings $settings): self
    {
        $storage = ExportStorage::forSettings($settings);
        if ($storage->isUnavailable()) {
            return new self(self::STATE_UNAVAILABLE);
        }

        if (!App::isEphemeral()) {
            return new self(self::STATE_DURABLE_HOST);
        }

        return new self(
            !$storage->isVolume() || $storage->backingFilesystem instanceof LocalFsInterface
                ? self::STATE_LOCAL
                : self::STATE_NON_LOCAL,
        );
    }

    public function shouldShowWarning(): bool
    {
        return $this->state === self::STATE_LOCAL;
    }

    public function isUnavailable(): bool
    {
        return $this->state === self::STATE_UNAVAILABLE;
    }
}

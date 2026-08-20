<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\storage;

use Craft;
use craft\base\BaseFsInterface;
use craft\base\FsInterface;
use craft\base\MissingComponentInterface;
use craft\models\Volume;
use lindemannrock\base\helpers\StorageVolumeHelper;
use lindemannrock\reportmanager\exceptions\ExportStorageUnavailableException;
use lindemannrock\reportmanager\models\Settings;
use lindemannrock\reportmanager\records\ExportRecord;
use Throwable;

/**
 * Resolves the effective export storage without probing provider credentials.
 *
 * @since 5.6.0
 */
final class ExportStorage
{
    public const TYPE_LOCAL = 'local';
    public const TYPE_VOLUME = 'volume';
    public const TYPE_UNAVAILABLE = 'unavailable';
    public const TYPE_UNRESOLVED = 'unresolved';
    public const EXPORT_SUBPATH = 'report-manager/exports';

    private function __construct(
        public readonly string $type,
        public readonly ?string $volumeUid = null,
        public readonly ?string $localPath = null,
        public readonly ?Volume $volume = null,
        public readonly ?FsInterface $backingFilesystem = null,
        public readonly ?Throwable $cause = null,
    ) {
    }

    public static function forSettings(Settings $settings): self
    {
        $volumeUid = trim((string)$settings->exportVolumeUid);
        if ($volumeUid === '') {
            $localPath = rtrim($settings->getExportPath(), '/') . '/';

            return new self(self::TYPE_LOCAL, localPath: $localPath);
        }

        return self::forVolumeUid($volumeUid);
    }

    public static function forRecord(ExportRecord $export): self
    {
        if ($export->storageType === self::TYPE_LOCAL) {
            return new self(
                self::TYPE_LOCAL,
                localPath: rtrim(dirname($export->filePath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            );
        }

        if ($export->storageType === self::TYPE_VOLUME && trim((string)$export->storageVolumeUid) !== '') {
            return self::forVolumeUid(trim((string)$export->storageVolumeUid));
        }

        return new self(self::TYPE_UNRESOLVED);
    }

    private static function forVolumeUid(string $volumeUid): self
    {
        try {
            $validationErrors = StorageVolumeHelper::validateVolume($volumeUid);
            if ($validationErrors !== []) {
                return new self(self::TYPE_UNAVAILABLE, volumeUid: $volumeUid);
            }

            $volume = Craft::$app->getVolumes()->getVolumeByUid($volumeUid);
            if (!$volume instanceof Volume || $volume instanceof MissingComponentInterface) {
                return new self(self::TYPE_UNAVAILABLE, volumeUid: $volumeUid);
            }

            $filesystem = $volume->getFs();
            if ($filesystem instanceof MissingComponentInterface) {
                return new self(self::TYPE_UNAVAILABLE, volumeUid: $volumeUid);
            }

            return new self(
                self::TYPE_VOLUME,
                volumeUid: $volumeUid,
                volume: $volume,
                backingFilesystem: $filesystem,
            );
        } catch (Throwable $exception) {
            return new self(
                self::TYPE_UNAVAILABLE,
                volumeUid: $volumeUid,
                cause: $exception,
            );
        }
    }

    public function isVolume(): bool
    {
        return $this->type === self::TYPE_VOLUME;
    }

    public function isUnavailable(): bool
    {
        return $this->type === self::TYPE_UNAVAILABLE;
    }

    public function isUnresolved(): bool
    {
        return $this->type === self::TYPE_UNRESOLVED;
    }

    public function filesystem(): BaseFsInterface
    {
        if ($this->volume instanceof Volume) {
            return $this->volume;
        }

        throw $this->unavailableException();
    }

    public function unavailableException(?Throwable $cause = null): ExportStorageUnavailableException
    {
        return new ExportStorageUnavailableException(
            $this->isUnresolved() ? self::unresolvedMessage() : self::unavailableMessage(),
            previous: $cause ?? $this->cause,
        );
    }

    public static function unavailableMessage(): string
    {
        return Craft::t(
            'report-manager',
            'The configured export volume is unavailable. Check its volume and filesystem configuration, then try again.',
        );
    }

    public static function unresolvedMessage(): string
    {
        return Craft::t(
            'report-manager',
            'This export was created before its storage location was recorded. Verify and assign its exact storage volume before downloading or deleting it.',
        );
    }
}

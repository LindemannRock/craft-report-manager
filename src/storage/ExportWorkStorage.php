<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\storage;

use Craft;
use craft\helpers\FileHelper;
use lindemannrock\reportmanager\records\ExportRecord;

/**
 * Authenticated, independently replaceable work objects on captured storage.
 *
 * @internal
 * @since 5.7.0
 */
final class ExportWorkStorage
{
    private ExportStorage $storage;
    private string $prefix;
    private string $stagingDirectory;

    public function __construct(ExportRecord $export)
    {
        $this->storage = ExportStorage::forRecord($export);
        if ($this->storage->isUnavailable() || $this->storage->isUnresolved()) {
            throw $this->storage->unavailableException();
        }
        if ($export->filePath === '' || !preg_match('/^[a-f0-9-]{36}$/i', $export->uid)) {
            throw new \RuntimeException('Export work storage requires a captured object identity.');
        }
        $this->prefix = dirname($export->filePath) . '/work-' . $export->uid;
        $this->stagingDirectory = Craft::$app->getPath()->getTempPath() . '/report-manager-work-' . $export->uid;
    }

    public function write(string $name, array $value): void
    {
        $path = $this->path($name);
        // Identity in the authenticated plaintext also prevents swapping parts.
        $bytes = Craft::$app->getSecurity()->encryptByKey(json_encode(
            ['path' => $path, 'value' => $value],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
        if ($this->storage->isVolume()) {
            $fs = $this->storage->filesystem();
            if (!$fs->directoryExists($this->prefix)) {
                $fs->createDirectory($this->prefix);
            }
            $fs->write($path, $bytes);
        } else {
            FileHelper::createDirectory($this->prefix, 0700);
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('Unable to commit export work object.');
            }
        }
        // Do not checkpoint a provider write that cannot be read back intact.
        if ($this->read($name) !== $value) {
            throw new \RuntimeException('Export work object verification failed.');
        }
    }

    public function read(string $name): array
    {
        $path = $this->path($name);
        $bytes = $this->storage->isVolume()
            ? $this->storage->filesystem()->read($path)
            : file_get_contents($path);
        if (!is_string($bytes)) {
            throw new \RuntimeException('Export work object is unavailable.');
        }
        $plaintext = Craft::$app->getSecurity()->decryptByKey($bytes);
        if ($plaintext === false) {
            throw new \RuntimeException('Export work object authentication failed.');
        }
        $envelope = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        if (($envelope['path'] ?? null) !== $path || !is_array($envelope['value'] ?? null)) {
            throw new \RuntimeException('Export work object identity mismatch.');
        }

        return $envelope['value'];
    }

    /** Remove only the directory belonging to this captured export UID. */
    public function cleanup(): void
    {
        $this->clearStaging();
        if ($this->storage->isVolume()) {
            $fs = $this->storage->filesystem();
            if ($fs->directoryExists($this->prefix)) {
                $fs->deleteDirectory($this->prefix);
            }
        } elseif (is_dir($this->prefix)) {
            FileHelper::removeDirectory($this->prefix);
        }
    }

    /** Worker-local staging is disposable; no checkpoint ever refers to it. */
    public function prepareStaging(): string
    {
        $this->clearStaging();
        FileHelper::createDirectory($this->stagingDirectory, 0700);

        return $this->stagingDirectory;
    }

    public function clearStaging(): void
    {
        if (is_dir($this->stagingDirectory)) {
            FileHelper::removeDirectory($this->stagingDirectory);
        }
    }

    private function path(string $name): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid export work object name.');
        }

        return $this->prefix . '/' . $name;
    }
}

<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\tests\Support;

use yii\queue\Queue;

/**
 * Serializes each admission so the next execution owns a fresh job instance.
 *
 * @since 5.7.0
 */
class ExportStepQueue extends Queue
{
    public array $messages = [];
    public bool $reject = false;
    public ?string $spoolPath = null;

    /** Exercise Yii's real deserialization, aborted-attempt preflight and error handler. */
    public function runReserved(string $message, int $attempt): bool
    {
        return $this->handleMessage('owned-export-step', $message, 1800, $attempt);
    }

    public function status($id): int
    {
        return self::STATUS_WAITING;
    }

    protected function pushMessage($message, $ttr, $delay, $priority): ?string
    {
        if ($this->reject) {
            return null;
        }
        $this->messages[] = $message;
        if ($this->spoolPath !== null) {
            if (file_put_contents($this->spoolPath, json_encode($this->messages, JSON_THROW_ON_ERROR)) === false) {
                throw new \RuntimeException('Unable to persist test-owned queue admission.');
            }
        }

        return (string)count($this->messages);
    }
}

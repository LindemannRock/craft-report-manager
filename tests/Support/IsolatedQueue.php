<?php
/**
 * LindemannRock Report Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\tests\Support;

use craft\queue\Queue;
use Throwable;

/**
 * Hides the permanent Craft queue before enabled plugins bootstrap.
 *
 * @since 5.6.0
 */
final class IsolatedQueue extends Queue
{
    private ?string $rawShadowTable = null;

    public function init(): void
    {
        parent::init();

        if ($this->db->getDriverName() !== 'mysql') {
            throw new \RuntimeException('The disposable Report Manager suite currently requires MySQL.');
        }

        $this->rawShadowTable = $this->db->getSchema()->getRawTableName($this->tableName);
        $stagingTable = $this->rawShadowTable . '_rm_bootstrap_' . bin2hex(random_bytes(8));
        $this->db->createCommand(sprintf(
            'CREATE TEMPORARY TABLE %s LIKE %s',
            $this->db->quoteTableName($stagingTable),
            $this->db->quoteTableName($this->rawShadowTable),
        ))->execute();
        $this->db->createCommand(sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $this->db->quoteTableName($stagingTable),
            $this->db->quoteTableName($this->rawShadowTable),
        ))->execute();

        register_shutdown_function(function(): void {
            if ($this->rawShadowTable === null) {
                return;
            }

            try {
                $this->db->createCommand(
                    'DROP TEMPORARY TABLE IF EXISTS ' . $this->db->quoteTableName($this->rawShadowTable),
                )->execute();
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Report Manager queue-shadow cleanup failed: ' . $exception->getMessage() . PHP_EOL);
            }
        });
    }

    /** Remove only rows in the connection-local shadow queue. */
    public function clearShadowRows(): void
    {
        $this->db->createCommand()->delete($this->tableName)->execute();
    }
}

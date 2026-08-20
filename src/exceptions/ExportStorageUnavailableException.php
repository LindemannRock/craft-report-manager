<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\reportmanager\exceptions;

use RuntimeException;

/**
 * Raised when Report Manager cannot use its authoritative export storage.
 *
 * @since 5.6.0
 */
final class ExportStorageUnavailableException extends RuntimeException
{
}

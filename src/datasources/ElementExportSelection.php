<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\datasources;

use craft\elements\db\ElementQuery;

/**
 * Shared identity handling for the built-in element-backed sources.
 *
 * @internal
 * @since 5.6.1
 */
final class ElementExportSelection
{
    public static function identities(ElementQuery $query): iterable
    {
        $selection = $query->limit(null)->offset(null)->prepareSubquery();
        $selection->select(['id' => 'elements.id', 'siteId' => 'elements_sites.siteId']);

        foreach ($selection->each(100) as $row) {
            yield ['id' => (int)$row['id'], 'siteId' => (int)$row['siteId']];
        }
    }

    public static function restrict(ElementQuery $query, array $options): void
    {
        if (isset($options['_exportIdentity'])) {
            $identity = $options['_exportIdentity'];
            $query->andWhere([
                'elements.id' => (int)$identity['id'],
                'elements_sites.siteId' => (int)$identity['siteId'],
            ]);
        }
    }
}

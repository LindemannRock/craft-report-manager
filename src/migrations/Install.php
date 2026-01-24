<?php
/**
 * Report Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\reportmanager\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\StringHelper;

/**
 * Install Migration
 *
 * @author    LindemannRock
 * @package   ReportManager
 * @since     5.0.0
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createSettingsTable();
        $this->createReportsTable();
        $this->createExportsTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Drop tables in reverse order (respecting foreign keys)
        $this->dropTableIfExists('{{%reportmanager_exports}}');
        $this->dropTableIfExists('{{%reportmanager_reports}}');
        $this->dropTableIfExists('{{%reportmanager_settings}}');

        return true;
    }

    /**
     * Create settings table
     */
    private function createSettingsTable(): void
    {
        if ($this->db->tableExists('{{%reportmanager_settings}}')) {
            return;
        }

        $this->createTable('{{%reportmanager_settings}}', [
            'id' => $this->primaryKey(),
            // Plugin settings
            'pluginName' => $this->string(255)->notNull()->defaultValue('Report Manager'),
            // Report generation settings
            'enableScheduledReports' => $this->boolean()->notNull()->defaultValue(true),
            'defaultSchedule' => $this->string(32)->notNull()->defaultValue('daily2am'),
            'maxExportBatchSize' => $this->integer()->notNull()->defaultValue(10000),
            'exportRetention' => $this->integer()->notNull()->defaultValue(30),
            'autoCleanupExports' => $this->boolean()->notNull()->defaultValue(true),
            // Export settings
            'exportVolumeUid' => $this->string(36)->null(),
            'exportPath' => $this->string(255)->notNull()->defaultValue('@storage/report-manager/exports'),
            'defaultExportFormat' => $this->string(16)->notNull()->defaultValue('csv'),
            'csvDelimiter' => $this->string(1)->notNull()->defaultValue(','),
            'csvEnclosure' => $this->string(1)->notNull()->defaultValue('"'),
            'csvIncludeBom' => $this->boolean()->notNull()->defaultValue(true),
            // Analytics settings
            'enableAnalytics' => $this->boolean()->notNull()->defaultValue(true),
            'defaultDateRange' => $this->string(32)->notNull()->defaultValue('last30days'),
            'dashboardRefreshInterval' => $this->integer()->notNull()->defaultValue(0),
            // Interface settings
            'itemsPerPage' => $this->integer()->notNull()->defaultValue(50),
            // Logging library
            'logLevel' => $this->string(20)->notNull()->defaultValue('error'),
            // Standard columns
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Insert default settings row (always id=1)
        $this->insert('{{%reportmanager_settings}}', [
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ]);
    }

    /**
     * Create reports table (saved report configurations)
     */
    private function createReportsTable(): void
    {
        if ($this->db->tableExists('{{%reportmanager_reports}}')) {
            return;
        }

        $this->createTable('{{%reportmanager_reports}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(64)->notNull(),
            'description' => $this->text()->null(),
            // Data source configuration
            'dataSource' => $this->string(64)->notNull(), // e.g., 'formie'
            'entityIds' => $this->text()->null()->comment('JSON array of form IDs'),
            'siteId' => $this->integer()->null(), // null = all sites
            // Report configuration
            'dateRange' => $this->string(32)->notNull()->defaultValue('last30days'),
            'customDateStart' => $this->dateTime()->null(),
            'customDateEnd' => $this->dateTime()->null(),
            'fieldHandles' => $this->text()->null()->comment('JSON array of field handles to include'),
            // Export settings
            'exportFormat' => $this->string(16)->notNull()->defaultValue('csv'),
            'exportMode' => $this->string(16)->notNull()->defaultValue('separate'), // separate or combined
            // Schedule settings
            'enableSchedule' => $this->boolean()->notNull()->defaultValue(false),
            'schedule' => $this->string(32)->null(), // e.g., 'daily2am', 'weekly'
            'lastGeneratedAt' => $this->dateTime()->null(),
            'nextScheduledAt' => $this->dateTime()->null(),
            // Status
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            // Standard columns
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes
        $this->createIndex(null, '{{%reportmanager_reports}}', ['handle'], true);
        $this->createIndex(null, '{{%reportmanager_reports}}', ['dataSource'], false);
        $this->createIndex(null, '{{%reportmanager_reports}}', ['siteId'], false);
        $this->createIndex(null, '{{%reportmanager_reports}}', ['enabled'], false);
        $this->createIndex(null, '{{%reportmanager_reports}}', ['enableSchedule'], false);
        $this->createIndex(null, '{{%reportmanager_reports}}', ['nextScheduledAt'], false);
        $this->createIndex(null, '{{%reportmanager_reports}}', ['sortOrder'], false);
    }

    /**
     * Create exports table (generated export files)
     */
    private function createExportsTable(): void
    {
        if ($this->db->tableExists('{{%reportmanager_exports}}')) {
            return;
        }

        $this->createTable('{{%reportmanager_exports}}', [
            'id' => $this->primaryKey(),
            'reportId' => $this->integer()->null(), // Null for ad-hoc exports
            // Export details
            'dataSource' => $this->string(64)->notNull(),
            'entityId' => $this->integer()->notNull(),
            'entityName' => $this->string(255)->null(),
            // Configuration used
            'dateRangeUsed' => $this->string(32)->null(),
            'dateStartUsed' => $this->dateTime()->null(),
            'dateEndUsed' => $this->dateTime()->null(),
            'fieldHandlesUsed' => $this->text()->null()->comment('JSON array'),
            'siteIdsUsed' => $this->text()->null()->comment('JSON array of site IDs'),
            // File details
            'format' => $this->string(16)->notNull(),
            'filename' => $this->string(255)->notNull(),
            'filePath' => $this->text()->notNull(),
            'fileSize' => $this->bigInteger()->notNull()->defaultValue(0),
            'recordCount' => $this->integer()->notNull()->defaultValue(0),
            // Status
            'status' => $this->string(32)->notNull()->defaultValue('pending'), // pending, processing, completed, failed
            'progress' => $this->integer()->notNull()->defaultValue(0), // 0-100
            'errorMessage' => $this->text()->null(),
            // Trigger info
            'triggeredBy' => $this->string(32)->notNull()->defaultValue('manual'), // manual, scheduled, api
            'triggeredByUserId' => $this->integer()->null(),
            // Timing
            'startedAt' => $this->dateTime()->null(),
            'completedAt' => $this->dateTime()->null(),
            // Standard columns
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes
        $this->createIndex(null, '{{%reportmanager_exports}}', ['reportId'], false);
        $this->createIndex(null, '{{%reportmanager_exports}}', ['dataSource'], false);
        $this->createIndex(null, '{{%reportmanager_exports}}', ['entityId'], false);
        $this->createIndex(null, '{{%reportmanager_exports}}', ['status'], false);
        $this->createIndex(null, '{{%reportmanager_exports}}', ['triggeredBy'], false);
        $this->createIndex(null, '{{%reportmanager_exports}}', ['dateCreated'], false);

        // Foreign key to reports (nullable for ad-hoc exports)
        $this->addForeignKey(
            null,
            '{{%reportmanager_exports}}',
            ['reportId'],
            '{{%reportmanager_reports}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // Foreign key to users (nullable)
        $this->addForeignKey(
            null,
            '{{%reportmanager_exports}}',
            ['triggeredByUserId'],
            '{{%users}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );
    }
}

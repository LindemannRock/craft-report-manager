<?php
/**
 * Report Manager config.php
 *
 * This file exists only as a template for the Report Manager settings.
 * It does nothing on its own.
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'report-manager.php'
 * and make your changes there to override default settings.
 *
 * Once copied to 'craft/config', this file will be multi-environment aware as
 * well, so you can have different settings groups for each environment, just as
 * you do for 'general.php'
 *
 * @since 5.1.5
 */

return [
    // Global settings
    '*' => [
        // ========================================
        // GENERAL SETTINGS
        // ========================================

        /**
         * Plugin display name
         * Customize how the plugin appears in the Control Panel
         */
        'pluginName' => 'Report Manager',

        /**
         * Log level for debugging
         * Options: 'error', 'warning', 'info', 'debug'
         * Note: 'debug' requires devMode to be enabled
         */
        'logLevel' => 'error',


        // ========================================
        // EXPORT SETTINGS
        // ========================================

        /**
         * Default format selected for new exports
         * Options: 'csv', 'xlsx', 'json'
         * Default: 'csv'
         */
        // 'defaultExportFormat' => 'csv',

        /**
         * Maximum records to export in a single batch
         * Range: 100-100000
         * Default: 10000
         */
        // 'maxExportBatchSize' => 10000,

        /**
         * Asset volume UID for export storage
         * Leave null to use exportPath instead
         */
        // 'exportVolumeUid' => null,

        /**
         * Export storage path
         * Only used when exportVolumeUid is null
         * Default: @storage/report-manager/exports
         * Can use environment variables: App::env('REPORT_EXPORT_PATH')
         */
        // 'exportPath' => '@storage/report-manager/exports',

        /**
         * CSV delimiter character
         * Default: ','
         */
        // 'csvDelimiter' => ',',

        /**
         * CSV enclosure character
         * Default: '"'
         */
        // 'csvEnclosure' => '"',

        /**
         * Include UTF-8 BOM in CSV exports for Excel compatibility
         * Default: true
         */
        // 'csvIncludeBom' => true,

        /**
         * Export file retention in days
         * Set to 0 to keep files indefinitely
         * Default: 30
         */
        // 'exportRetention' => 30,

        /**
         * Automatically delete old exports based on exportRetention
         * Default: true
         */
        // 'autoCleanupExports' => true,


        // ========================================
        // SCHEDULING SETTINGS
        // ========================================

        /**
         * Global scheduled report switch
         * When disabled, report-level scheduling controls are hidden and scheduled jobs exit.
         */
        // 'enableScheduledReports' => true,

        /**
         * Default frequency selected when creating a new report
         * Options: 'every6hours', 'every12hours', 'daily', 'daily2am', 'weekly',
         *          'monthly', 'every2months', 'quarterly', 'every6months', 'yearly'
         */
        // 'defaultSchedule' => 'daily2am',

        /**
         * Minimum interval between scheduled report checks (in minutes)
         * Default: 60 (1 hour)
         */
        // 'schedulingInterval' => 60,


        // ========================================
        // INTERFACE SETTINGS
        // ========================================

        /**
         * Number of items to display per page in lists
         * Range: 10-500
         * Default: 100
         */
        // 'itemsPerPage' => 100,


        // ========================================
        // BASE PLUGIN OVERRIDES
        // ========================================
        // These settings override lindemannrock-base defaults for this plugin only.
        // Global defaults: vendor/lindemannrock/craft-plugin-base/src/config.php
        // To customize globally: copy to config/lindemannrock-base.php

        /**
         * Date/time formatting overrides
         * Override base plugin date/time display settings for this plugin
         * Defaults: from config/lindemannrock-base.php
         */
        // 'timeFormat' => '24',      // '12' (AM/PM) or '24' (military)
        // 'monthFormat' => 'short',  // 'numeric' (01), 'short' (Jan), 'long' (January)
        // 'dateOrder' => 'dmy',      // 'dmy', 'mdy', 'ymd'
        // 'dateSeparator' => '/',    // '/', '-', '.'
        // 'showSeconds' => false,    // Show seconds in time display

        /**
         * Default date range for analytics and dashboard pages
         * Options: 'today', 'yesterday', 'last7days', 'last30days', 'last90days',
         *          'thisMonth', 'lastMonth', 'thisYear', 'lastYear', 'all'
         * Default: 'last30days' (from base plugin)
         */
        // 'defaultDateRange' => 'last7days',

        /**
         * Export format overrides
         * Enable/disable specific export formats for this plugin
         * Default: all enabled (from base plugin)
         */
        // 'exports' => [
        //     'csv' => true,
        //     'json' => true,
        //     'excel' => true,
        // ],
    ],

    // Dev environment settings
    'dev' => [
        'logLevel' => 'debug',
        // 'exportRetentionDays' => 7,  // Keep fewer files in dev
    ],

    // Staging environment settings
    'staging' => [
        'logLevel' => 'info',
    ],

    // Production environment settings
    'production' => [
        'logLevel' => 'error',
    ],
];

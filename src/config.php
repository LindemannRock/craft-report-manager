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
 * @since 5.0.0
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
         * Export file retention in days
         * Set to 0 to keep files indefinitely
         * Default: 30 days
         */
        // 'exportRetentionDays' => 30,

        /**
         * Export storage path
         * Leave null to use default (@storage/report-manager/exports)
         * Can use environment variables: App::env('REPORT_EXPORT_PATH')
         */
        // 'exportPath' => null,


        // ========================================
        // SCHEDULING SETTINGS
        // ========================================

        /**
         * Minimum interval between scheduled report checks (in minutes)
         * Default: 60 (1 hour)
         */
        // 'schedulingInterval' => 60,


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
         * Default date range for analytics, logs, and dashboard pages
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

<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Plugin Activator
 *
 * Handles plugin activation, including database table creation.
 */


class Activator {

    /**
     * Activate the plugin
     */
    public static function activate(): void {
        self::create_tables();
        self::run_migrations();
        self::set_default_options();
        self::schedule_cron_events();

        // Store version for future updates
        update_option('isf_version', ISF_VERSION);

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Run database migrations for version upgrades
     */
    private static function run_migrations(): void {
        global $wpdb;

        $current_version = get_option('isf_version', '1.0.0');

        // Migration for v2.1.0: Add embed_token column
        if (version_compare($current_version, '2.1.0', '<')) {
            $table = $wpdb->prefix . ISF_TABLE_INSTANCES;

            // Check if column exists
            $column_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s
                     AND TABLE_NAME = %s
                     AND COLUMN_NAME = 'embed_token'",
                    DB_NAME,
                    $table
                )
            );

            if (!$column_exists) {
                // Table name is whitelisted and validated via ISF_TABLE_INSTANCES constant
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN embed_token VARCHAR(64) NULL AFTER test_mode");
                // Column names are hardcoded, safe
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE INDEX embed_token (embed_token)");
            }
        }

        // Migration for v2.2.0: Add display_order column for drag-and-drop sorting
        if (version_compare($current_version, '2.2.0', '<')) {
            $table = $wpdb->prefix . ISF_TABLE_INSTANCES;

            // Check if column exists
            $column_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s
                     AND TABLE_NAME = %s
                     AND COLUMN_NAME = 'display_order'",
                    DB_NAME,
                    $table
                )
            );

            if (!$column_exists) {
                // Table name is whitelisted and validated via ISF_TABLE_INSTANCES constant
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN display_order INT UNSIGNED DEFAULT 0 AFTER embed_token");
                // Column names are hardcoded, safe
                $wpdb->query("ALTER TABLE {$table} ADD INDEX display_order (display_order)");

                // Set initial order based on id - column names are hardcoded, safe
                $wpdb->query("UPDATE {$table} SET display_order = id");
            }
        }

        // Migration for v2.4.0: Add 'external' to form_type ENUM
        if (version_compare($current_version, '2.4.0', '<')) {
            $table = $wpdb->prefix . ISF_TABLE_INSTANCES;

            // Check current ENUM values
            $column_info = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COLUMN_TYPE
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s
                     AND TABLE_NAME = %s
                     AND COLUMN_NAME = 'form_type'",
                    DB_NAME,
                    $table
                )
            );

            // Add 'external' to ENUM if not already present
            // Table name is whitelisted, ENUM values are hardcoded literals, safe
            if ($column_info && strpos($column_info->COLUMN_TYPE, 'external') === false) {
                $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN form_type ENUM('enrollment','scheduler','external') DEFAULT 'enrollment'");
            }
        }
    }

    /**
     * Create database tables
     */
    private static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Instances table
        $table_instances = $wpdb->prefix . ISF_TABLE_INSTANCES;
        $sql_instances = "CREATE TABLE {$table_instances} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            utility VARCHAR(50) NOT NULL,
            form_type ENUM('enrollment','scheduler','external') DEFAULT 'enrollment',
            api_endpoint VARCHAR(500) NOT NULL,
            api_password VARCHAR(500),
            support_email_from VARCHAR(255),
            support_email_to TEXT,
            settings JSON,
            is_active TINYINT(1) DEFAULT 1,
            test_mode TINYINT(1) DEFAULT 0,
            embed_token VARCHAR(64) NULL,
            display_order INT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY slug (slug),
            UNIQUE KEY embed_token (embed_token),
            KEY utility (utility),
            KEY form_type_active (form_type, is_active),
            KEY display_order (display_order)
        ) {$charset_collate};";

        // Submissions table
        $table_submissions = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;
        $sql_submissions = "CREATE TABLE {$table_submissions} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id INT UNSIGNED NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            account_number VARCHAR(50),
            customer_name VARCHAR(255),
            device_type ENUM('thermostat','dcu'),
            form_data MEDIUMBLOB,
            api_response MEDIUMBLOB,
            status ENUM('in_progress','completed','failed','abandoned') DEFAULT 'in_progress',
            step TINYINT UNSIGNED DEFAULT 1,
            ip_address VARCHAR(45),
            user_agent VARCHAR(500),
            is_test TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            KEY session_id (session_id),
            KEY status_date (status, created_at),
            KEY instance_status (instance_id, status),
            KEY is_test (is_test),
            CONSTRAINT fk_submission_instance FOREIGN KEY (instance_id)
                REFERENCES {$table_instances}(id) ON DELETE CASCADE
        ) {$charset_collate};";

        // Logs table
        $table_logs = $wpdb->prefix . ISF_TABLE_LOGS;
        $sql_logs = "CREATE TABLE {$table_logs} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id INT UNSIGNED,
            submission_id INT UNSIGNED,
            log_type ENUM('info','warning','error','api_call','security') NOT NULL,
            message TEXT NOT NULL,
            details JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY type_date (log_type, created_at),
            KEY instance_id (instance_id),
            KEY submission_id (submission_id)
        ) {$charset_collate};";

        // Step analytics table - tracks user progression through form steps
        $table_analytics = $wpdb->prefix . ISF_TABLE_ANALYTICS;
        $sql_analytics = "CREATE TABLE {$table_analytics} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id INT UNSIGNED NOT NULL,
            submission_id INT UNSIGNED,
            session_id VARCHAR(64) NOT NULL,
            step INT UNSIGNED NOT NULL,
            step_name VARCHAR(100),
            action ENUM('enter','exit','complete','abandon') NOT NULL,
            time_on_step INT UNSIGNED DEFAULT 0,
            device_type VARCHAR(50),
            browser VARCHAR(100),
            is_mobile TINYINT(1) DEFAULT 0,
            is_test TINYINT(1) DEFAULT 0,
            referrer VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY instance_step (instance_id, step),
            KEY session_step (session_id, step),
            KEY action_date (action, created_at),
            KEY instance_date (instance_id, created_at),
            KEY is_test (is_test),
            CONSTRAINT fk_analytics_instance FOREIGN KEY (instance_id)
                REFERENCES {$table_instances}(id) ON DELETE CASCADE
        ) {$charset_collate};";

        // Failed submissions retry queue
        $table_retry_queue = $wpdb->prefix . 'isf_retry_queue';
        $sql_retry_queue = "CREATE TABLE {$table_retry_queue} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            submission_id INT UNSIGNED NOT NULL,
            instance_id INT UNSIGNED NOT NULL,
            retry_count TINYINT UNSIGNED DEFAULT 0,
            max_retries TINYINT UNSIGNED DEFAULT 3,
            last_error TEXT,
            next_retry_at TIMESTAMP NULL,
            status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY status_retry (status, next_retry_at),
            KEY submission_id (submission_id),
            KEY instance_id (instance_id)
        ) {$charset_collate};";

        // Webhook notifications table
        $table_webhooks = $wpdb->prefix . 'isf_webhooks';
        $sql_webhooks = "CREATE TABLE {$table_webhooks} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id INT UNSIGNED,
            name VARCHAR(255) NOT NULL,
            url VARCHAR(500) NOT NULL,
            events JSON NOT NULL,
            secret VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            last_triggered_at TIMESTAMP NULL,
            success_count INT UNSIGNED DEFAULT 0,
            failure_count TINYINT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY instance_active (instance_id, is_active)
        ) {$charset_collate};";

        // API usage tracking table for rate limit monitoring
        $table_api_usage = $wpdb->prefix . 'isf_api_usage';
        $sql_api_usage = "CREATE TABLE {$table_api_usage} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id INT UNSIGNED NOT NULL,
            endpoint VARCHAR(100) NOT NULL,
            method VARCHAR(50) NOT NULL,
            status_code SMALLINT UNSIGNED,
            response_time_ms INT UNSIGNED,
            success TINYINT(1) DEFAULT 1,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY instance_endpoint (instance_id, endpoint),
            KEY created_at (created_at),
            KEY instance_date (instance_id, created_at),
            KEY endpoint_date (endpoint, created_at)
        ) {$charset_collate};";

        // Resume tokens table for "save and continue later" feature
        $table_resume_tokens = $wpdb->prefix . 'isf_resume_tokens';
        $sql_resume_tokens = "CREATE TABLE {$table_resume_tokens} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL,
            instance_id INT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL,
            email VARCHAR(255) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY token (token),
            KEY session_instance (session_id, instance_id),
            KEY expires_at (expires_at),
            KEY instance_id (instance_id)
        ) {$charset_collate};";

        // Scheduled reports table
        $table_scheduled_reports = $wpdb->prefix . 'isf_scheduled_reports';
        $sql_scheduled_reports = "CREATE TABLE {$table_scheduled_reports} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
            recipients TEXT NOT NULL,
            instance_id INT UNSIGNED NULL,
            settings JSON,
            is_active TINYINT(1) DEFAULT 1,
            last_sent_at TIMESTAMP NULL,
            next_send_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY frequency_active (frequency, is_active),
            KEY next_send (next_send_at, is_active),
            KEY instance_id (instance_id)
        ) {$charset_collate};";

        // Audit log table for admin actions
        $table_audit_log = $wpdb->prefix . 'isf_audit_log';
        $sql_audit_log = "CREATE TABLE {$table_audit_log} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            user_login VARCHAR(60) NOT NULL,
            user_email VARCHAR(100),
            action VARCHAR(100) NOT NULL,
            object_type VARCHAR(50) NOT NULL,
            object_id INT UNSIGNED,
            object_name VARCHAR(255),
            details JSON,
            ip_address VARCHAR(45),
            user_agent VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY user_action (user_id, action),
            KEY action_date (action, created_at),
            KEY object_type_id (object_type, object_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // GDPR data requests table
        $table_gdpr_requests = $wpdb->prefix . 'isf_gdpr_requests';
        $sql_gdpr_requests = "CREATE TABLE {$table_gdpr_requests} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_type ENUM('export','erasure') NOT NULL,
            email VARCHAR(255) NOT NULL,
            account_number VARCHAR(50),
            status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
            requested_by BIGINT UNSIGNED,
            processed_by BIGINT UNSIGNED,
            request_data JSON,
            result_data JSON,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL,
            KEY email_type (email, request_type),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Visitors table - Anonymous visitor tracking for attribution
        $table_visitors = $wpdb->prefix . ISF_TABLE_VISITORS;
        $sql_visitors = "CREATE TABLE {$table_visitors} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visitor_id VARCHAR(64) NOT NULL,
            fingerprint_hash VARCHAR(64),
            first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            visit_count INT UNSIGNED DEFAULT 1,
            first_touch JSON,
            device_info JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY visitor_id (visitor_id),
            KEY fingerprint_hash (fingerprint_hash),
            KEY first_seen_at (first_seen_at)
        ) {$charset_collate};";

        // Touches table - All marketing touchpoints for attribution
        $table_touches = $wpdb->prefix . ISF_TABLE_TOUCHES;
        $sql_touches = "CREATE TABLE {$table_touches} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visitor_id VARCHAR(64) NOT NULL,
            instance_id INT UNSIGNED,
            touch_type ENUM('page_view','form_view','form_start','form_complete','handoff','return_visit') NOT NULL,
            utm_source VARCHAR(100),
            utm_medium VARCHAR(100),
            utm_campaign VARCHAR(255),
            utm_term VARCHAR(255),
            utm_content VARCHAR(255),
            gclid VARCHAR(100),
            fbclid VARCHAR(100),
            msclkid VARCHAR(100),
            referrer VARCHAR(500),
            referrer_domain VARCHAR(255),
            landing_page VARCHAR(500),
            page_url VARCHAR(500),
            promo_code VARCHAR(50),
            touch_data JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY visitor_id (visitor_id),
            KEY instance_id (instance_id),
            KEY touch_type (touch_type),
            KEY utm_source (utm_source),
            KEY utm_campaign (utm_campaign),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Handoffs table - External enrollment redirect tracking
        $table_handoffs = $wpdb->prefix . ISF_TABLE_HANDOFFS;
        $sql_handoffs = "CREATE TABLE {$table_handoffs} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id INT UNSIGNED NOT NULL,
            visitor_id VARCHAR(64),
            handoff_token VARCHAR(64) NOT NULL,
            destination_url VARCHAR(500) NOT NULL,
            attribution JSON NOT NULL,
            status ENUM('redirected','completed','expired') DEFAULT 'redirected',
            account_number VARCHAR(50),
            external_id VARCHAR(100),
            completion_data JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            UNIQUE KEY handoff_token (handoff_token),
            KEY instance_id (instance_id),
            KEY visitor_id (visitor_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // External completions table - Inbound completion data from external systems
        $table_external_completions = $wpdb->prefix . ISF_TABLE_EXTERNAL_COMPLETIONS;
        $sql_external_completions = "CREATE TABLE {$table_external_completions} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id INT UNSIGNED NOT NULL,
            source ENUM('webhook','redirect','import') NOT NULL,
            handoff_id INT UNSIGNED,
            account_number VARCHAR(50),
            customer_email VARCHAR(255),
            external_id VARCHAR(100),
            completion_type VARCHAR(50),
            raw_data JSON NOT NULL,
            processed TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY instance_id (instance_id),
            KEY handoff_id (handoff_id),
            KEY account_number (account_number),
            KEY processed (processed)
        ) {$charset_collate};";

        // ============================================
        // PLATFORM ECOSYSTEM TABLES
        // ============================================

        // API Keys table - Developer Portal API key management
        $table_api_keys = $wpdb->prefix . 'isf_api_keys';
        $sql_api_keys = "CREATE TABLE {$table_api_keys} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            api_key VARCHAR(64) NOT NULL,
            api_secret_hash VARCHAR(255) NOT NULL,
            permissions JSON NOT NULL,
            rate_limit INT UNSIGNED DEFAULT 1000,
            burst_limit INT UNSIGNED DEFAULT 100,
            allowed_ips JSON,
            allowed_origins JSON,
            is_active TINYINT(1) DEFAULT 1,
            last_used_at TIMESTAMP NULL,
            usage_count INT UNSIGNED DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY api_key (api_key),
            KEY created_by (created_by),
            KEY is_active (is_active),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        // API Rate Limits table - Track rate limiting per key
        $table_api_rate_limits = $wpdb->prefix . 'isf_api_rate_limits';
        $sql_api_rate_limits = "CREATE TABLE {$table_api_rate_limits} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            api_key_id INT UNSIGNED NOT NULL,
            window_start TIMESTAMP NOT NULL,
            request_count INT UNSIGNED DEFAULT 0,
            KEY api_key_window (api_key_id, window_start)
        ) {$charset_collate};";

        // Marketplace Items table - Templates, connectors, themes
        $table_marketplace_items = $wpdb->prefix . 'isf_marketplace_items';
        $sql_marketplace_items = "CREATE TABLE {$table_marketplace_items} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_type ENUM('template','connector','theme','addon') NOT NULL,
            slug VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            version VARCHAR(20) DEFAULT '1.0.0',
            author VARCHAR(255),
            icon_url VARCHAR(500),
            screenshot_url VARCHAR(500),
            item_data JSON NOT NULL,
            category VARCHAR(50),
            tags JSON,
            is_featured TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            install_count INT UNSIGNED DEFAULT 0,
            rating_avg DECIMAL(2,1) DEFAULT 0,
            rating_count INT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY type_slug (item_type, slug),
            KEY category (category),
            KEY is_featured (is_featured),
            KEY install_count (install_count)
        ) {$charset_collate};";

        // Marketplace Installations table - Track what's installed
        $table_marketplace_installs = $wpdb->prefix . 'isf_marketplace_installs';
        $sql_marketplace_installs = "CREATE TABLE {$table_marketplace_installs} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_id INT UNSIGNED NOT NULL,
            instance_id INT UNSIGNED,
            installed_version VARCHAR(20),
            installed_by BIGINT UNSIGNED NOT NULL,
            settings JSON,
            is_active TINYINT(1) DEFAULT 1,
            installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY item_id (item_id),
            KEY instance_id (instance_id)
        ) {$charset_collate};";

        // Tenants table - White-label SaaS multi-tenancy
        $table_tenants = $wpdb->prefix . 'isf_tenants';
        $sql_tenants = "CREATE TABLE {$table_tenants} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            domain VARCHAR(255),
            contact_email VARCHAR(255) NOT NULL,
            contact_phone VARCHAR(50),
            tier ENUM('starter','professional','enterprise','custom') DEFAULT 'starter',
            status ENUM('active','suspended','inactive') DEFAULT 'active',
            api_key VARCHAR(64) NOT NULL,
            settings JSON,
            custom_limits JSON,
            billing_info JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY slug (slug),
            UNIQUE KEY api_key (api_key),
            KEY domain (domain),
            KEY tier_status (tier, status)
        ) {$charset_collate};";

        // Tenant Clients table - Clients under each tenant
        $table_tenant_clients = $wpdb->prefix . 'isf_tenant_clients';
        $sql_tenant_clients = "CREATE TABLE {$table_tenant_clients} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            contact_email VARCHAR(255),
            settings JSON,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY tenant_id (tenant_id),
            UNIQUE KEY tenant_slug (tenant_id, slug)
        ) {$charset_collate};";

        // Branding Profiles table - White-label theming
        $table_branding = $wpdb->prefix . 'isf_branding_profiles';
        $sql_branding = "CREATE TABLE {$table_branding} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED,
            name VARCHAR(255) NOT NULL,
            settings JSON NOT NULL,
            is_default TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY tenant_id (tenant_id),
            KEY is_default (is_default)
        ) {$charset_collate};";

        // Tenant Usage table - Track usage for billing
        $table_tenant_usage = $wpdb->prefix . 'isf_tenant_usage';
        $sql_tenant_usage = "CREATE TABLE {$table_tenant_usage} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            submissions INT UNSIGNED DEFAULT 0,
            api_calls INT UNSIGNED DEFAULT 0,
            storage_mb INT UNSIGNED DEFAULT 0,
            emails_sent INT UNSIGNED DEFAULT 0,
            sms_sent INT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY tenant_period (tenant_id, period_start),
            KEY period_start (period_start)
        ) {$charset_collate};";

        // BI Reports table - Saved custom reports
        $table_bi_reports = $wpdb->prefix . 'isf_reports';
        $sql_bi_reports = "CREATE TABLE {$table_bi_reports} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            type VARCHAR(50) NOT NULL,
            config JSON NOT NULL,
            is_public TINYINT(1) DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY created_by (created_by),
            KEY type (type)
        ) {$charset_collate};";

        // BI Dashboards table - Custom dashboard layouts
        $table_bi_dashboards = $wpdb->prefix . 'isf_dashboards';
        $sql_bi_dashboards = "CREATE TABLE {$table_bi_dashboards} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            layout JSON,
            widgets JSON,
            is_default TINYINT(1) DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY created_by (created_by),
            KEY is_default (is_default)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql_instances);
        dbDelta($sql_submissions);
        dbDelta($sql_logs);
        dbDelta($sql_analytics);
        dbDelta($sql_retry_queue);
        dbDelta($sql_webhooks);
        dbDelta($sql_api_usage);
        dbDelta($sql_resume_tokens);
        dbDelta($sql_scheduled_reports);
        dbDelta($sql_audit_log);
        dbDelta($sql_gdpr_requests);
        dbDelta($sql_visitors);
        dbDelta($sql_touches);
        dbDelta($sql_handoffs);
        dbDelta($sql_external_completions);

        // Platform Ecosystem tables
        dbDelta($sql_api_keys);
        dbDelta($sql_api_rate_limits);
        dbDelta($sql_marketplace_items);
        dbDelta($sql_marketplace_installs);
        dbDelta($sql_tenants);
        dbDelta($sql_tenant_clients);
        dbDelta($sql_branding);
        dbDelta($sql_tenant_usage);
        dbDelta($sql_bi_reports);
        dbDelta($sql_bi_dashboards);
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options(): void {
        $default_settings = [
            'log_retention_days' => 90,
            'session_timeout_minutes' => 30,
            'rate_limit_requests' => 120,
            'rate_limit_window' => 60,
            'cleanup_abandoned_hours' => 24,
            // Data retention policy settings
            'retention_submissions_days' => 365,
            'retention_analytics_days' => 180,
            'retention_audit_log_days' => 365,
            'retention_api_usage_days' => 90,
            'retention_enabled' => false,
            'anonymize_instead_of_delete' => true,
        ];

        if (!get_option('isf_settings')) {
            add_option('isf_settings', $default_settings);
        }
    }

    /**
     * Schedule cron events for maintenance tasks
     */
    private static function schedule_cron_events(): void {
        // Clean up abandoned sessions daily
        if (!wp_next_scheduled('isf_cleanup_sessions')) {
            wp_schedule_event(time(), 'daily', 'isf_cleanup_sessions');
        }

        // Clean up old logs weekly
        if (!wp_next_scheduled('isf_cleanup_logs')) {
            wp_schedule_event(time(), 'weekly', 'isf_cleanup_logs');
        }

        // Process retry queue every 5 minutes
        if (!wp_next_scheduled('isf_process_retry_queue')) {
            wp_schedule_event(time(), 'five_minutes', 'isf_process_retry_queue');
        }

        // Send scheduled reports hourly (the report generator checks if reports are due)
        if (!wp_next_scheduled('isf_send_scheduled_reports')) {
            wp_schedule_event(time(), 'hourly', 'isf_send_scheduled_reports');
        }

        // Data retention policy automation - run daily
        if (!wp_next_scheduled('isf_apply_retention_policy')) {
            wp_schedule_event(time(), 'daily', 'isf_apply_retention_policy');
        }

        // ML model training - run weekly
        if (!wp_next_scheduled('peanut_ml_formflow_train')) {
            wp_schedule_event(time(), 'weekly', 'peanut_ml_formflow_train');
        }
    }

    /**
     * Add custom cron schedules
     */
    public static function add_cron_schedules(array $schedules): array {
        $schedules['five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 Minutes', 'formflow')
        ];
        return $schedules;
    }
}

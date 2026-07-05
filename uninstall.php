<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Uninstall FormFlow
 *
 * Removes all plugin data when the plugin is uninstalled via WordPress admin.
 * This file is called automatically by WordPress when the plugin is deleted.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Table names - ordered for foreign key constraints (child tables first, parent tables last)
$tables = [
    // Tables with foreign keys to isf_instances (drop first)
    $wpdb->prefix . 'isf_analytics',
    $wpdb->prefix . 'isf_submissions',
    // Tables without foreign keys
    $wpdb->prefix . 'isf_logs',
    $wpdb->prefix . 'isf_retry_queue',
    $wpdb->prefix . 'isf_webhooks',
    $wpdb->prefix . 'isf_api_usage',
    $wpdb->prefix . 'isf_resume_tokens',
    $wpdb->prefix . 'isf_scheduled_reports',
    $wpdb->prefix . 'isf_audit_log',
    $wpdb->prefix . 'isf_gdpr_requests',
    // Parent table (drop last)
    $wpdb->prefix . 'isf_instances',
];

// Drop tables (in correct order due to foreign keys)
foreach ($tables as $table) {
    // Table names are safe (constructed from wpdb->prefix + known strings)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $table ) );
}

// Deactivate license on Peanut License Server before deleting data
$license_key = get_option('formflow_license_key');
if (!empty($license_key)) {
    $api_url = 'https://peanutgraphic.com/wp-json/peanut-api/v1/license/deactivate';
    wp_remote_post($api_url, [
        'timeout' => 10,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'body' => json_encode([
            'license_key' => $license_key,
            'site_url' => home_url(),
        ]),
    ]);
}

// Delete plugin options
delete_option('isf_version');
delete_option('isf_settings');
delete_option('isf_encryption_key_hash');
delete_option('isf_branding');

// Delete license options
delete_option('formflow_license_key');
delete_option('formflow_license_data');
delete_option('formflow_license_last_check');
delete_option('formflow_whitelist_ips');

// Delete transients using proper prepared statements
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_isf_' ) . '%'
    )
);
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_timeout_isf_' ) . '%'
    )
);
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_formflow_' ) . '%'
    )
);
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_timeout_formflow_' ) . '%'
    )
);

// Delete user meta
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( 'isf_' ) . '%'
    )
);

// Clear any scheduled cron events
wp_clear_scheduled_hook('isf_cleanup_sessions');
wp_clear_scheduled_hook('isf_cleanup_logs');
wp_clear_scheduled_hook('isf_process_retry_queue');
wp_clear_scheduled_hook('isf_send_scheduled_reports');
wp_clear_scheduled_hook('isf_apply_retention_policy');

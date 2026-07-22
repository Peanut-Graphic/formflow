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

// Preserve stored data by default. Dropping the tables below is IRREVERSIBLE
// and destroys the enrollment record of a utility program — encrypted customer
// PII, the audit log, and GDPR request history. A single "Delete" click in
// wp-admin (e.g. a delete-and-reinstall to fix a glitch) must not wipe that.
// Destruction only happens when an admin has explicitly opted in via
// isf_settings['delete_data_on_uninstall']. This matches the Deactivator,
// which already preserves data on deactivation.
$isf_settings = get_option('isf_settings', []);
$isf_delete_data = is_array($isf_settings) && !empty($isf_settings['delete_data_on_uninstall']);

if ($isf_delete_data) {
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

    // Config that is only meaningful alongside the now-deleted data. Kept when
    // data is preserved so a reinstall can still read/interpret it — notably
    // the encryption key hash, without which preserved encrypted submissions
    // would be unreadable.
    delete_option('isf_settings');
    delete_option('isf_encryption_key_hash');
    delete_option('isf_branding');

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

// Always safe to run whether or not stored data is preserved: remove the
// version marker (a reinstall re-runs the idempotent schema migration) and the
// license bookkeeping (this site's license was just deactivated above).
delete_option('isf_version');

// Delete license options
delete_option('formflow_license_key');
delete_option('formflow_license_data');
delete_option('formflow_license_last_check');
delete_option('formflow_whitelist_ips');

// Clear any scheduled cron events
wp_clear_scheduled_hook('isf_cleanup_sessions');
wp_clear_scheduled_hook('isf_cleanup_logs');
wp_clear_scheduled_hook('isf_process_retry_queue');
wp_clear_scheduled_hook('isf_send_scheduled_reports');
wp_clear_scheduled_hook('isf_apply_retention_policy');

<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Plugin Deactivator
 *
 * Handles plugin deactivation. Note: Data is preserved on deactivation.
 * Full cleanup only happens on uninstall.
 */


class Deactivator {

    /**
     * Deactivate the plugin
     */
    public static function deactivate(): void {
        // Clear scheduled cron events
        self::clear_cron_events();

        // Clear transients
        self::clear_transients();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Clear all scheduled cron events
     */
    private static function clear_cron_events(): void {
        wp_clear_scheduled_hook('isf_cleanup_sessions');
        wp_clear_scheduled_hook('isf_cleanup_logs');
        wp_clear_scheduled_hook('isf_process_retry_queue');
        wp_clear_scheduled_hook('isf_send_scheduled_reports');
        wp_clear_scheduled_hook('isf_apply_retention_policy');
    }

    /**
     * Clear plugin transients
     */
    private static function clear_transients(): void {
        global $wpdb;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_isf_%',
                '_transient_timeout_isf_%'
            )
        );
    }
}

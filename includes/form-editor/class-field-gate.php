<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side field gate for client-mode submissions.
 *
 * Client admins should only be able to write Copy, Notifications, and
 * destination credentials. Even though the client-mode UI hides
 * everything else, a malicious client could craft a direct POST. This
 * class strips disallowed keys before the save handler reaches the DB.
 */
class FieldGate {

    /** Top-level POST keys clients may not write. */
    private const CLIENT_BLOCKED_TOP_LEVEL = [
        'name','slug','form_type','utility',
        'api_endpoint','api_password',
        'is_active','test_mode','demo_mode',
    ];

    /** Settings JSON sub-sections clients may not write. */
    private const CLIENT_BLOCKED_SETTINGS_SECTIONS = [
        'form_schema','basics','validation','styling',
        'gtm','analytics','features','scheduling','maintenance',
        'tracking','fraud',
    ];

    public static function strip_blocked_fields(array $posted, string $mode): array {
        if ($mode === ModeResolver::MODE_DEV) {
            return $posted;
        }

        foreach (self::CLIENT_BLOCKED_TOP_LEVEL as $k) {
            unset($posted[$k]);
        }

        if (!empty($posted['settings'])) {
            $settings = json_decode(stripslashes_or_passthrough($posted['settings']), true);
            if (is_array($settings)) {
                foreach (self::CLIENT_BLOCKED_SETTINGS_SECTIONS as $section) {
                    unset($settings[$section]);
                }
                $posted['settings'] = wp_json_encode($settings);
            }
        }
        return $posted;
    }
}

/**
 * Tolerates being called from unit tests where stripslashes() may not
 * be desired. Production POST data is always slashed by WP.
 */
function stripslashes_or_passthrough(string $s): string {
    return function_exists('stripslashes') ? stripslashes($s) : $s;
}

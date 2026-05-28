<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers + resolves the custom capability that drives dev/client mode.
 *
 * `isf_dev_mode` is granted to the Administrator role on activation.
 * Site owners revoke per-user via any role-editor plugin to lock down
 * client admins to client mode. Deactivation does NOT remove the cap —
 * preserves grants across deactivate/reactivate cycles.
 */
class Capabilities {

    public const DEV_MODE = 'isf_dev_mode';

    /**
     * Called from the plugin activator. Idempotent.
     */
    public static function register_on_activate(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::DEV_MODE);
        }
    }
}

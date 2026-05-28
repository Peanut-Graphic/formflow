<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the effective editing mode for the current user.
 *
 * - Users without `isf_dev_mode` always get client mode (no override possible).
 * - Users with `isf_dev_mode` default to dev mode, but may opt into client
 *   mode via the user-meta preference. (This is the "Editing as: Client"
 *   switcher in the editor header.)
 */
class ModeResolver {

    public const MODE_DEV    = 'dev';
    public const MODE_CLIENT = 'client';

    public const PREF_META_KEY = 'isf_editor_mode_preference';

    public static function effective_mode(): string {
        if (!self::has_both_modes()) {
            return self::MODE_CLIENT;
        }
        $pref = get_user_meta(get_current_user_id(), self::PREF_META_KEY, true);
        return $pref === self::MODE_CLIENT ? self::MODE_CLIENT : self::MODE_DEV;
    }

    public static function has_both_modes(): bool {
        return (bool) current_user_can(Capabilities::DEV_MODE);
    }

    public static function set_preference(string $mode): bool {
        if (!in_array($mode, [self::MODE_DEV, self::MODE_CLIENT], true)) {
            return false;
        }
        return (bool) update_user_meta(get_current_user_id(), self::PREF_META_KEY, $mode);
    }
}

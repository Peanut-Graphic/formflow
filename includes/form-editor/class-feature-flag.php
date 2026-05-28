<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the ISF_NEW_EDITOR feature flag.
 *
 * Resolution order:
 *   1. `define('ISF_NEW_EDITOR', true|false)` in wp-config.php (wins if defined)
 *   2. `update_option('isf_new_editor', '1'|'0')` admin toggle
 *   3. Default: OFF
 */
class FeatureFlag {

    public const CONSTANT = 'ISF_NEW_EDITOR';
    public const OPTION   = 'isf_new_editor';

    public static function is_enabled(): bool {
        if (defined(self::CONSTANT)) {
            return (bool) constant(self::CONSTANT);
        }
        return self::is_enabled_via_option_for_test(self::OPTION);
    }

    // Separate method so unit tests can validate the option path
    // without colliding with the (one-shot) ISF_NEW_EDITOR define().
    public static function is_enabled_via_option_for_test(string $option_name): bool {
        return get_option($option_name, '0') === '1';
    }
}

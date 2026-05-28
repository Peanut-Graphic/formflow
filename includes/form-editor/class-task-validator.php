<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-task status computation. Pure functions over instance data —
 * no DB calls, no WP function calls beyond what's already in $instance.
 *
 * Used by the overview to render status badges and by detail pages
 * to render the inline status strip.
 */
class TaskValidator {

    public const STATUS_OK        = 'ok';        // green
    public const STATUS_ATTENTION = 'attention'; // red
    public const STATUS_DEFAULTS  = 'defaults';  // yellow
    public const STATUS_NA        = 'na';        // grey

    public static function status_for(string $task_slug, array $instance): string {
        // Form-type conditionals come first.
        if (!TaskRegistry::is_visible_for_form_type($task_slug, $instance['form_type'] ?? '')) {
            return self::STATUS_NA;
        }

        $settings = $instance['settings'] ?? [];

        switch ($task_slug) {
            case 'setup':
                return ($instance['name'] ?? '') && ($instance['slug'] ?? '') && ($instance['form_type'] ?? '')
                    ? self::STATUS_OK : self::STATUS_ATTENTION;

            case 'fields':
                $steps = $settings['form_schema']['steps'] ?? [];
                $count = 0;
                foreach ($steps as $s) { $count += count($s['fields'] ?? []); }
                return $count > 0 ? self::STATUS_OK : self::STATUS_ATTENTION;

            case 'connector':
                // Visible only when form_type !== 'custom' (na-guard above already covered).
                return ($instance['api_endpoint'] ?? '') !== ''
                    ? self::STATUS_OK : self::STATUS_ATTENTION;

            case 'delivery':
                $destinations = $settings['destinations'] ?? [];
                $active = array_filter($destinations, fn($d) => !empty($d['is_active']));
                if (count($active) === 0) {
                    return self::STATUS_ATTENTION;
                }
                foreach ($active as $d) {
                    foreach (['host','username'] as $req) {
                        if (empty($d['config'][$req] ?? '')) {
                            return self::STATUS_ATTENTION;
                        }
                    }
                }
                return self::STATUS_OK;

            case 'scheduling':
                return !empty($settings['scheduling']) ? self::STATUS_OK : self::STATUS_DEFAULTS;

            case 'copy':
                return !empty($settings['content']['form_title']) ? self::STATUS_OK : self::STATUS_DEFAULTS;

            case 'notifications':
                return !empty($settings['email']['send_confirmation']) && !empty($settings['email']['from_address'])
                    ? self::STATUS_OK : self::STATUS_DEFAULTS;

            case 'tracking':
                $gtm = !empty($settings['gtm']['enabled']);
                $analytics = !empty($settings['analytics']['enabled']);
                return ($gtm || $analytics) ? self::STATUS_OK : self::STATUS_DEFAULTS;

            case 'advanced':
            case 'submissions':
                return self::STATUS_OK;
        }

        return self::STATUS_DEFAULTS;
    }
}

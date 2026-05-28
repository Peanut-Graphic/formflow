<?php
namespace ISF\FormEditor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defines the editor task list. Each task = a card on the overview
 * + a single screen in the editor.
 *
 * Tasks declared here are pure data; rendering happens in
 * admin/views/form-editor/tasks/{slug}.php.
 */
class TaskRegistry {

    /** Tasks that hide when the instance's form_type matches this list. */
    private const HIDE_ON_FORM_TYPES = [
        'connector'  => ['custom'],
        'scheduling' => ['custom'],
    ];

    /**
     * Full dev-mode task list.
     *
     * @return array<string, array{title:string, icon:string, view:string, description:string}>
     */
    public static function all_tasks(): array {
        return [
            'setup' => [
                'title'       => __('Setup', 'formflow'),
                'icon'        => 'admin-settings',
                'view'        => 'tasks/setup.php',
                'description' => __('Name, slug, type, utility.', 'formflow'),
            ],
            'fields' => [
                'title'       => __('Form fields', 'formflow'),
                'icon'        => 'forms',
                'view'        => 'tasks/fields.php',
                'description' => __('Schema, validation, layout.', 'formflow'),
            ],
            'connector' => [
                'title'       => __('Connector', 'formflow'),
                'icon'        => 'admin-plugins',
                'view'        => 'tasks/connector.php',
                'description' => __('API auth, presets, test connection.', 'formflow'),
            ],
            'delivery' => [
                'title'       => __('Delivery', 'formflow'),
                'icon'        => 'upload',
                'view'        => 'tasks/delivery.php',
                'description' => __('SFTP, email, webhooks, manual export.', 'formflow'),
            ],
            'scheduling' => [
                'title'       => __('Scheduling', 'formflow'),
                'icon'        => 'calendar-alt',
                'view'        => 'tasks/scheduling.php',
                'description' => __('Slots, blocked dates, capacity, maintenance.', 'formflow'),
            ],
            'copy' => [
                'title'       => __('Copy', 'formflow'),
                'icon'        => 'edit',
                'view'        => 'tasks/copy.php',
                'description' => __('Title, description, buttons, T&Cs.', 'formflow'),
            ],
            'notifications' => [
                'title'       => __('Notifications', 'formflow'),
                'icon'        => 'email',
                'view'        => 'tasks/notifications.php',
                'description' => __('Confirmation email, team alerts.', 'formflow'),
            ],
            'tracking' => [
                'title'       => __('Tracking', 'formflow'),
                'icon'        => 'chart-line',
                'view'        => 'tasks/tracking.php',
                'description' => __('UTM, GA4, attribution.', 'formflow'),
            ],
            'advanced' => [
                'title'       => __('Advanced', 'formflow'),
                'icon'        => 'admin-tools',
                'view'        => 'tasks/advanced.php',
                'description' => __('Features, modes, debug.', 'formflow'),
            ],
            'submissions' => [
                'title'       => __('Submissions', 'formflow'),
                'icon'        => 'list-view',
                'view'        => 'tasks/submissions.php',
                'description' => __('Recent submissions and delivery status.', 'formflow'),
            ],
        ];
    }

    /** Subset of {@see all_tasks()} visible to the given mode. */
    public static function tasks_for_mode(string $mode): array {
        $all = self::all_tasks();
        $visible_slugs = $mode === ModeResolver::MODE_CLIENT
            ? ['delivery','copy','notifications','submissions']
            : ['setup','fields','connector','delivery','scheduling','copy','notifications','tracking','advanced'];
        $ordered = [];
        foreach ($visible_slugs as $slug) {
            if (isset($all[$slug])) {
                $ordered[$slug] = $all[$slug];
            }
        }
        return $ordered;
    }

    /** Whether the task card is shown (not greyed out) for this form_type. */
    public static function is_visible_for_form_type(string $task_slug, string $form_type): bool {
        $hidden_on = self::HIDE_ON_FORM_TYPES[$task_slug] ?? [];
        return !in_array($form_type, $hidden_on, true);
    }
}

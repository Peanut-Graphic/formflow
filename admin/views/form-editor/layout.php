<?php
/**
 * Form Editor — top-level layout.
 *
 * Resolves URL → mode → router → view, then renders chrome
 * (breadcrumb, mode switcher) around the resolved view.
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\Router;
use ISF\FormEditor\ModeResolver;
use ISF\FormEditor\TaskRegistry;

$mode = ModeResolver::effective_mode();
$query = $_GET ?? [];
$router = new Router($query, $mode);
$view = $router->resolved_view();
$instance_id = $router->instance_id();

// Load the instance once for child views.
$instance = null;
if ($instance_id > 0) {
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}isf_instances WHERE id = %d", $instance_id),
        ARRAY_A
    );
    if ($row && !empty($row['settings'])) {
        $row['settings'] = json_decode($row['settings'], true) ?: [];
    }
    $instance = $row ?: null;
}

$tasks = TaskRegistry::tasks_for_mode($mode);
$current_task_def = ($view !== 'overview' && $view !== 'no-task') ? ($tasks[$view] ?? null) : null;
?>
<div class="wrap isf-form-editor" data-mode="<?php echo esc_attr($mode); ?>" data-instance-id="<?php echo (int) $instance_id; ?>">

    <header class="isf-fe-header">
        <nav class="isf-fe-breadcrumb">
            <a href="<?php echo esc_url(admin_url('admin.php?page=isf-dashboard')); ?>">
                <?php esc_html_e('Forms', 'formflow'); ?>
            </a>
            <?php if ($instance) : ?>
                <span class="isf-fe-sep">/</span>
                <a href="<?php echo esc_url(admin_url('admin.php?page=isf-form&id=' . $instance_id)); ?>">
                    <?php echo esc_html($instance['name'] ?? __('(unnamed)', 'formflow')); ?>
                </a>
            <?php endif; ?>
            <?php if ($current_task_def) : ?>
                <span class="isf-fe-sep">/</span>
                <strong>
                    <span class="dashicons dashicons-<?php echo esc_attr($current_task_def['icon']); ?>"></span>
                    <?php echo esc_html($current_task_def['title']); ?>
                </strong>
            <?php endif; ?>
        </nav>

        <?php if (ModeResolver::has_both_modes()) : ?>
            <div class="isf-fe-mode-switcher">
                <label for="isf-fe-mode-pref">
                    <?php esc_html_e('Editing as:', 'formflow'); ?>
                </label>
                <select id="isf-fe-mode-pref" data-current="<?php echo esc_attr($mode); ?>">
                    <option value="dev" <?php selected($mode, ModeResolver::MODE_DEV); ?>>
                        <?php esc_html_e('Dev (all tasks)', 'formflow'); ?>
                    </option>
                    <option value="client" <?php selected($mode, ModeResolver::MODE_CLIENT); ?>>
                        <?php esc_html_e('Client (limited view)', 'formflow'); ?>
                    </option>
                </select>
            </div>
        <?php endif; ?>
    </header>

    <main class="isf-fe-main">
        <?php
        if (!$instance) {
            require ISF_PLUGIN_DIR . 'admin/views/form-editor/no-task.php';
        } elseif ($view === 'overview') {
            require ISF_PLUGIN_DIR . 'admin/views/form-editor/overview.php';
        } elseif ($view === 'no-task') {
            require ISF_PLUGIN_DIR . 'admin/views/form-editor/no-task.php';
        } else {
            $task_def = $tasks[$view];
            $view_path = ISF_PLUGIN_DIR . 'admin/views/form-editor/' . $task_def['view'];
            if (file_exists($view_path)) {
                require $view_path;
            } else {
                require ISF_PLUGIN_DIR . 'admin/views/form-editor/no-task.php';
            }
        }
        ?>
    </main>
</div>

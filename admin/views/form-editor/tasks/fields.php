<?php
/**
 * Form Fields task — reuses the existing form-builder.js drag-reorder
 * + property panel inside the new editor's two-pane chrome.
 *
 * Expects $instance, $instance_id in scope.
 */
if (!defined('ABSPATH')) { exit; }

$schema = $instance['settings']['form_schema'] ?? ['version' => '1.0', 'steps' => []];
$schema_json = wp_json_encode($schema);
?>
<div class="isf-fe-task-panel" data-task="fields">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Form fields', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Drag to reorder; click a field to edit its properties.', 'formflow'); ?></p>

        <!-- Reuse existing form-builder container -->
        <div id="isf-form-builder"
             data-instance-id="<?php echo esc_attr($instance_id); ?>"
             data-schema='<?php echo esc_attr($schema_json); ?>'>
            <noscript><?php esc_html_e('Form builder requires JavaScript.', 'formflow'); ?></noscript>
        </div>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>

<?php
// The existing form-builder.js bootstraps itself against #isf-form-builder.
// Enqueue it explicitly for this task screen:
wp_enqueue_script('isf-form-builder');
wp_enqueue_style('isf-form-builder');
?>

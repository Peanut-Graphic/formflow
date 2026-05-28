<?php
/**
 * Form Fields task — reuses the existing form-builder.js drag-reorder
 * + property panel inside the new editor's two-pane chrome.
 *
 * Expects $instance, $instance_id in scope.
 */
if (!defined('ABSPATH')) { exit; }

$schema = $instance['settings']['form_schema'] ?? ['version' => '1.0', 'steps' => []];

// Provide form-builder.js the globals it auto-bootstraps from.
$builder = \ISF\Builder\FormBuilder::instance();
wp_enqueue_script('isf-form-builder');
wp_enqueue_style('isf-form-builder');
wp_localize_script('isf-form-builder', 'isf_builder', [
    'instance_id' => (int) $instance_id,
    'schema'      => $schema,
    'field_types' => $builder->get_field_types_by_category(),
    'ajax_url'    => admin_url('admin-ajax.php'),
    'nonce'       => wp_create_nonce('isf_admin_nonce'),
    'rest_url'    => rest_url('isf/v1'),
    'rest_nonce'  => wp_create_nonce('wp_rest'),
]);
?>
<div class="isf-fe-task-panel" data-task="fields">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Form fields', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Drag to reorder; click a field to edit its properties.', 'formflow'); ?></p>

        <div id="isf-form-builder">
            <noscript><?php esc_html_e('Form builder requires JavaScript.', 'formflow'); ?></noscript>
        </div>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>

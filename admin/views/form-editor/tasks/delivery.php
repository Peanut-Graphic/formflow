<?php
/**
 * Delivery task — list (left) + per-destination editor (right).
 * Expects $instance, $instance_id, $mode, $router in scope.
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\ModeResolver;
use ISF\FormEditor\TaskValidator;

$destinations = $instance['settings']['destinations'] ?? [];
$selected_key = $router->sub_item();
if ($selected_key === '' && count($destinations) > 0) {
    $selected_key = '0';
}
$selected_idx = is_numeric($selected_key) ? (int) $selected_key : 0;
$selected = $destinations[$selected_idx] ?? null;

$registry = \ISF\Destinations\DestinationRegistry::instance();

// Build rail items
$rail_items = [];
foreach ($destinations as $idx => $d) {
    $d_status = empty($d['is_active']) ? TaskValidator::STATUS_DEFAULTS : (
        (empty($d['config']['host']) || empty($d['config']['username']))
            ? TaskValidator::STATUS_ATTENTION
            : TaskValidator::STATUS_OK
    );
    $rail_items[] = [
        'label'    => $d['name'] ?? ($d['type'] ?? 'destination'),
        'sublabel' => ($d['type'] ?? '') . ' · ' . (empty($d['is_active']) ? __('Paused', 'formflow') : __('Active', 'formflow')),
        'href'     => admin_url('admin.php?page=isf-form&id=' . (int) $instance_id . '&task=delivery&dest=' . $idx),
        'active'   => $idx === $selected_idx,
        'status'   => $d_status,
    ];
}

$rail_footer = '';
if (count($destinations) === 0) {
    $rail_footer = '<em>' . esc_html__('No destinations yet.', 'formflow') . '</em>';
}
?>
<div class="isf-fe-task-panel" data-task="delivery">
    <div class="isf-fe-task-two-pane">
        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sub-rail.php'; ?>

        <div class="isf-fe-detail">
            <?php if (!$selected) : ?>
                <h2><?php esc_html_e('No destination selected', 'formflow'); ?></h2>
                <p><?php esc_html_e('Add a destination from the list on the left, or import a form template that includes one.', 'formflow'); ?></p>
            <?php else :
                $type = $selected['type'] ?? '';
                $destination = $registry->get($type);
            ?>
                <h2><?php echo esc_html($selected['name'] ?? $type); ?></h2>
                <p class="description">
                    <?php echo esc_html($destination ? $destination->get_description() : __('Unknown destination type.', 'formflow')); ?>
                </p>

                <?php
                $missing = (empty($selected['config']['host']) || empty($selected['config']['username']));
                if ($missing) :
                    $strip_status = 'error';
                    $strip_message = __('Two required fields are empty. Fill in host and credentials, then test the connection before activating.', 'formflow');
                    require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/inline-status-strip.php';
                endif;
                ?>

                <?php
                // Render the existing destinations-pod for the selected destination.
                // We pass a one-item destinations array so the existing partial only renders one editor.
                $original_instance = $instance;
                $instance['settings']['destinations'] = [$selected];
                require ISF_PLUGIN_DIR . 'admin/views/partials/destinations-pod.php';
                $instance = $original_instance;
                ?>

                <?php
                $extra_buttons = [
                    '<button type="button" class="button button-secondary isf-destination-test" data-destination-index="' . esc_attr($selected_idx) . '">' .
                    '<span class="dashicons dashicons-admin-network"></span> ' . esc_html__('Test connection', 'formflow') .
                    '</button>',
                ];
                require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php';
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

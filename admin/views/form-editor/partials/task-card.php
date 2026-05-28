<?php
/**
 * One card in the overview grid.
 * Expects $slug, $def, $status, $instance, $instance_id in scope.
 */
if (!defined('ABSPATH')) { exit; }

use ISF\FormEditor\TaskValidator;

$status_labels = [
    TaskValidator::STATUS_OK        => __('Complete', 'formflow'),
    TaskValidator::STATUS_ATTENTION => __('Needs attention', 'formflow'),
    TaskValidator::STATUS_DEFAULTS  => __('Defaults', 'formflow'),
    TaskValidator::STATUS_NA        => __('Not applicable', 'formflow'),
];

$status_symbols = [
    TaskValidator::STATUS_OK        => '✓',
    TaskValidator::STATUS_ATTENTION => '⚠',
    TaskValidator::STATUS_DEFAULTS  => '⊙',
    TaskValidator::STATUS_NA        => '—',
];

$is_clickable = $status !== TaskValidator::STATUS_NA;
$href = $is_clickable
    ? admin_url('admin.php?page=isf-form&id=' . (int) $instance_id . '&task=' . $slug)
    : '#';

$tag = $is_clickable ? 'a' : 'div';
?>
<<?php echo $tag; ?> class="isf-fe-task-card isf-fe-status-<?php echo esc_attr($status); ?>"
    <?php if ($is_clickable): ?>href="<?php echo esc_url($href); ?>"<?php endif; ?>
    aria-disabled="<?php echo $is_clickable ? 'false' : 'true'; ?>">
    <div class="isf-fe-task-card-head">
        <span class="dashicons dashicons-<?php echo esc_attr($def['icon']); ?>"></span>
        <h3><?php echo esc_html($def['title']); ?></h3>
    </div>
    <p class="isf-fe-task-card-desc"><?php echo esc_html($def['description']); ?></p>
    <div class="isf-fe-task-card-status">
        <span class="isf-fe-status-symbol"><?php echo esc_html($status_symbols[$status]); ?></span>
        <span class="isf-fe-status-label"><?php echo esc_html($status_labels[$status]); ?></span>
    </div>
</<?php echo $tag; ?>>

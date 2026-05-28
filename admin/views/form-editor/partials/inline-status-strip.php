<?php
/**
 * Inline status strip — appears at the top of a task's detail pane.
 *
 * Expects $strip_status ('error'|'success'), $strip_message in scope.
 */
if (!defined('ABSPATH')) { exit; }
$class = ($strip_status ?? '') === 'success' ? 'is-success' : 'is-error';
?>
<div class="isf-fe-inline-strip <?php echo esc_attr($class); ?>" role="status">
    <strong><?php echo esc_html($strip_status === 'success' ? '✓' : '⚠'); ?></strong>
    <?php echo esc_html($strip_message ?? ''); ?>
</div>

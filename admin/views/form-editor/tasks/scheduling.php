<?php
if (!defined('ABSPATH')) { exit; }
$scheduling = $instance['settings']['scheduling'] ?? [];
?>
<div class="isf-fe-task-panel" data-task="scheduling">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Scheduling', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Available slots, blocked dates, capacity limits, maintenance windows.', 'formflow'); ?></p>

        <p>
            <em><?php esc_html_e('The scheduling editor is being rebuilt — for now, use the legacy editor at:', 'formflow'); ?></em><br>
            <a href="<?php echo esc_url(admin_url('admin.php?page=isf-instance-editor&id=' . (int) $instance_id . '&bypass=1')); ?>"><?php esc_html_e('Open legacy instance editor', 'formflow'); ?></a>
        </p>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>

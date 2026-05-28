<?php
if (!defined('ABSPATH')) { exit; }
?>
<div class="isf-fe-task-panel" data-task="advanced">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Advanced', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Features, modes, debug, raw settings.', 'formflow'); ?></p>

        <h3><?php esc_html_e('Mode flags', 'formflow'); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="test_mode"><?php esc_html_e('Test Mode', 'formflow'); ?></label></th>
                <td><label><input type="checkbox" id="test_mode" name="test_mode" value="1" data-fe-autosave <?php checked(!empty($instance['test_mode'])); ?>> <?php esc_html_e('Mark submissions as test', 'formflow'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="demo_mode"><?php esc_html_e('Demo Mode', 'formflow'); ?> <span style="color:#d63638"><?php esc_html_e('(dangerous)', 'formflow'); ?></span></label></th>
                <td><label><input type="checkbox" id="demo_mode" name="demo_mode" value="1" data-fe-autosave <?php checked(!empty($instance['settings']['demo_mode'])); ?>> <?php esc_html_e('Return mock data — no real enrollment. NEVER enable on a live form.', 'formflow'); ?></label></td>
            </tr>
        </table>

        <h3><?php esc_html_e('Legacy editor', 'formflow'); ?></h3>
        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=isf-instance-editor&id=' . (int) $instance_id . '&bypass=1')); ?>"><?php esc_html_e('Open legacy editor (for advanced settings not yet ported)', 'formflow'); ?></a></p>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>

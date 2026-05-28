<?php
if (!defined('ABSPATH')) { exit; }
?>
<div class="isf-fe-task-panel" data-task="connector">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Connector', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('API endpoint and credentials for the IntelliSOURCE enrollment connector.', 'formflow'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="api_endpoint"><?php esc_html_e('API endpoint', 'formflow'); ?></label></th>
                <td><input type="url" id="api_endpoint" name="api_endpoint" class="large-text" data-fe-autosave value="<?php echo esc_attr($instance['api_endpoint'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="api_password"><?php esc_html_e('API password', 'formflow'); ?></label></th>
                <td><input type="password" id="api_password" name="api_password" class="regular-text" data-fe-autosave value="" autocomplete="new-password" placeholder="<?php esc_attr_e('(saved — leave blank to keep)', 'formflow'); ?>"></td>
            </tr>
        </table>

        <?php
        $extra_buttons = [
            '<button type="button" class="button" id="isf-test-api"><span class="dashicons dashicons-admin-network"></span> ' . esc_html__('Test connection', 'formflow') . '</button>'
        ];
        require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php';
        ?>
    </div>
</div>

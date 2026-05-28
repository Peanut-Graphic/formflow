<?php
/**
 * Notifications task — confirmation email + team alerts.
 * Expects $instance, $mode in scope.
 */
if (!defined('ABSPATH')) { exit; }
$email = $instance['settings']['email'] ?? [];
?>
<div class="isf-fe-task-panel" data-task="notifications">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Notifications', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Confirmation emails to submitters and notifications to your team.', 'formflow'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="email_send_confirmation"><?php esc_html_e('Send confirmation email', 'formflow'); ?></label></th>
                <td><label><input type="checkbox" id="email_send_confirmation" name="settings[email][send_confirmation]" value="1" data-fe-autosave <?php checked(!empty($email['send_confirmation'])); ?>> <?php esc_html_e('Send a confirmation to the submitter', 'formflow'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="email_from_name"><?php esc_html_e('From name', 'formflow'); ?></label></th>
                <td><input type="text" id="email_from_name" name="settings[email][from_name]" class="regular-text" data-fe-autosave value="<?php echo esc_attr($email['from_name'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="email_from_address"><?php esc_html_e('From address', 'formflow'); ?></label></th>
                <td><input type="email" id="email_from_address" name="settings[email][from_address]" class="regular-text" data-fe-autosave value="<?php echo esc_attr($email['from_address'] ?? ''); ?>"></td>
            </tr>
        </table>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>

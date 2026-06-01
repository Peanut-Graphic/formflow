<?php
/**
 * Copy task — text content (title, description, buttons, T&Cs).
 * Expects $instance, $mode in scope.
 */
if (!defined('ABSPATH')) { exit; }
$content = $instance['settings']['content'] ?? [];
$event_mode_enabled = !empty($instance['settings']['event_mode']);
$event_mode_seconds = (int) ($instance['settings']['event_mode_auto_reset_seconds'] ?? 5);
?>
<div class="isf-fe-task-panel" data-task="copy">
    <div class="isf-fe-detail">
        <h2><?php esc_html_e('Copy & messages', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Public-facing text on the form: title, description, buttons, success message, terms.', 'formflow'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="content_form_title"><?php esc_html_e('Form title', 'formflow'); ?></label></th>
                <td><input type="text" id="content_form_title" name="settings[content][form_title]" class="large-text" data-fe-autosave value="<?php echo esc_attr($content['form_title'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="content_form_description"><?php esc_html_e('Form description', 'formflow'); ?></label></th>
                <td><textarea id="content_form_description" name="settings[content][form_description]" rows="3" class="large-text" data-fe-autosave><?php echo esc_textarea($content['form_description'] ?? ''); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row"><label for="content_submit_label"><?php esc_html_e('Submit button label', 'formflow'); ?></label></th>
                <td><input type="text" id="content_submit_label" name="settings[content][button_labels][submit]" class="regular-text" data-fe-autosave value="<?php echo esc_attr($content['button_labels']['submit'] ?? __('Submit', 'formflow')); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="content_success_message"><?php esc_html_e('Success message', 'formflow'); ?></label></th>
                <td><textarea id="content_success_message" name="settings[content][success_message]" rows="3" class="large-text" data-fe-autosave><?php echo esc_textarea($content['success_message'] ?? ''); ?></textarea></td>
            </tr>
        </table>

        <h3 style="margin-top:2em;"><?php esc_html_e('Event mode', 'formflow'); ?></h3>
        <p class="description"><?php esc_html_e('For tablet kiosks doing back-to-back enrollments (e.g. at an outreach event). When enabled, the success panel shows a "Start another enrollment" button so the next person can submit without a manual browser refresh.', 'formflow'); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Enable event mode', 'formflow'); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="settings[event_mode]"
                               value="1"
                               data-fe-autosave
                               <?php checked($event_mode_enabled); ?>>
                        <?php esc_html_e('Show a "Start another enrollment" button on the success panel.', 'formflow'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="event_mode_auto_reset_seconds"><?php esc_html_e('Auto-reset countdown', 'formflow'); ?></label></th>
                <td>
                    <input type="number"
                           id="event_mode_auto_reset_seconds"
                           name="settings[event_mode_auto_reset_seconds]"
                           min="0"
                           max="60"
                           step="1"
                           class="small-text"
                           data-fe-autosave
                           value="<?php echo esc_attr((string) $event_mode_seconds); ?>"> <?php esc_html_e('seconds', 'formflow'); ?>
                    <p class="description"><?php esc_html_e('After the success panel shows, automatically reset the form for the next person after this many seconds. Set to 0 for manual button only. Tapping anywhere during the countdown cancels the auto-reset.', 'formflow'); ?></p>
                </td>
            </tr>
        </table>

        <?php require ISF_PLUGIN_DIR . 'admin/views/form-editor/partials/sticky-action-bar.php'; ?>
    </div>
</div>

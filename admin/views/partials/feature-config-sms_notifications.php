<?php
/**
 * SMS Notifications Feature Configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = $features['sms_notifications'] ?? [];
?>

<table class="form-table isf-feature-config-table">
    <tr>
        <th scope="row">
            <label for="sms_provider"><?php esc_html_e('SMS Provider', 'formflow'); ?></label>
        </th>
        <td>
            <select id="sms_provider" name="settings[features][sms_notifications][provider]">
                <option value="twilio" <?php selected($settings['provider'] ?? 'twilio', 'twilio'); ?>>Twilio</option>
            </select>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="sms_account_sid"><?php esc_html_e('Account SID', 'formflow'); ?></label>
        </th>
        <td>
            <input type="text" id="sms_account_sid" name="settings[features][sms_notifications][account_sid]"
                   class="regular-text" value="<?php echo esc_attr($settings['account_sid'] ?? ''); ?>"
                   placeholder="ACxxxxxxxxxxxxxxxxx">
            <p class="description"><?php esc_html_e('Your Twilio Account SID', 'formflow'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="sms_auth_token"><?php esc_html_e('Auth Token', 'formflow'); ?></label>
        </th>
        <td>
            <?php // Never render the stored token back into the page — it is encrypted at rest and must not leak into HTML. Blank field; a blank submit keeps the saved value. ?>
            <input type="password" id="sms_auth_token" name="settings[features][sms_notifications][auth_token]"
                   class="regular-text" value="" autocomplete="new-password"
                   placeholder="<?php echo !empty($settings['auth_token'])
                       ? esc_attr__('Saved, leave blank to keep', 'formflow')
                       : esc_attr__('Enter your Auth Token', 'formflow'); ?>">
            <p class="description"><?php esc_html_e('Your Twilio Auth Token (encrypted at rest; leave blank to keep the saved value)', 'formflow'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="sms_from_number"><?php esc_html_e('From Phone Number', 'formflow'); ?></label>
        </th>
        <td>
            <input type="tel" id="sms_from_number" name="settings[features][sms_notifications][from_number]"
                   class="regular-text" value="<?php echo esc_attr($settings['from_number'] ?? ''); ?>"
                   placeholder="+1234567890">
            <p class="description"><?php esc_html_e('Your Twilio phone number (E.164 format)', 'formflow'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Send Notifications', 'formflow'); ?></th>
        <td>
            <fieldset>
                <label class="isf-checkbox-label">
                    <input type="checkbox" name="settings[features][sms_notifications][send_enrollment_confirmation]" value="1"
                           <?php checked($settings['send_enrollment_confirmation'] ?? true); ?>>
                    <?php esc_html_e('Enrollment confirmation', 'formflow'); ?>
                </label>
                <br>
                <label class="isf-checkbox-label">
                    <input type="checkbox" name="settings[features][sms_notifications][send_appointment_reminder]" value="1"
                           <?php checked($settings['send_appointment_reminder'] ?? true); ?>>
                    <?php esc_html_e('Appointment reminder', 'formflow'); ?>
                </label>
            </fieldset>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="sms_reminder_hours"><?php esc_html_e('Reminder Timing', 'formflow'); ?></label>
        </th>
        <td>
            <select id="sms_reminder_hours" name="settings[features][sms_notifications][reminder_hours_before]">
                <option value="12" <?php selected($settings['reminder_hours_before'] ?? 24, 12); ?>>
                    <?php esc_html_e('12 hours before appointment', 'formflow'); ?>
                </option>
                <option value="24" <?php selected($settings['reminder_hours_before'] ?? 24, 24); ?>>
                    <?php esc_html_e('24 hours before appointment', 'formflow'); ?>
                </option>
                <option value="48" <?php selected($settings['reminder_hours_before'] ?? 24, 48); ?>>
                    <?php esc_html_e('48 hours before appointment', 'formflow'); ?>
                </option>
            </select>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Test SMS', 'formflow'); ?></th>
        <td>
            <input type="tel" id="sms_test_number" class="regular-text" placeholder="+1234567890">
            <button type="button" class="button" id="isf-test-sms">
                <?php esc_html_e('Send Test SMS', 'formflow'); ?>
            </button>
            <span id="isf-sms-test-result"></span>
        </td>
    </tr>
</table>

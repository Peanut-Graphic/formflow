<?php
/**
 * Team Notifications Feature Configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = $features['team_notifications'] ?? [];
?>

<table class="form-table isf-feature-config-table">
    <tr>
        <th scope="row">
            <label for="team_provider"><?php esc_html_e('Provider', 'formflow'); ?></label>
        </th>
        <td>
            <select id="team_provider" name="settings[features][team_notifications][provider]" class="isf-team-provider">
                <option value="slack" <?php selected($settings['provider'] ?? 'slack', 'slack'); ?>>Slack</option>
                <option value="teams" <?php selected($settings['provider'] ?? 'slack', 'teams'); ?>>Microsoft Teams</option>
            </select>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="team_webhook_url"><?php esc_html_e('Webhook URL', 'formflow'); ?></label>
        </th>
        <td>
            <?php // Never render the stored webhook back into the page — encrypted at rest, and the URL is itself a secret. Blank field; a blank submit keeps the saved value. ?>
            <input type="url" id="team_webhook_url" name="settings[features][team_notifications][webhook_url]"
                   class="large-text" value="" autocomplete="off"
                   placeholder="<?php echo !empty($settings['webhook_url']) ? esc_attr__('Saved, leave blank to keep', 'formflow') : 'https://hooks.slack.com/services/...'; ?>">
            <p class="description isf-slack-help" <?php echo ($settings['provider'] ?? 'slack') !== 'slack' ? 'style="display:none;"' : ''; ?>>
                <?php printf(
                    esc_html__('Create an incoming webhook in your %s', 'formflow'),
                    '<a href="https://api.slack.com/apps" target="_blank">Slack App settings</a>'
                ); ?>
            </p>
            <p class="description isf-teams-help" <?php echo ($settings['provider'] ?? 'slack') !== 'teams' ? 'style="display:none;"' : ''; ?>>
                <?php printf(
                    esc_html__('Create an incoming webhook in your %s', 'formflow'),
                    '<a href="https://docs.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook" target="_blank">Teams channel settings</a>'
                ); ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Notify On', 'formflow'); ?></th>
        <td>
            <fieldset>
                <label class="isf-checkbox-label">
                    <input type="checkbox" name="settings[features][team_notifications][notify_on_enrollment]" value="1"
                           <?php checked($settings['notify_on_enrollment'] ?? true); ?>>
                    <?php esc_html_e('New enrollment', 'formflow'); ?>
                </label>
                <br>
                <label class="isf-checkbox-label">
                    <input type="checkbox" name="settings[features][team_notifications][notify_on_failure]" value="1"
                           <?php checked($settings['notify_on_failure'] ?? true); ?>>
                    <?php esc_html_e('Failed enrollment', 'formflow'); ?>
                </label>
            </fieldset>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Daily Digest', 'formflow'); ?></th>
        <td>
            <label class="isf-checkbox-label">
                <input type="checkbox" name="settings[features][team_notifications][daily_digest]" value="1"
                       <?php checked($settings['daily_digest'] ?? false); ?>>
                <?php esc_html_e('Send daily summary', 'formflow'); ?>
            </label>
            <br><br>
            <label for="team_digest_time"><?php esc_html_e('Digest Time:', 'formflow'); ?></label>
            <input type="time" id="team_digest_time" name="settings[features][team_notifications][digest_time]"
                   value="<?php echo esc_attr($settings['digest_time'] ?? '09:00'); ?>">
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Test Webhook', 'formflow'); ?></th>
        <td>
            <button type="button" class="button" id="isf-test-webhook">
                <?php esc_html_e('Send Test Notification', 'formflow'); ?>
            </button>
            <span id="isf-webhook-test-result"></span>
        </td>
    </tr>
</table>

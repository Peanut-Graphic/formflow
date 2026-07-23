<?php
/**
 * CRM Integration Feature Configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = $features['crm_integration'] ?? [];
$providers = \ISF\CRMIntegration::get_providers();
?>

<table class="form-table isf-feature-config-table">
    <tr>
        <th scope="row">
            <label for="crm_provider"><?php esc_html_e('CRM Provider', 'formflow'); ?></label>
        </th>
        <td>
            <select id="crm_provider" name="settings[features][crm_integration][provider]" class="isf-crm-provider-select">
                <?php foreach ($providers as $key => $provider): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['provider'] ?? 'salesforce', $key); ?>>
                        <?php echo esc_html($provider['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <!-- Salesforce Settings -->
    <tr class="isf-crm-settings isf-crm-salesforce" <?php echo ($settings['provider'] ?? 'salesforce') !== 'salesforce' ? 'style="display:none;"' : ''; ?>>
        <th scope="row">
            <label for="crm_api_url"><?php esc_html_e('Instance URL', 'formflow'); ?></label>
        </th>
        <td>
            <input type="url" id="crm_api_url" name="settings[features][crm_integration][api_url]"
                   class="regular-text" value="<?php echo esc_attr($settings['api_url'] ?? ''); ?>"
                   placeholder="https://yourorg.my.salesforce.com">
            <p class="description"><?php esc_html_e('Your Salesforce instance URL', 'formflow'); ?></p>
        </td>
    </tr>

    <!-- Common API Settings -->
    <tr class="isf-crm-settings isf-crm-salesforce isf-crm-hubspot isf-crm-zoho isf-crm-custom">
        <th scope="row">
            <label for="crm_api_key"><?php esc_html_e('API Key / Client ID', 'formflow'); ?></label>
        </th>
        <td>
            <input type="text" id="crm_api_key" name="settings[features][crm_integration][api_key]"
                   class="regular-text" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>">
        </td>
    </tr>
    <tr class="isf-crm-settings isf-crm-salesforce isf-crm-zoho">
        <th scope="row">
            <label for="crm_api_secret"><?php esc_html_e('Client Secret', 'formflow'); ?></label>
        </th>
        <td>
            <?php // Never render the stored secret back into the page — encrypted at rest. Blank field; a blank submit keeps the saved value. ?>
            <input type="password" id="crm_api_secret" name="settings[features][crm_integration][api_secret]"
                   class="regular-text" value="" autocomplete="new-password"
                   placeholder="<?php echo !empty($settings['api_secret']) ? esc_attr__('Saved, leave blank to keep', 'formflow') : ''; ?>">
            <p class="description"><?php esc_html_e('Encrypted at rest; leave blank to keep the saved value', 'formflow'); ?></p>
        </td>
    </tr>

    <!-- Custom API Settings -->
    <tr class="isf-crm-settings isf-crm-custom" <?php echo ($settings['provider'] ?? 'salesforce') !== 'custom' ? 'style="display:none;"' : ''; ?>>
        <th scope="row">
            <label for="crm_custom_url"><?php esc_html_e('API Endpoint URL', 'formflow'); ?></label>
        </th>
        <td>
            <input type="url" id="crm_custom_url" name="settings[features][crm_integration][api_url]"
                   class="regular-text" value="<?php echo esc_attr($settings['api_url'] ?? ''); ?>"
                   placeholder="https://api.yourcrm.com/contacts">
        </td>
    </tr>

    <tr class="isf-crm-settings isf-crm-salesforce isf-crm-zoho">
        <th scope="row">
            <label for="crm_object_type"><?php esc_html_e('Object Type', 'formflow'); ?></label>
        </th>
        <td>
            <select id="crm_object_type" name="settings[features][crm_integration][object_type]">
                <option value="Lead" <?php selected($settings['object_type'] ?? 'Lead', 'Lead'); ?>>Lead</option>
                <option value="Contact" <?php selected($settings['object_type'] ?? 'Lead', 'Contact'); ?>>Contact</option>
                <option value="Account" <?php selected($settings['object_type'] ?? 'Lead', 'Account'); ?>>Account</option>
            </select>
        </td>
    </tr>

    <tr>
        <th scope="row"><?php esc_html_e('Sync Timing', 'formflow'); ?></th>
        <td>
            <fieldset>
                <label class="isf-checkbox-label">
                    <input type="checkbox" name="settings[features][crm_integration][sync_on_completion]" value="1"
                           <?php checked($settings['sync_on_completion'] ?? true); ?>>
                    <?php esc_html_e('Sync when enrollment is completed', 'formflow'); ?>
                </label>
                <br>
                <label class="isf-checkbox-label">
                    <input type="checkbox" name="settings[features][crm_integration][sync_on_update]" value="1"
                           <?php checked($settings['sync_on_update'] ?? false); ?>>
                    <?php esc_html_e('Sync when record is updated (reschedule, etc.)', 'formflow'); ?>
                </label>
            </fieldset>
        </td>
    </tr>

    <tr>
        <th scope="row"><?php esc_html_e('Test Connection', 'formflow'); ?></th>
        <td>
            <button type="button" class="button" id="isf-test-crm">
                <?php esc_html_e('Test CRM Connection', 'formflow'); ?>
            </button>
            <span id="isf-crm-test-result"></span>
        </td>
    </tr>
</table>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var providerSelect = document.getElementById('crm_provider');

    function updateProviderFields() {
        var provider = providerSelect.value;
        document.querySelectorAll('.isf-crm-settings').forEach(function(row) {
            row.style.display = row.classList.contains('isf-crm-' + provider) ? '' : 'none';
        });
    }

    providerSelect.addEventListener('change', updateProviderFields);
    updateProviderFields();
});
</script>

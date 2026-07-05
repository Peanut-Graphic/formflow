<?php
/**
 * Tools Tab: Settings
 *
 * @package FormFlow
 */

if (!defined('ABSPATH')) {
    exit;
}

settings_errors('isf_settings');

// Get license manager
$license = \ISF\LicenseManager::instance();
$is_pro = $license->is_pro();
?>

<!-- License Section -->
<div id="license" class="formflow-license-card <?php echo $is_pro ? 'is-pro' : ''; ?>">
    <div class="formflow-license-status">
        <div class="formflow-license-icon <?php echo $is_pro ? 'pro' : 'free'; ?>">
            <span class="dashicons dashicons-<?php echo $is_pro ? 'awards' : 'admin-plugins'; ?>"></span>
        </div>
        <div class="formflow-license-info">
            <h3>
                <?php
                printf(
                    esc_html__('FormFlow %s', 'formflow'),
                    $license->get_license_type_label()
                );
                ?>
            </h3>
            <p>
                <?php if ($is_pro) : ?>
                    <?php esc_html_e('All Pro features are unlocked.', 'formflow'); ?>
                <?php else : ?>
                    <?php esc_html_e('Upgrade to Pro to unlock advanced features.', 'formflow'); ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ($is_pro) : ?>
    <div class="formflow-license-details">
        <div class="formflow-license-detail">
            <span class="formflow-license-detail-label"><?php esc_html_e('License Key', 'formflow'); ?></span>
            <span class="formflow-license-detail-value"><?php echo esc_html($license->get_license_key_masked()); ?></span>
        </div>
        <div class="formflow-license-detail">
            <span class="formflow-license-detail-label"><?php esc_html_e('Type', 'formflow'); ?></span>
            <span class="formflow-license-detail-value"><?php echo esc_html($license->get_license_type_label()); ?></span>
        </div>
        <div class="formflow-license-detail">
            <span class="formflow-license-detail-label"><?php esc_html_e('Expires', 'formflow'); ?></span>
            <span class="formflow-license-detail-value"><?php echo esc_html($license->get_expiration_date()); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <form method="post" style="margin-top: 15px;">
        <?php wp_nonce_field('formflow_license_action'); ?>

        <?php if ($is_pro) : ?>
            <input type="hidden" name="formflow_license_action" value="deactivate">
            <button type="submit" class="button button-secondary">
                <?php esc_html_e('Deactivate License', 'formflow'); ?>
            </button>
        <?php else : ?>
            <div style="display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 250px;">
                    <input type="text" name="license_key" class="regular-text" style="width: 100%;"
                           placeholder="<?php esc_attr_e('FFPR-XXXX-XXXX-XXXX', 'formflow'); ?>">
                </div>
                <input type="hidden" name="formflow_license_action" value="activate">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Activate License', 'formflow'); ?>
                </button>
                <a href="https://formflow.dev/pricing" target="_blank" class="button button-secondary">
                    <?php esc_html_e('Get Pro License', 'formflow'); ?>
                </a>
            </div>
            <p class="description" style="margin-top: 10px;">
                <?php esc_html_e('Enter your license key to unlock Pro features. Purchase a license at formflow.dev', 'formflow'); ?>
            </p>
        <?php endif; ?>
    </form>

    <?php
    // Show admin testing indicator if active
    if ($license->is_admin_testing_mode()) : ?>
    <div style="margin-top: 15px; padding: 10px 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; display: flex; align-items: center; gap: 8px;">
        <span class="dashicons dashicons-warning" style="color: #856404;"></span>
        <span style="color: #856404; font-size: 13px;">
            <strong><?php esc_html_e('Admin Testing Mode Active', 'formflow'); ?></strong> —
            <?php esc_html_e('All Pro features are unlocked for development purposes.', 'formflow'); ?>
        </span>
    </div>
    <?php endif; ?>

    <?php if ($license->is_ip_whitelisted()) : ?>
    <div style="margin-top: 15px; padding: 10px 15px; background: #d1e7dd; border: 1px solid #0f5132; border-radius: 4px; display: flex; align-items: center; gap: 8px;">
        <span class="dashicons dashicons-yes-alt" style="color: #0f5132;"></span>
        <span style="color: #0f5132; font-size: 13px;">
            <strong><?php esc_html_e('IP Whitelisted', 'formflow'); ?></strong> —
            <?php esc_html_e('Your IP address has Pro access.', 'formflow'); ?>
        </span>
    </div>
    <?php endif; ?>

    <?php if (!$is_pro) : ?>
    <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
        <h4 style="margin: 0 0 15px; font-size: 14px;"><?php esc_html_e('Choose Your Plan:', 'formflow'); ?></h4>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <!-- Pro -->
            <div style="border: 2px solid #7c3aed; border-radius: 8px; padding: 15px; text-align: center; background: linear-gradient(135deg, #faf5ff 0%, #fff 100%);">
                <div style="font-weight: 600; color: #7c3aed; font-size: 16px;"><?php esc_html_e('Pro', 'formflow'); ?></div>
                <div style="font-size: 24px; font-weight: 700; margin: 8px 0;">$149<span style="font-size: 14px; font-weight: 400;">/yr</span></div>
                <div style="font-size: 12px; color: #646970;"><?php esc_html_e('1 Site License', 'formflow'); ?></div>
                <div style="font-size: 11px; color: #7c3aed; margin-top: 8px;"><?php esc_html_e('22 Pro Features', 'formflow'); ?></div>
            </div>

            <!-- Agency -->
            <div style="border: 2px solid #2563eb; border-radius: 8px; padding: 15px; text-align: center; background: linear-gradient(135deg, #eff6ff 0%, #fff 100%); position: relative;">
                <div style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background: #2563eb; color: white; font-size: 10px; padding: 2px 10px; border-radius: 10px;"><?php esc_html_e('POPULAR', 'formflow'); ?></div>
                <div style="font-weight: 600; color: #2563eb; font-size: 16px;"><?php esc_html_e('Agency', 'formflow'); ?></div>
                <div style="font-size: 24px; font-weight: 700; margin: 8px 0;">$349<span style="font-size: 14px; font-weight: 400;">/yr</span></div>
                <div style="font-size: 12px; color: #646970;"><?php esc_html_e('3 Site Licenses', 'formflow'); ?></div>
                <div style="font-size: 11px; color: #2563eb; margin-top: 8px;"><?php esc_html_e('All Pro Features', 'formflow'); ?></div>
            </div>

            <!-- Enterprise -->
            <div style="border: 2px solid #059669; border-radius: 8px; padding: 15px; text-align: center; background: linear-gradient(135deg, #ecfdf5 0%, #fff 100%);">
                <div style="font-weight: 600; color: #059669; font-size: 16px;"><?php esc_html_e('Enterprise', 'formflow'); ?></div>
                <div style="font-size: 24px; font-weight: 700; margin: 8px 0;">$749<span style="font-size: 14px; font-weight: 400;">/yr</span></div>
                <div style="font-size: 12px; color: #646970;"><?php esc_html_e('8 Site Licenses', 'formflow'); ?></div>
                <div style="font-size: 11px; color: #059669; margin-top: 8px;"><?php esc_html_e('+ Priority Support & SLA', 'formflow'); ?></div>
            </div>

            <!-- Custom -->
            <div style="border: 2px solid #64748b; border-radius: 8px; padding: 15px; text-align: center; background: linear-gradient(135deg, #f8fafc 0%, #fff 100%);">
                <div style="font-weight: 600; color: #64748b; font-size: 16px;"><?php esc_html_e('Custom', 'formflow'); ?></div>
                <div style="font-size: 18px; font-weight: 600; margin: 12px 0; color: #334155;"><?php esc_html_e('Contact Sales', 'formflow'); ?></div>
                <div style="font-size: 12px; color: #646970;"><?php esc_html_e('Unlimited Sites', 'formflow'); ?></div>
                <div style="font-size: 11px; color: #64748b; margin-top: 8px;"><?php esc_html_e('Custom Pricing', 'formflow'); ?></div>
            </div>
        </div>

        <h4 style="margin: 20px 0 10px; font-size: 14px;"><?php esc_html_e('Pro Features Include:', 'formflow'); ?></h4>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; font-size: 13px;">
            <?php
            $pro_features = $license->get_pro_features();
            $featured = array_slice($pro_features, 0, 12);
            foreach ($featured as $key => $label) : ?>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span class="dashicons dashicons-yes-alt" style="color: #7c3aed; font-size: 16px; width: 16px; height: 16px;"></span>
                    <?php echo esc_html($label); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($pro_features) > 12) : ?>
            <p style="margin: 10px 0 0; font-size: 13px; color: #646970;">
                <?php printf(esc_html__('...and %d more features!', 'formflow'), count($pro_features) - 12); ?>
            </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- IP Whitelist Section (Admin Only) -->
<?php if (current_user_can('manage_options')) :
    $whitelist_ips = $license->get_whitelisted_ips();
    $current_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($current_ip, ',') !== false) {
        $current_ip = trim(explode(',', $current_ip)[0]);
    }
?>
<div class="isf-card" style="margin-top: 20px;">
    <h2><?php esc_html_e('IP Whitelist (Admin Testing)', 'formflow'); ?></h2>
    <p class="description" style="margin-bottom: 15px;">
        <?php esc_html_e('Whitelist IP addresses to grant Pro access without a license key. Useful for development and testing.', 'formflow'); ?>
    </p>

    <form method="post">
        <?php wp_nonce_field('formflow_whitelist_action'); ?>
        <input type="hidden" name="formflow_whitelist_action" value="update">

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Your Current IP', 'formflow'); ?></label>
                </th>
                <td>
                    <code style="font-size: 14px; padding: 5px 10px; background: #f0f0f1;"><?php echo esc_html($current_ip); ?></code>
                    <?php if (!in_array($current_ip, $whitelist_ips, true)) : ?>
                        <button type="submit" name="formflow_whitelist_action" value="add_current" class="button button-secondary" style="margin-left: 10px;">
                            <?php esc_html_e('Add My IP', 'formflow'); ?>
                        </button>
                    <?php else : ?>
                        <span style="margin-left: 10px; color: #00a32a;">
                            <span class="dashicons dashicons-yes" style="vertical-align: middle;"></span>
                            <?php esc_html_e('Whitelisted', 'formflow'); ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="whitelist_ips"><?php esc_html_e('Whitelisted IPs', 'formflow'); ?></label>
                </th>
                <td>
                    <textarea id="whitelist_ips" name="whitelist_ips" rows="5" class="large-text code" placeholder="192.168.1.100&#10;10.0.0.0/24&#10;172.16.*.*"><?php echo esc_textarea(implode("\n", $whitelist_ips)); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('One IP per line. Supports:', 'formflow'); ?>
                    </p>
                    <ul style="margin: 5px 0 0 20px; font-size: 12px; color: #646970;">
                        <li><?php esc_html_e('Exact IPs: 192.168.1.100', 'formflow'); ?></li>
                        <li><?php esc_html_e('CIDR notation: 192.168.1.0/24', 'formflow'); ?></li>
                        <li><?php esc_html_e('Wildcards: 192.168.1.*', 'formflow'); ?></li>
                    </ul>
                </td>
            </tr>
        </table>

        <p class="submit" style="margin-top: 0; padding-top: 0;">
            <button type="submit" class="button button-primary">
                <?php esc_html_e('Save Whitelist', 'formflow'); ?>
            </button>
        </p>
    </form>
</div>
<?php endif; ?>

<form method="post">
    <?php wp_nonce_field('isf_settings_nonce'); ?>

    <div class="isf-card">
        <h2><?php esc_html_e('Session & Security', 'formflow'); ?></h2>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="session_timeout_minutes"><?php esc_html_e('Session Timeout', 'formflow'); ?></label>
                </th>
                <td>
                    <input type="number" id="session_timeout_minutes" name="session_timeout_minutes"
                           value="<?php echo esc_attr($settings['session_timeout_minutes'] ?? 30); ?>"
                           min="5" max="120" class="small-text"> <?php esc_html_e('minutes', 'formflow'); ?>
                    <p class="description">
                        <?php esc_html_e('How long form sessions remain valid after inactivity.', 'formflow'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="rate_limit_preset"><?php esc_html_e('Rate Limiting', 'formflow'); ?></label>
                </th>
                <td>
                    <?php
                    $disable_rate_limit = !empty($settings['disable_rate_limit']);
                    $current_requests = $settings['rate_limit_requests'] ?? 120;
                    $current_window = $settings['rate_limit_window'] ?? 60;
                    ?>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="disable_rate_limit" value="1" <?php checked($disable_rate_limit); ?> id="disable_rate_limit">
                            <?php esc_html_e('Disable rate limiting entirely', 'formflow'); ?>
                        </label>
                        <p class="description" style="margin-left: 24px; color: #d63638;">
                            <?php esc_html_e('Warning: Only disable if experiencing persistent 429 errors. This removes abuse protection.', 'formflow'); ?>
                        </p>
                    </fieldset>

                    <div id="rate_limit_options" style="margin-top: 15px; <?php echo $disable_rate_limit ? 'opacity: 0.5;' : ''; ?>">
                        <label for="rate_limit_preset" style="font-weight: 500;"><?php esc_html_e('Preset:', 'formflow'); ?></label>
                        <select id="rate_limit_preset" style="margin-left: 5px;">
                            <option value="custom"><?php esc_html_e('Custom', 'formflow'); ?></option>
                            <option value="strict" <?php selected($current_requests == 60 && $current_window == 60); ?>><?php esc_html_e('Strict (60/min) - High security', 'formflow'); ?></option>
                            <option value="normal" <?php selected($current_requests == 120 && $current_window == 60); ?>><?php esc_html_e('Normal (120/min) - Recommended', 'formflow'); ?></option>
                            <option value="relaxed" <?php selected($current_requests == 200 && $current_window == 60); ?>><?php esc_html_e('Relaxed (200/min) - If seeing 429 errors', 'formflow'); ?></option>
                            <option value="very_relaxed" <?php selected($current_requests == 300 && $current_window == 60); ?>><?php esc_html_e('Very Relaxed (300/min) - For high-traffic forms', 'formflow'); ?></option>
                        </select>

                        <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                            <label style="font-weight: 500;"><?php esc_html_e('Custom Values:', 'formflow'); ?></label>
                            <div style="margin-top: 8px;">
                                <input type="number" id="rate_limit_requests" name="rate_limit_requests"
                                       value="<?php echo esc_attr($current_requests); ?>"
                                       min="10" max="1000" class="small-text" style="width: 70px;">
                                <?php esc_html_e('requests per', 'formflow'); ?>
                                <input type="number" id="rate_limit_window" name="rate_limit_window"
                                       value="<?php echo esc_attr($current_window); ?>"
                                       min="10" max="300" class="small-text" style="width: 60px;">
                                <?php esc_html_e('seconds per IP address', 'formflow'); ?>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="isf-card">
        <h2><?php esc_html_e('Third-Party Integrations', 'formflow'); ?></h2>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="google_places_api_key"><?php esc_html_e('Google Places API Key', 'formflow'); ?></label>
                </th>
                <td>
                    <input type="text" id="google_places_api_key" name="google_places_api_key" class="regular-text"
                           value="<?php echo esc_attr($settings['google_places_api_key'] ?? ''); ?>"
                           placeholder="AIza...">
                    <p class="description">
                        <?php
                        printf(
                            esc_html__('Optional: Enable address autocomplete on forms. Get a key from the %sGoogle Cloud Console%s.', 'formflow'),
                            '<a href="https://console.cloud.google.com/apis/credentials" target="_blank">',
                            '</a>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>
    </div>

    <div class="isf-card">
        <h2><?php esc_html_e('Data Retention', 'formflow'); ?></h2>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="cleanup_abandoned_hours"><?php esc_html_e('Abandoned Sessions', 'formflow'); ?></label>
                </th>
                <td>
                    <input type="number" id="cleanup_abandoned_hours" name="cleanup_abandoned_hours"
                           value="<?php echo esc_attr($settings['cleanup_abandoned_hours'] ?? 24); ?>"
                           min="1" max="168" class="small-text"> <?php esc_html_e('hours', 'formflow'); ?>
                    <p class="description">
                        <?php esc_html_e('Mark incomplete submissions as abandoned after this time.', 'formflow'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="log_retention_days"><?php esc_html_e('Log Retention', 'formflow'); ?></label>
                </th>
                <td>
                    <input type="number" id="log_retention_days" name="log_retention_days"
                           value="<?php echo esc_attr($settings['log_retention_days'] ?? 90); ?>"
                           min="7" max="365" class="small-text"> <?php esc_html_e('days', 'formflow'); ?>
                    <p class="description">
                        <?php esc_html_e('Automatically delete activity logs older than this.', 'formflow'); ?>
                    </p>
                </td>
            </tr>
        </table>
    </div>

    <div class="isf-card">
        <h2><?php esc_html_e('Encryption', 'formflow'); ?></h2>

        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Encryption Status', 'formflow'); ?></th>
                <td>
                    <?php
                    $encryption = new \ISF\Encryption();
                    $test_result = $encryption->test();
                    ?>
                    <?php if ($test_result) : ?>
                        <span class="isf-status isf-status-active">
                            <span class="dashicons dashicons-yes"></span>
                            <?php esc_html_e('Working', 'formflow'); ?>
                        </span>
                    <?php else : ?>
                        <span class="isf-status isf-status-error">
                            <span class="dashicons dashicons-no"></span>
                            <?php esc_html_e('Error', 'formflow'); ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Custom Encryption Key', 'formflow'); ?></th>
                <td>
                    <?php if (defined('ISF_ENCRYPTION_KEY')) : ?>
                        <span class="isf-status isf-status-active">
                            <?php esc_html_e('Custom key defined in wp-config.php', 'formflow'); ?>
                        </span>
                    <?php else : ?>
                        <span class="isf-status isf-status-warning">
                            <?php esc_html_e('Using WordPress auth salt (default)', 'formflow'); ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="isf-card">
        <h2><?php esc_html_e('System Information', 'formflow'); ?></h2>

        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Plugin Version', 'formflow'); ?></th>
                <td><code><?php echo esc_html(ISF_VERSION); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('PHP Version', 'formflow'); ?></th>
                <td><code><?php echo esc_html(PHP_VERSION); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('WordPress Version', 'formflow'); ?></th>
                <td><code><?php echo esc_html(get_bloginfo('version')); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('SSL Status', 'formflow'); ?></th>
                <td>
                    <?php if (is_ssl()) : ?>
                        <span class="isf-status isf-status-active">
                            <span class="dashicons dashicons-lock"></span>
                            <?php esc_html_e('Active', 'formflow'); ?>
                        </span>
                    <?php else : ?>
                        <span class="isf-status isf-status-warning">
                            <span class="dashicons dashicons-unlock"></span>
                            <?php esc_html_e('Not Active', 'formflow'); ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <p class="submit">
        <input type="submit" name="isf_save_settings" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'formflow'); ?>">
    </p>
</form>

<script>
jQuery(document).ready(function($) {
    var presets = {
        'strict': { requests: 60, window: 60 },
        'normal': { requests: 120, window: 60 },
        'relaxed': { requests: 200, window: 60 },
        'very_relaxed': { requests: 300, window: 60 }
    };

    $('#rate_limit_preset').on('change', function() {
        var preset = $(this).val();
        if (preset !== 'custom' && presets[preset]) {
            $('#rate_limit_requests').val(presets[preset].requests);
            $('#rate_limit_window').val(presets[preset].window);
        }
    });

    $('#disable_rate_limit').on('change', function() {
        if ($(this).is(':checked')) {
            $('#rate_limit_options').css('opacity', '0.5');
            $('#rate_limit_requests, #rate_limit_window, #rate_limit_preset').prop('disabled', true);
        } else {
            $('#rate_limit_options').css('opacity', '1');
            $('#rate_limit_requests, #rate_limit_window, #rate_limit_preset').prop('disabled', false);
        }
    });

    if ($('#disable_rate_limit').is(':checked')) {
        $('#rate_limit_requests, #rate_limit_window, #rate_limit_preset').prop('disabled', true);
    }
});
</script>

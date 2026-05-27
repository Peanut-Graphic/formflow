<?php
/**
 * Admin View: Form Tester
 *
 * Test form API connections and field mappings.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get demo accounts for reference
$demo_accounts = \ISF\Api\MockApiClient::get_demo_accounts_info();
?>

<div class="wrap isf-admin-wrap">
    <h1><?php esc_html_e('Form Tester', 'formflow'); ?></h1>
    <p class="description"><?php esc_html_e('Test API connections and verify form functionality before going live.', 'formflow'); ?></p>

    <!-- Instance Selector -->
    <div class="isf-card">
        <h2><?php esc_html_e('Select Form Instance', 'formflow'); ?></h2>
        <form method="get" action="">
            <input type="hidden" name="page" value="isf-test">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="instance_id"><?php esc_html_e('Form Instance', 'formflow'); ?></label>
                    </th>
                    <td>
                        <select name="instance_id" id="instance_id" onchange="this.form.submit()">
                            <option value=""><?php esc_html_e('-- Select Instance --', 'formflow'); ?></option>
                            <?php foreach ($instances as $inst) : ?>
                                <option value="<?php echo esc_attr($inst['id']); ?>"
                                    <?php selected($instance_id, $inst['id']); ?>>
                                    <?php echo esc_html($inst['name']); ?>
                                    <?php if ($inst['settings']['demo_mode'] ?? false) echo ' [DEMO]'; ?>
                                    <?php if (!$inst['is_active']) echo ' (Inactive)'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
        </form>
    </div>

    <?php if ($instance) : ?>
        <!-- Instance Info -->
        <div class="isf-card">
            <h2><?php esc_html_e('Instance Configuration', 'formflow'); ?></h2>
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Name', 'formflow'); ?></th>
                        <td><?php echo esc_html($instance['name']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Slug', 'formflow'); ?></th>
                        <td><code><?php echo esc_html($instance['slug']); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('API Endpoint', 'formflow'); ?></th>
                        <td><code><?php echo esc_html($instance['api_endpoint']); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Mode', 'formflow'); ?></th>
                        <td>
                            <?php if ($instance['settings']['demo_mode'] ?? false) : ?>
                                <span class="isf-status isf-status-demo"><?php esc_html_e('Demo Mode', 'formflow'); ?></span>
                            <?php elseif ($instance['test_mode']) : ?>
                                <span class="isf-status isf-status-test"><?php esc_html_e('Test Mode', 'formflow'); ?></span>
                            <?php else : ?>
                                <span class="isf-status isf-status-active"><?php esc_html_e('Live', 'formflow'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- API Tests -->
        <div class="isf-test-grid">
            <!-- Connection Test -->
            <div class="isf-card">
                <h2><?php esc_html_e('1. Connection Test', 'formflow'); ?></h2>
                <p class="description"><?php esc_html_e('Verify API connectivity.', 'formflow'); ?></p>
                <button type="button" class="button button-primary isf-run-test" data-test="connection">
                    <?php esc_html_e('Test Connection', 'formflow'); ?>
                </button>
                <div class="isf-test-result" id="result-connection"></div>
            </div>

            <!-- Promo Codes Test -->
            <div class="isf-card">
                <h2><?php esc_html_e('2. Promo Codes', 'formflow'); ?></h2>
                <p class="description"><?php esc_html_e('Fetch promotional codes from API.', 'formflow'); ?></p>
                <button type="button" class="button button-primary isf-run-test" data-test="get_promo_codes">
                    <?php esc_html_e('Get Promo Codes', 'formflow'); ?>
                </button>
                <div class="isf-test-result" id="result-get_promo_codes"></div>
            </div>

            <!-- Account Validation Test -->
            <div class="isf-card">
                <h2><?php esc_html_e('3. Account Validation', 'formflow'); ?></h2>
                <p class="description"><?php esc_html_e('Test account validation with sample data.', 'formflow'); ?></p>

                <?php if ($instance['settings']['demo_mode'] ?? false) : ?>
                    <div class="isf-demo-accounts" style="margin-bottom: 15px;">
                        <h4><?php esc_html_e('Demo Test Accounts', 'formflow'); ?></h4>
                        <table class="isf-demo-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Account #', 'formflow'); ?></th>
                                    <th><?php esc_html_e('ZIP', 'formflow'); ?></th>
                                    <th><?php esc_html_e('Info', 'formflow'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($demo_accounts as $acc) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html($acc['account']); ?></code></td>
                                        <td><code><?php echo esc_html($acc['zip']); ?></code></td>
                                        <td><?php echo esc_html($acc['description']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><label for="test_account"><?php esc_html_e('Account Number', 'formflow'); ?></label></th>
                        <td><input type="text" id="test_account" class="regular-text" value="1234567890"></td>
                    </tr>
                    <tr>
                        <th><label for="test_zip"><?php esc_html_e('ZIP Code', 'formflow'); ?></label></th>
                        <td><input type="text" id="test_zip" class="regular-text" value="20001"></td>
                    </tr>
                </table>
                <button type="button" class="button button-primary isf-run-test" data-test="validate_account">
                    <?php esc_html_e('Validate Account', 'formflow'); ?>
                </button>
                <div class="isf-test-result" id="result-validate_account"></div>
            </div>

            <!-- Schedule Test -->
            <div class="isf-card">
                <h2><?php esc_html_e('4. Schedule Availability', 'formflow'); ?></h2>
                <p class="description"><?php esc_html_e('Fetch available scheduling slots.', 'formflow'); ?></p>
                <button type="button" class="button button-primary isf-run-test" data-test="get_schedule">
                    <?php esc_html_e('Get Schedule Slots', 'formflow'); ?>
                </button>
                <div class="isf-test-result" id="result-get_schedule"></div>
            </div>
        </div>

        <!-- Field Mapping Reference -->
        <div class="isf-card">
            <h2><?php esc_html_e('API Field Mapping Reference', 'formflow'); ?></h2>
            <p class="description"><?php esc_html_e('Shows how form fields map to API parameters.', 'formflow'); ?></p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Form Field', 'formflow'); ?></th>
                        <th><?php esc_html_e('API Parameter', 'formflow'); ?></th>
                        <th><?php esc_html_e('Description', 'formflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>utility_no</code></td>
                        <td><code>utility_no</code></td>
                        <td><?php esc_html_e('Customer account number', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>zip</code></td>
                        <td><code>zip</code></td>
                        <td><?php esc_html_e('Service ZIP code', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>first_name</code></td>
                        <td><code>fname</code></td>
                        <td><?php esc_html_e('Customer first name (returned from API)', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>last_name</code></td>
                        <td><code>lname</code></td>
                        <td><?php esc_html_e('Customer last name (returned from API)', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>ca_no</code></td>
                        <td><code>caNo</code></td>
                        <td><?php esc_html_e('Comverge account number (from validation)', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>schedule_date</code></td>
                        <td><code>schedule_date</code></td>
                        <td><?php esc_html_e('Selected appointment date', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>schedule_time</code></td>
                        <td><code>time</code></td>
                        <td><?php esc_html_e('Selected time slot (AM, MD, PM, EV)', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>promo_code</code></td>
                        <td><code>promo_code</code></td>
                        <td><?php esc_html_e('How did you hear about us', 'formflow'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

    <?php else : ?>
        <div class="isf-card">
            <p class="isf-empty-state">
                <span class="dashicons dashicons-info"></span>
                <?php esc_html_e('Please select a form instance to begin testing.', 'formflow'); ?>
            </p>
        </div>
    <?php endif; ?>
</div>

<?php if ($instance) : ?>
<script>
jQuery(document).ready(function($) {
    var instanceId = <?php echo (int)$instance_id; ?>;

    $('.isf-run-test').on('click', function() {
        var $btn = $(this);
        var testType = $btn.data('test');
        var $result = $('#result-' + testType);
        var testData = {};

        // Collect test data based on test type
        if (testType === 'validate_account') {
            testData = {
                account_number: $('#test_account').val(),
                zip_code: $('#test_zip').val()
            };
        }

        $btn.prop('disabled', true).text('Testing...');
        $result.html('<p><em>Running test...</em></p>').show();

        $.ajax({
            url: isf_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_test_form',
                nonce: isf_admin.nonce,
                instance_id: instanceId,
                test_type: testType,
                test_data: testData
            },
            success: function(response) {
                var html = '';
                if (response.success) {
                    var r = response.data.result;
                    html = '<div class="notice notice-' + (r.success ? 'success' : 'warning') + ' inline">';
                    html += '<p><strong>' + (r.success ? 'Success!' : 'Warning') + '</strong></p>';
                    html += '</div>';
                    html += '<pre style="background:#f5f5f5;padding:10px;overflow:auto;max-height:300px;">';
                    html += JSON.stringify(r.data, null, 2);
                    html += '</pre>';
                    if (response.data.demo_mode) {
                        html += '<p><small><em>Running in Demo Mode</em></small></p>';
                    }
                } else {
                    html = '<div class="notice notice-error inline">';
                    html += '<p><strong>Error:</strong> ' + (response.data.message || 'Unknown error') + '</p>';
                    if (response.data.trace) {
                        html += '<pre style="font-size:11px;overflow:auto;max-height:200px;">' + response.data.trace + '</pre>';
                    }
                    html += '</div>';
                }
                $result.html(html);
            },
            error: function(xhr, status, error) {
                $result.html('<div class="notice notice-error inline"><p><strong>AJAX Error:</strong> ' + error + '</p><p>Status: ' + xhr.status + '</p><pre>' + xhr.responseText.substring(0, 500) + '</pre></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).text($btn.text().replace('Testing...', ''));
                // Restore button text
                var texts = {
                    'connection': '<?php echo esc_js(__('Test Connection', 'formflow')); ?>',
                    'get_promo_codes': '<?php echo esc_js(__('Get Promo Codes', 'formflow')); ?>',
                    'validate_account': '<?php echo esc_js(__('Validate Account', 'formflow')); ?>',
                    'get_schedule': '<?php echo esc_js(__('Get Schedule Slots', 'formflow')); ?>'
                };
                $btn.text(texts[testType]);
            }
        });
    });
});
</script>
<?php endif; ?>

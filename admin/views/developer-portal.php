<?php
/**
 * Developer Portal Admin View
 *
 * API key management, documentation, and SDK downloads.
 *
 * @package FormFlow
 * @since 2.7.0
 * @status upcoming
 */

if (!defined('ABSPATH')) {
    exit;
}

// SECURITY: Check user capabilities
if (!current_user_can('manage_options')) {
    wp_die(
        esc_html__('You do not have sufficient permissions to access this page.', 'formflow'),
        esc_html__('Permission Denied', 'formflow'),
        ['response' => 403]
    );
}

$active_tab = sanitize_text_field($_GET['tab'] ?? 'keys');
$api_platform = \ISF\Platform\APIPlatform::instance();
$api_keys = $api_platform->get_api_keys();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['isf_developer_nonce'])) {
    if (wp_verify_nonce($_POST['isf_developer_nonce'], 'isf_developer_action')) {
        $action = sanitize_text_field($_POST['developer_action'] ?? '');

        if ($action === 'create_key') {
            $result = $api_platform->create_api_key([
                'name' => sanitize_text_field($_POST['key_name']),
                'description' => sanitize_textarea_field($_POST['key_description']),
                'permissions' => array_map('sanitize_text_field', $_POST['permissions'] ?? ['read']),
                'rate_limit' => intval($_POST['rate_limit']),
                'allowed_ips' => array_filter(array_map('trim', explode("\n", $_POST['allowed_ips'] ?? ''))),
                'allowed_origins' => array_filter(array_map('trim', explode("\n", $_POST['allowed_origins'] ?? ''))),
                'expires_at' => (isset($_POST['expires_at']) && $_POST['expires_at'] !== '') ? sanitize_text_field($_POST['expires_at']) : null,
            ]);

            if (!is_wp_error($result)) {
                $new_key_created = $result;
            } else {
                $error_message = $result->get_error_message();
            }
        } elseif ($action === 'revoke_key') {
            $key_id = intval($_POST['key_id']);
            $api_platform->revoke_api_key($key_id);
            $api_keys = $api_platform->get_api_keys(); // Refresh
        }
    }
}
?>

<div class="wrap isf-admin-wrap">
    <h1>
        <span class="dashicons dashicons-rest-api" style="font-size: 30px; width: 30px; height: 30px; margin-right: 10px;"></span>
        <?php esc_html_e('Developer Portal', 'formflow'); ?>
        <span class="isf-badge isf-badge-upcoming"><?php esc_html_e('Upcoming Feature', 'formflow'); ?></span>
    </h1>

    <p class="description" style="font-size: 14px; margin-bottom: 20px;">
        <?php esc_html_e('Manage API keys, view documentation, and download SDKs for integrating with FormFlow.', 'formflow'); ?>
    </p>

    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url(add_query_arg('tab', 'keys')); ?>"
           class="nav-tab <?php echo $active_tab === 'keys' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-network"></span>
            <?php esc_html_e('API Keys', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'docs')); ?>"
           class="nav-tab <?php echo $active_tab === 'docs' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-book"></span>
            <?php esc_html_e('Documentation', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'sdks')); ?>"
           class="nav-tab <?php echo $active_tab === 'sdks' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-code-standards"></span>
            <?php esc_html_e('SDKs & Libraries', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'playground')); ?>"
           class="nav-tab <?php echo $active_tab === 'playground' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-editor-code"></span>
            <?php esc_html_e('API Playground', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'webhooks')); ?>"
           class="nav-tab <?php echo $active_tab === 'webhooks' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-rss"></span>
            <?php esc_html_e('Webhooks', 'formflow'); ?>
        </a>
    </nav>

    <?php if ($active_tab === 'keys') : ?>
        <!-- API Keys Tab -->
        <?php if (isset($new_key_created)) : ?>
            <div class="notice notice-success isf-api-key-notice">
                <h3><?php esc_html_e('API Key Created Successfully!', 'formflow'); ?></h3>
                <p><strong><?php esc_html_e('Save these credentials now. The secret will not be shown again!', 'formflow'); ?></strong></p>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('API Key', 'formflow'); ?></th>
                        <td>
                            <code id="new-api-key" class="isf-api-credential"><?php echo esc_html($new_key_created['api_key']); ?></code>
                            <button type="button" class="button button-small isf-copy-btn" data-target="new-api-key">
                                <span class="dashicons dashicons-clipboard"></span>
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('API Secret', 'formflow'); ?></th>
                        <td>
                            <code id="new-api-secret" class="isf-api-credential"><?php echo esc_html($new_key_created['api_secret']); ?></code>
                            <button type="button" class="button button-small isf-copy-btn" data-target="new-api-secret">
                                <span class="dashicons dashicons-clipboard"></span>
                            </button>
                        </td>
                    </tr>
                </table>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)) : ?>
            <div class="notice notice-error">
                <p><?php echo esc_html($error_message); ?></p>
            </div>
        <?php endif; ?>

        <div class="isf-card">
            <h2><?php esc_html_e('Your API Keys', 'formflow'); ?></h2>

            <?php if (!empty($api_keys)) : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 200px;"><?php esc_html_e('Name', 'formflow'); ?></th>
                            <th style="width: 200px;"><?php esc_html_e('API Key', 'formflow'); ?></th>
                            <th><?php esc_html_e('Permissions', 'formflow'); ?></th>
                            <th style="width: 120px;"><?php esc_html_e('Rate Limit', 'formflow'); ?></th>
                            <th style="width: 140px;"><?php esc_html_e('Last Used', 'formflow'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Status', 'formflow'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Actions', 'formflow'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($api_keys as $key) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($key['name']); ?></strong>
                                    <?php if ($key['description']) : ?>
                                        <br><small class="description"><?php echo esc_html($key['description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code><?php echo esc_html($key['api_key_masked']); ?></code>
                                </td>
                                <td>
                                    <?php foreach ($key['permissions'] as $perm) : ?>
                                        <span class="isf-badge isf-badge-<?php echo esc_attr($perm); ?>"><?php echo esc_html($perm); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td><?php echo esc_html(number_format($key['rate_limit'])); ?>/hr</td>
                                <td>
                                    <?php if ($key['last_used_at']) : ?>
                                        <?php echo esc_html(human_time_diff(strtotime($key['last_used_at']), current_time('timestamp'))); ?> ago
                                    <?php else : ?>
                                        <em><?php esc_html_e('Never', 'formflow'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($key['is_active']) : ?>
                                        <span class="isf-status isf-status-active"><?php esc_html_e('Active', 'formflow'); ?></span>
                                    <?php else : ?>
                                        <span class="isf-status isf-status-revoked"><?php esc_html_e('Revoked', 'formflow'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($key['is_active']) : ?>
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('isf_developer_action', 'isf_developer_nonce'); ?>
                                            <input type="hidden" name="developer_action" value="revoke_key">
                                            <input type="hidden" name="key_id" value="<?php echo esc_attr($key['id']); ?>">
                                            <button type="submit" class="button button-small button-link-delete"
                                                    onclick="return confirm('<?php esc_attr_e('Are you sure you want to revoke this API key?', 'formflow'); ?>');">
                                                <?php esc_html_e('Revoke', 'formflow'); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="isf-empty-state">
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php esc_html_e('No API keys yet. Create one to start using the API.', 'formflow'); ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="isf-card">
            <h2><?php esc_html_e('Create New API Key', 'formflow'); ?></h2>

            <form method="post">
                <?php wp_nonce_field('isf_developer_action', 'isf_developer_nonce'); ?>
                <input type="hidden" name="developer_action" value="create_key">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="key_name"><?php esc_html_e('Key Name', 'formflow'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="key_name" name="key_name" class="regular-text" required
                                   placeholder="<?php esc_attr_e('e.g., Production Integration', 'formflow'); ?>">
                            <p class="description"><?php esc_html_e('A descriptive name to identify this key.', 'formflow'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="key_description"><?php esc_html_e('Description', 'formflow'); ?></label>
                        </th>
                        <td>
                            <textarea id="key_description" name="key_description" class="regular-text" rows="2"
                                      placeholder="<?php esc_attr_e('What will this key be used for?', 'formflow'); ?>"></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Permissions', 'formflow'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="permissions[]" value="read" checked>
                                    <strong><?php esc_html_e('Read', 'formflow'); ?></strong> -
                                    <?php esc_html_e('View instances, submissions, programs', 'formflow'); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="permissions[]" value="write">
                                    <strong><?php esc_html_e('Write', 'formflow'); ?></strong> -
                                    <?php esc_html_e('Create and update records', 'formflow'); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="permissions[]" value="delete">
                                    <strong><?php esc_html_e('Delete', 'formflow'); ?></strong> -
                                    <?php esc_html_e('Delete records (use with caution)', 'formflow'); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="permissions[]" value="analytics">
                                    <strong><?php esc_html_e('Analytics', 'formflow'); ?></strong> -
                                    <?php esc_html_e('Access analytics and reporting endpoints', 'formflow'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="rate_limit"><?php esc_html_e('Rate Limit', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="rate_limit" name="rate_limit" value="1000" min="100" max="100000" class="small-text">
                            <?php esc_html_e('requests per hour', 'formflow'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="allowed_ips"><?php esc_html_e('IP Whitelist', 'formflow'); ?></label>
                        </th>
                        <td>
                            <textarea id="allowed_ips" name="allowed_ips" class="regular-text" rows="3"
                                      placeholder="<?php esc_attr_e('One IP per line (leave empty to allow all)', 'formflow'); ?>"></textarea>
                            <p class="description"><?php esc_html_e('Restrict API access to specific IP addresses.', 'formflow'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="allowed_origins"><?php esc_html_e('CORS Origins', 'formflow'); ?></label>
                        </th>
                        <td>
                            <textarea id="allowed_origins" name="allowed_origins" class="regular-text" rows="3"
                                      placeholder="<?php esc_attr_e('https://example.com (one per line)', 'formflow'); ?>"></textarea>
                            <p class="description"><?php esc_html_e('Allowed origins for browser-based API calls.', 'formflow'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="expires_at"><?php esc_html_e('Expiration', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="date" id="expires_at" name="expires_at" class="regular-text"
                                   min="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>">
                            <p class="description"><?php esc_html_e('Leave empty for no expiration.', 'formflow'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php esc_html_e('Create API Key', 'formflow'); ?>
                    </button>
                </p>
            </form>
        </div>

    <?php elseif ($active_tab === 'docs') : ?>
        <!-- Documentation Tab -->
        <div class="isf-card">
            <h2><?php esc_html_e('API Documentation', 'formflow'); ?></h2>

            <div class="isf-api-docs">
                <h3><?php esc_html_e('Authentication', 'formflow'); ?></h3>
                <p><?php esc_html_e('All API requests require authentication using your API key.', 'formflow'); ?></p>

                <h4><?php esc_html_e('Header Authentication', 'formflow'); ?></h4>
                <pre class="isf-code-block">
curl -X GET "<?php echo esc_html(rest_url('formflow/v2/instances')); ?>" \
  -H "X-API-Key: ff_your_api_key_here" \
  -H "X-API-Secret: your_api_secret_here"</pre>

                <h4><?php esc_html_e('Bearer Token', 'formflow'); ?></h4>
                <pre class="isf-code-block">
curl -X GET "<?php echo esc_html(rest_url('formflow/v2/instances')); ?>" \
  -H "Authorization: Bearer ff_your_api_key_here"</pre>

                <hr>

                <h3><?php esc_html_e('Base URL', 'formflow'); ?></h3>
                <pre class="isf-code-block"><?php echo esc_html(rest_url('formflow/v2')); ?></pre>

                <hr>

                <h3><?php esc_html_e('Endpoints', 'formflow'); ?></h3>

                <div class="isf-endpoint-group">
                    <h4><?php esc_html_e('Instances (Forms)', 'formflow'); ?></h4>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th style="width: 100px;"><?php esc_html_e('Method', 'formflow'); ?></th>
                                <th><?php esc_html_e('Endpoint', 'formflow'); ?></th>
                                <th><?php esc_html_e('Description', 'formflow'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="isf-method isf-method-get">GET</span></td>
                                <td><code>/instances</code></td>
                                <td><?php esc_html_e('List all form instances', 'formflow'); ?></td>
                            </tr>
                            <tr>
                                <td><span class="isf-method isf-method-get">GET</span></td>
                                <td><code>/instances/{id}</code></td>
                                <td><?php esc_html_e('Get a specific instance', 'formflow'); ?></td>
                            </tr>
                            <tr>
                                <td><span class="isf-method isf-method-post">POST</span></td>
                                <td><code>/instances</code></td>
                                <td><?php esc_html_e('Create a new instance', 'formflow'); ?></td>
                            </tr>
                            <tr>
                                <td><span class="isf-method isf-method-put">PUT</span></td>
                                <td><code>/instances/{id}</code></td>
                                <td><?php esc_html_e('Update an instance', 'formflow'); ?></td>
                            </tr>
                            <tr>
                                <td><span class="isf-method isf-method-delete">DELETE</span></td>
                                <td><code>/instances/{id}</code></td>
                                <td><?php esc_html_e('Delete an instance', 'formflow'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="isf-endpoint-group">
                    <h4><?php esc_html_e('Submissions', 'formflow'); ?></h4>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th style="width: 100px;"><?php esc_html_e('Method', 'formflow'); ?></th>
                                <th><?php esc_html_e('Endpoint', 'formflow'); ?></th>
                                <th><?php esc_html_e('Description', 'formflow'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="isf-method isf-method-get">GET</span></td>
                                <td><code>/submissions</code></td>
                                <td><?php esc_html_e('List submissions (with filtering)', 'formflow'); ?></td>
                            </tr>
                            <tr>
                                <td><span class="isf-method isf-method-get">GET</span></td>
                                <td><code>/submissions/{id}</code></td>
                                <td><?php esc_html_e('Get a specific submission', 'formflow'); ?></td>
                            </tr>
                            <tr>
                                <td><span class="isf-method isf-method-post">POST</span></td>
                                <td><code>/submissions</code></td>
                                <td><?php esc_html_e('Create a new submission', 'formflow'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="isf-endpoint-group">
                    <h4><?php esc_html_e('Analytics', 'formflow'); ?></h4>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th style="width: 100px;"><?php esc_html_e('Method', 'formflow'); ?></th>
                                <th><?php esc_html_e('Endpoint', 'formflow'); ?></th>
                                <th><?php esc_html_e('Description', 'formflow'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="isf-method isf-method-get">GET</span></td>
                                <td><code>/analytics/funnel</code></td>
                                <td><?php esc_html_e('Get funnel analytics', 'formflow'); ?></td>
                            </tr>
                            <tr>
                                <td><span class="isf-method isf-method-get">GET</span></td>
                                <td><code>/analytics/conversions</code></td>
                                <td><?php esc_html_e('Get conversion analytics', 'formflow'); ?></td>
                            </tr>
                            <tr>
                                <td><span class="isf-method isf-method-get">GET</span></td>
                                <td><code>/analytics/attribution</code></td>
                                <td><?php esc_html_e('Get attribution analytics', 'formflow'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <hr>

                <h3><?php esc_html_e('Rate Limiting', 'formflow'); ?></h3>
                <p><?php esc_html_e('API requests are rate-limited per API key. Headers are included in each response:', 'formflow'); ?></p>
                <ul>
                    <li><code>X-RateLimit-Limit</code> - <?php esc_html_e('Maximum requests per hour', 'formflow'); ?></li>
                    <li><code>X-RateLimit-Remaining</code> - <?php esc_html_e('Requests remaining', 'formflow'); ?></li>
                    <li><code>X-RateLimit-Reset</code> - <?php esc_html_e('Unix timestamp when limit resets', 'formflow'); ?></li>
                </ul>

                <hr>

                <h3><?php esc_html_e('OpenAPI Specification', 'formflow'); ?></h3>
                <p>
                    <?php esc_html_e('Download the complete OpenAPI 3.0 specification:', 'formflow'); ?>
                    <a href="<?php echo esc_url(rest_url('formflow/v2/openapi.json')); ?>" target="_blank" class="button button-small">
                        <?php esc_html_e('Download OpenAPI Spec', 'formflow'); ?>
                    </a>
                </p>
            </div>
        </div>

    <?php elseif ($active_tab === 'sdks') : ?>
        <!-- SDKs Tab -->
        <div class="isf-card">
            <h2><?php esc_html_e('SDKs & Libraries', 'formflow'); ?></h2>
            <p class="description">
                <?php esc_html_e('Official and community SDKs for integrating with the FormFlow API.', 'formflow'); ?>
            </p>

            <div class="isf-sdk-grid">
                <div class="isf-sdk-card">
                    <div class="isf-sdk-icon">
                        <span class="dashicons dashicons-editor-code" style="font-size: 48px; width: 48px; height: 48px; color: #3178c6;"></span>
                    </div>
                    <h3>JavaScript / TypeScript</h3>
                    <p><?php esc_html_e('Official SDK for Node.js and browser applications.', 'formflow'); ?></p>
                    <pre class="isf-code-block">npm install @formflow/sdk</pre>
                    <p>
                        <span class="isf-badge isf-badge-upcoming"><?php esc_html_e('Coming Soon', 'formflow'); ?></span>
                    </p>
                </div>

                <div class="isf-sdk-card">
                    <div class="isf-sdk-icon">
                        <span class="dashicons dashicons-editor-code" style="font-size: 48px; width: 48px; height: 48px; color: #777bb4;"></span>
                    </div>
                    <h3>PHP</h3>
                    <p><?php esc_html_e('Official SDK for PHP applications.', 'formflow'); ?></p>
                    <pre class="isf-code-block">composer require formflow/sdk</pre>
                    <p>
                        <span class="isf-badge isf-badge-upcoming"><?php esc_html_e('Coming Soon', 'formflow'); ?></span>
                    </p>
                </div>

                <div class="isf-sdk-card">
                    <div class="isf-sdk-icon">
                        <span class="dashicons dashicons-editor-code" style="font-size: 48px; width: 48px; height: 48px; color: #3776ab;"></span>
                    </div>
                    <h3>Python</h3>
                    <p><?php esc_html_e('Official SDK for Python applications.', 'formflow'); ?></p>
                    <pre class="isf-code-block">pip install formflow</pre>
                    <p>
                        <span class="isf-badge isf-badge-upcoming"><?php esc_html_e('Coming Soon', 'formflow'); ?></span>
                    </p>
                </div>

                <div class="isf-sdk-card">
                    <div class="isf-sdk-icon">
                        <span class="dashicons dashicons-editor-code" style="font-size: 48px; width: 48px; height: 48px; color: #b07219;"></span>
                    </div>
                    <h3>Java</h3>
                    <p><?php esc_html_e('Official SDK for Java applications.', 'formflow'); ?></p>
                    <pre class="isf-code-block">&lt;dependency&gt;
  &lt;groupId&gt;com.formflow&lt;/groupId&gt;
  &lt;artifactId&gt;sdk&lt;/artifactId&gt;
&lt;/dependency&gt;</pre>
                    <p>
                        <span class="isf-badge isf-badge-upcoming"><?php esc_html_e('Coming Soon', 'formflow'); ?></span>
                    </p>
                </div>
            </div>
        </div>

        <div class="isf-card">
            <h2><?php esc_html_e('Quick Start Examples', 'formflow'); ?></h2>

            <h3>JavaScript</h3>
            <pre class="isf-code-block">
import { FormFlowClient } from '@formflow/sdk';

const client = new FormFlowClient({
    apiKey: 'ff_your_api_key',
    apiSecret: 'your_api_secret'
});

// List all submissions
const submissions = await client.submissions.list({
    instanceId: 123,
    status: 'completed'
});

// Create a new submission
const newSubmission = await client.submissions.create({
    instanceId: 123,
    formData: {
        email: 'customer@example.com',
        accountNumber: '12345678'
    }
});</pre>

            <h3>PHP</h3>
            <pre class="isf-code-block">
use FormFlow\Client;

$client = new Client([
    'api_key' => 'ff_your_api_key',
    'api_secret' => 'your_api_secret'
]);

// List all submissions
$submissions = $client->submissions->list([
    'instance_id' => 123,
    'status' => 'completed'
]);

// Create a new submission
$newSubmission = $client->submissions->create([
    'instance_id' => 123,
    'form_data' => [
        'email' => 'customer@example.com',
        'account_number' => '12345678'
    ]
]);</pre>

            <h3>Python</h3>
            <pre class="isf-code-block">
from formflow import FormFlowClient

client = FormFlowClient(
    api_key='ff_your_api_key',
    api_secret='your_api_secret'
)

# List all submissions
submissions = client.submissions.list(
    instance_id=123,
    status='completed'
)

# Create a new submission
new_submission = client.submissions.create(
    instance_id=123,
    form_data={
        'email': 'customer@example.com',
        'account_number': '12345678'
    }
)</pre>
        </div>

    <?php elseif ($active_tab === 'playground') : ?>
        <!-- API Playground Tab -->
        <div class="isf-card">
            <h2><?php esc_html_e('API Playground', 'formflow'); ?></h2>
            <p class="description">
                <?php esc_html_e('Test API endpoints directly from your browser.', 'formflow'); ?>
            </p>

            <div class="isf-playground">
                <div class="isf-playground-request">
                    <h3><?php esc_html_e('Request', 'formflow'); ?></h3>

                    <div class="isf-playground-row">
                        <select id="playground-method" class="isf-playground-method">
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="DELETE">DELETE</option>
                        </select>
                        <input type="text" id="playground-endpoint" class="isf-playground-endpoint"
                               value="/instances" placeholder="/instances">
                    </div>

                    <div class="isf-playground-row">
                        <label for="playground-api-key"><?php esc_html_e('API Key', 'formflow'); ?></label>
                        <select id="playground-api-key">
                            <option value=""><?php esc_html_e('Select an API key...', 'formflow'); ?></option>
                            <?php foreach ($api_keys as $key) : ?>
                                <?php if ($key['is_active']) : ?>
                                    <option value="<?php echo esc_attr($key['api_key']); ?>">
                                        <?php echo esc_html($key['name']); ?> (<?php echo esc_html($key['api_key_masked']); ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="isf-playground-row" id="playground-body-row" style="display: none;">
                        <label for="playground-body"><?php esc_html_e('Request Body (JSON)', 'formflow'); ?></label>
                        <textarea id="playground-body" rows="8" placeholder='{"key": "value"}'></textarea>
                    </div>

                    <button type="button" id="playground-send" class="button button-primary">
                        <span class="dashicons dashicons-controls-play"></span>
                        <?php esc_html_e('Send Request', 'formflow'); ?>
                    </button>
                </div>

                <div class="isf-playground-response">
                    <h3><?php esc_html_e('Response', 'formflow'); ?></h3>
                    <div id="playground-status"></div>
                    <pre id="playground-response" class="isf-code-block"><?php esc_html_e('Response will appear here...', 'formflow'); ?></pre>
                </div>
            </div>
        </div>

    <?php elseif ($active_tab === 'webhooks') : ?>
        <!-- Webhooks Tab -->
        <div class="isf-card">
            <h2><?php esc_html_e('Webhooks', 'formflow'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure webhook endpoints to receive real-time notifications about form events.', 'formflow'); ?>
            </p>

            <p>
                <span class="isf-badge isf-badge-upcoming"><?php esc_html_e('Coming Soon', 'formflow'); ?></span>
                <?php esc_html_e('Webhook management will be available in the next update.', 'formflow'); ?>
            </p>

            <h3><?php esc_html_e('Available Events', 'formflow'); ?></h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Event', 'formflow'); ?></th>
                        <th><?php esc_html_e('Description', 'formflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>submission.created</code></td>
                        <td><?php esc_html_e('A new form submission was created', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>submission.completed</code></td>
                        <td><?php esc_html_e('A submission was successfully completed', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>submission.failed</code></td>
                        <td><?php esc_html_e('A submission failed processing', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>appointment.scheduled</code></td>
                        <td><?php esc_html_e('An appointment was scheduled', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>appointment.cancelled</code></td>
                        <td><?php esc_html_e('An appointment was cancelled', 'formflow'); ?></td>
                    </tr>
                    <tr>
                        <td><code>enrollment.confirmed</code></td>
                        <td><?php esc_html_e('A program enrollment was confirmed', 'formflow'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
.isf-badge-upcoming {
    background: #f0f6fc;
    color: #0366d6;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.isf-api-key-notice {
    background: #f0fff4;
    border-left-color: #28a745;
    padding: 15px 20px;
}
.isf-api-credential {
    display: inline-block;
    padding: 8px 12px;
    background: #f6f8fa;
    border-radius: 4px;
    font-size: 13px;
    user-select: all;
}
.isf-method {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    color: #fff;
}
.isf-method-get { background: #28a745; }
.isf-method-post { background: #007bff; }
.isf-method-put { background: #fd7e14; }
.isf-method-delete { background: #dc3545; }
.isf-endpoint-group {
    margin: 20px 0;
}
.isf-sdk-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.isf-sdk-card {
    background: #f6f8fa;
    border: 1px solid #e1e4e8;
    border-radius: 6px;
    padding: 20px;
    text-align: center;
}
.isf-sdk-card h3 {
    margin: 15px 0 10px;
}
.isf-sdk-card pre {
    text-align: left;
    font-size: 12px;
}
.isf-playground {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.isf-playground-row {
    margin-bottom: 15px;
}
.isf-playground-row label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}
.isf-playground-method {
    width: 100px;
}
.isf-playground-endpoint {
    flex: 1;
    margin-left: 10px;
}
.isf-playground-row:first-child {
    display: flex;
    align-items: center;
}
#playground-api-key,
#playground-body {
    width: 100%;
}
#playground-status {
    margin-bottom: 10px;
}
.isf-code-block {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 15px;
    border-radius: 4px;
    overflow-x: auto;
    font-size: 13px;
    line-height: 1.5;
}
@media (max-width: 1200px) {
    .isf-playground {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Copy button functionality
    $('.isf-copy-btn').on('click', function() {
        var targetId = $(this).data('target');
        var text = $('#' + targetId).text();
        navigator.clipboard.writeText(text).then(function() {
            alert('<?php echo esc_js(__('Copied to clipboard!', 'formflow')); ?>');
        });
    });

    // Playground method change
    $('#playground-method').on('change', function() {
        if ($(this).val() === 'POST' || $(this).val() === 'PUT') {
            $('#playground-body-row').show();
        } else {
            $('#playground-body-row').hide();
        }
    });

    // Playground send request
    $('#playground-send').on('click', function() {
        var method = $('#playground-method').val();
        var endpoint = $('#playground-endpoint').val();
        var apiKey = $('#playground-api-key').val();
        var body = $('#playground-body').val();

        if (!apiKey) {
            alert('<?php echo esc_js(__('Please select an API key.', 'formflow')); ?>');
            return;
        }

        var url = '<?php echo esc_js(rest_url('formflow/v2')); ?>' + endpoint;

        var options = {
            method: method,
            headers: {
                'X-API-Key': apiKey,
                'Content-Type': 'application/json'
            }
        };

        if ((method === 'POST' || method === 'PUT') && body) {
            options.body = body;
        }

        $('#playground-send').prop('disabled', true).text('<?php echo esc_js(__('Sending...', 'formflow')); ?>');

        fetch(url, options)
            .then(function(response) {
                var statusClass = response.ok ? 'notice-success' : 'notice-error';
                $('#playground-status').html('<div class="notice ' + statusClass + '"><p>' + response.status + ' ' + response.statusText + '</p></div>');
                return response.json();
            })
            .then(function(data) {
                $('#playground-response').text(JSON.stringify(data, null, 2));
            })
            .catch(function(error) {
                $('#playground-response').text('Error: ' + error.message);
            })
            .finally(function() {
                $('#playground-send').prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> <?php echo esc_js(__('Send Request', 'formflow')); ?>');
            });
    });
});
</script>

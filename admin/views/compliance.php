<?php
/**
 * Admin Compliance View
 *
 * GDPR tools, audit log, and data retention policy management.
 */

if (!defined('ABSPATH')) {
    exit;
}

$active_tab = sanitize_text_field($_GET['tab'] ?? 'gdpr');
$settings = get_option('isf_settings', []);
?>

<div class="wrap isf-admin-wrap">
    <h1><?php esc_html_e('Security & Compliance', 'formflow'); ?></h1>

    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url(add_query_arg('tab', 'gdpr')); ?>"
           class="nav-tab <?php echo $active_tab === 'gdpr' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('GDPR Tools', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'audit')); ?>"
           class="nav-tab <?php echo $active_tab === 'audit' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Audit Log', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'retention')); ?>"
           class="nav-tab <?php echo $active_tab === 'retention' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Data Retention', 'formflow'); ?>
        </a>
    </nav>

    <?php if ($active_tab === 'gdpr') : ?>
        <!-- GDPR Tools Tab -->
        <div class="isf-card">
            <h2><?php esc_html_e('Data Subject Requests', 'formflow'); ?></h2>
            <p class="description">
                <?php esc_html_e('Process GDPR data export and erasure requests. Enter an email address or account number to find associated data.', 'formflow'); ?>
            </p>

            <div class="isf-gdpr-search">
                <form id="isf-gdpr-search-form">
                    <?php wp_nonce_field('isf_admin_nonce', 'nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="gdpr_email"><?php esc_html_e('Email Address', 'formflow'); ?></label>
                            </th>
                            <td>
                                <input type="email" id="gdpr_email" name="email" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="gdpr_account"><?php esc_html_e('Account Number (Optional)', 'formflow'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="gdpr_account" name="account_number" class="regular-text">
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" class="button button-primary" id="isf-gdpr-search">
                            <span class="dashicons dashicons-search" style="vertical-align: middle;"></span>
                            <?php esc_html_e('Search for Data', 'formflow'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <div id="isf-gdpr-results" style="display: none;">
                <hr>
                <h3><?php esc_html_e('Search Results', 'formflow'); ?></h3>
                <div id="isf-gdpr-results-content"></div>

                <div class="isf-gdpr-actions" style="margin-top: 20px;">
                    <button type="button" class="button" id="isf-gdpr-export" disabled>
                        <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                        <?php esc_html_e('Export Data (JSON)', 'formflow'); ?>
                    </button>
                    <button type="button" class="button" id="isf-gdpr-anonymize" disabled>
                        <span class="dashicons dashicons-hidden" style="vertical-align: middle;"></span>
                        <?php esc_html_e('Anonymize Data', 'formflow'); ?>
                    </button>
                    <button type="button" class="button button-link-delete" id="isf-gdpr-delete" disabled>
                        <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                        <?php esc_html_e('Permanently Delete', 'formflow'); ?>
                    </button>
                </div>
            </div>
        </div>

        <div class="isf-card">
            <h2><?php esc_html_e('Request History', 'formflow'); ?></h2>
            <p class="description">
                <?php esc_html_e('View history of GDPR data requests processed through this system.', 'formflow'); ?>
            </p>

            <?php
            $requests = $this->db->get_gdpr_requests([], 20, 0);
            ?>

            <?php if (!empty($requests)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'formflow'); ?></th>
                            <th><?php esc_html_e('Type', 'formflow'); ?></th>
                            <th><?php esc_html_e('Email', 'formflow'); ?></th>
                            <th><?php esc_html_e('Status', 'formflow'); ?></th>
                            <th><?php esc_html_e('Requested', 'formflow'); ?></th>
                            <th><?php esc_html_e('Processed', 'formflow'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $request) : ?>
                            <tr>
                                <td><?php echo esc_html($request['id']); ?></td>
                                <td>
                                    <span class="isf-status isf-status-<?php echo esc_attr($request['request_type']); ?>">
                                        <?php echo esc_html(ucfirst($request['request_type'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($request['email']); ?></td>
                                <td>
                                    <span class="isf-status isf-status-<?php echo esc_attr($request['status']); ?>">
                                        <?php echo esc_html(ucfirst($request['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($request['created_at']); ?></td>
                                <td><?php echo $request['processed_at'] ? esc_html($request['processed_at']) : '&mdash;'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="isf-empty-state">
                    <?php esc_html_e('No GDPR requests have been processed yet.', 'formflow'); ?>
                </p>
            <?php endif; ?>
        </div>

    <?php elseif ($active_tab === 'audit') : ?>
        <!-- Audit Log Tab -->
        <div class="isf-card">
            <h2><?php esc_html_e('Admin Action Audit Log', 'formflow'); ?></h2>
            <p class="description">
                <?php esc_html_e('Track all administrative actions performed in the plugin for security and compliance purposes.', 'formflow'); ?>
            </p>

            <div class="isf-log-filters">
                <form method="get">
                    <input type="hidden" name="page" value="isf-compliance">
                    <input type="hidden" name="tab" value="audit">

                    <select name="action_filter">
                        <option value=""><?php esc_html_e('All Actions', 'formflow'); ?></option>
                        <option value="create" <?php selected($_GET['action_filter'] ?? '', 'create'); ?>><?php esc_html_e('Create', 'formflow'); ?></option>
                        <option value="update" <?php selected($_GET['action_filter'] ?? '', 'update'); ?>><?php esc_html_e('Update', 'formflow'); ?></option>
                        <option value="delete" <?php selected($_GET['action_filter'] ?? '', 'delete'); ?>><?php esc_html_e('Delete', 'formflow'); ?></option>
                        <option value="export" <?php selected($_GET['action_filter'] ?? '', 'export'); ?>><?php esc_html_e('Export', 'formflow'); ?></option>
                        <option value="gdpr_export" <?php selected($_GET['action_filter'] ?? '', 'gdpr_export'); ?>><?php esc_html_e('GDPR Export', 'formflow'); ?></option>
                        <option value="gdpr_erasure" <?php selected($_GET['action_filter'] ?? '', 'gdpr_erasure'); ?>><?php esc_html_e('GDPR Erasure', 'formflow'); ?></option>
                    </select>

                    <select name="object_type">
                        <option value=""><?php esc_html_e('All Objects', 'formflow'); ?></option>
                        <option value="instance" <?php selected($_GET['object_type'] ?? '', 'instance'); ?>><?php esc_html_e('Form Instance', 'formflow'); ?></option>
                        <option value="submission" <?php selected($_GET['object_type'] ?? '', 'submission'); ?>><?php esc_html_e('Submission', 'formflow'); ?></option>
                        <option value="settings" <?php selected($_GET['object_type'] ?? '', 'settings'); ?>><?php esc_html_e('Settings', 'formflow'); ?></option>
                        <option value="webhook" <?php selected($_GET['object_type'] ?? '', 'webhook'); ?>><?php esc_html_e('Webhook', 'formflow'); ?></option>
                        <option value="report" <?php selected($_GET['object_type'] ?? '', 'report'); ?>><?php esc_html_e('Report', 'formflow'); ?></option>
                    </select>

                    <input type="date" name="date_from" value="<?php echo esc_attr($_GET['date_from'] ?? ''); ?>" placeholder="<?php esc_attr_e('From Date', 'formflow'); ?>">
                    <input type="date" name="date_to" value="<?php echo esc_attr($_GET['date_to'] ?? ''); ?>" placeholder="<?php esc_attr_e('To Date', 'formflow'); ?>">

                    <button type="submit" class="button"><?php esc_html_e('Filter', 'formflow'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=isf-compliance&tab=audit')); ?>" class="button"><?php esc_html_e('Reset', 'formflow'); ?></a>
                </form>
            </div>

            <?php
            $filters = [];
            if (isset($_GET['action_filter']) && $_GET['action_filter'] !== '') {
                $filters['action'] = sanitize_text_field($_GET['action_filter']);
            }
            if (isset($_GET['object_type']) && $_GET['object_type'] !== '') {
                $filters['object_type'] = sanitize_text_field($_GET['object_type']);
            }
            if (isset($_GET['date_from']) && $_GET['date_from'] !== '') {
                $filters['date_from'] = sanitize_text_field($_GET['date_from']);
            }
            if (isset($_GET['date_to']) && $_GET['date_to'] !== '') {
                $filters['date_to'] = sanitize_text_field($_GET['date_to']);
            }

            $audit_logs = $this->db->get_audit_log($filters, 50, 0);
            ?>

            <?php if (!empty($audit_logs)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date/Time', 'formflow'); ?></th>
                            <th><?php esc_html_e('User', 'formflow'); ?></th>
                            <th><?php esc_html_e('Action', 'formflow'); ?></th>
                            <th><?php esc_html_e('Object', 'formflow'); ?></th>
                            <th><?php esc_html_e('Details', 'formflow'); ?></th>
                            <th><?php esc_html_e('IP Address', 'formflow'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html($log['created_at']); ?></td>
                                <td>
                                    <strong><?php echo esc_html($log['user_login']); ?></strong>
                                    <br><small><?php echo esc_html($log['user_email']); ?></small>
                                </td>
                                <td>
                                    <code><?php echo esc_html($log['action']); ?></code>
                                </td>
                                <td>
                                    <?php echo esc_html($log['object_type']); ?>
                                    <?php if ($log['object_id']) : ?>
                                        <br><small>#<?php echo esc_html($log['object_id']); ?></small>
                                    <?php endif; ?>
                                    <?php if ($log['object_name']) : ?>
                                        <br><small><?php echo esc_html($log['object_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($log['details'])) : ?>
                                        <button type="button" class="button button-small isf-view-details" data-details="<?php echo esc_attr(json_encode($log['details'])); ?>">
                                            <?php esc_html_e('View', 'formflow'); ?>
                                        </button>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html($log['ip_address']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="isf-empty-state">
                    <span class="dashicons dashicons-shield"></span>
                    <?php esc_html_e('No audit log entries found.', 'formflow'); ?>
                </p>
            <?php endif; ?>
        </div>

    <?php elseif ($active_tab === 'retention') : ?>
        <!-- Data Retention Tab -->
        <div class="isf-card">
            <h2><?php esc_html_e('Data Retention Policy', 'formflow'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure automatic data retention policies to comply with data protection regulations. Old data will be processed during the daily maintenance cron job.', 'formflow'); ?>
            </p>

            <?php settings_errors('isf_retention_settings'); ?>

            <form method="post" id="isf-retention-form">
                <?php wp_nonce_field('isf_retention_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Enable Automatic Retention', 'formflow'); ?></label>
                        </th>
                        <td>
                            <label class="isf-checkbox-label">
                                <input type="checkbox" name="retention_enabled" value="1"
                                    <?php checked($settings['retention_enabled'] ?? false); ?>>
                                <?php esc_html_e('Automatically process old data according to retention policy', 'formflow'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, the system will automatically process data older than the specified retention periods during daily maintenance.', 'formflow'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Retention Action', 'formflow'); ?></label>
                        </th>
                        <td>
                            <label class="isf-checkbox-label">
                                <input type="checkbox" name="anonymize_instead_of_delete" value="1"
                                    <?php checked($settings['anonymize_instead_of_delete'] ?? true); ?>>
                                <?php esc_html_e('Anonymize submissions instead of deleting', 'formflow'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Recommended: Anonymizes PII fields while preserving aggregated data for analytics. If unchecked, submissions will be permanently deleted.', 'formflow'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e('Retention Periods', 'formflow'); ?></h3>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="retention_submissions_days"><?php esc_html_e('Form Submissions', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="retention_submissions_days" name="retention_submissions_days"
                                   value="<?php echo esc_attr($settings['retention_submissions_days'] ?? 365); ?>"
                                   min="30" max="3650" class="small-text">
                            <?php esc_html_e('days', 'formflow'); ?>
                            <p class="description">
                                <?php esc_html_e('Customer submission data including names, emails, account numbers, etc.', 'formflow'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="retention_analytics_days"><?php esc_html_e('Analytics Data', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="retention_analytics_days" name="retention_analytics_days"
                                   value="<?php echo esc_attr($settings['retention_analytics_days'] ?? 180); ?>"
                                   min="30" max="730" class="small-text">
                            <?php esc_html_e('days', 'formflow'); ?>
                            <p class="description">
                                <?php esc_html_e('Form funnel analytics, step timing, and device information.', 'formflow'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="retention_audit_log_days"><?php esc_html_e('Audit Logs', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="retention_audit_log_days" name="retention_audit_log_days"
                                   value="<?php echo esc_attr($settings['retention_audit_log_days'] ?? 365); ?>"
                                   min="90" max="1825" class="small-text">
                            <?php esc_html_e('days', 'formflow'); ?>
                            <p class="description">
                                <?php esc_html_e('Admin action audit trail (recommended minimum: 1 year for compliance).', 'formflow'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="retention_api_usage_days"><?php esc_html_e('API Usage Logs', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="retention_api_usage_days" name="retention_api_usage_days"
                                   value="<?php echo esc_attr($settings['retention_api_usage_days'] ?? 90); ?>"
                                   min="7" max="365" class="small-text">
                            <?php esc_html_e('days', 'formflow'); ?>
                            <p class="description">
                                <?php esc_html_e('API call logs for rate limiting and debugging.', 'formflow'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="log_retention_days"><?php esc_html_e('Activity Logs', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="log_retention_days" name="log_retention_days"
                                   value="<?php echo esc_attr($settings['log_retention_days'] ?? 90); ?>"
                                   min="7" max="365" class="small-text">
                            <?php esc_html_e('days', 'formflow'); ?>
                            <p class="description">
                                <?php esc_html_e('General activity and error logs.', 'formflow'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="isf_save_retention" class="button button-primary" value="<?php esc_attr_e('Save Retention Policy', 'formflow'); ?>">
                    <button type="button" id="isf-preview-retention" class="button">
                        <?php esc_html_e('Preview Affected Data', 'formflow'); ?>
                    </button>
                    <button type="button" id="isf-run-retention" class="button button-secondary">
                        <?php esc_html_e('Run Retention Now', 'formflow'); ?>
                    </button>
                </p>
            </form>

            <div id="isf-retention-preview" style="display: none; margin-top: 20px;">
                <h3><?php esc_html_e('Data Affected by Current Policy', 'formflow'); ?></h3>
                <div id="isf-retention-preview-content"></div>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- Details Modal -->
<div id="isf-details-modal" class="isf-modal" style="display: none;">
    <div class="isf-modal-content">
        <div class="isf-modal-header">
            <h2><?php esc_html_e('Details', 'formflow'); ?></h2>
            <button type="button" class="isf-modal-close">&times;</button>
        </div>
        <div class="isf-modal-body">
            <pre id="isf-details-content" class="isf-raw-data"></pre>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var foundSubmissions = [];

    // GDPR Search
    $('#isf-gdpr-search-form').on('submit', function(e) {
        e.preventDefault();

        var email = $('#gdpr_email').val();
        var account = $('#gdpr_account').val();

        $('#isf-gdpr-search').prop('disabled', true).text('<?php echo esc_js(__('Searching...', 'formflow')); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'formflow_gdpr_search',
                nonce: $('input[name="nonce"]').val(),
                email: email,
                account_number: account
            },
            success: function(response) {
                $('#isf-gdpr-search').prop('disabled', false).html('<span class="dashicons dashicons-search" style="vertical-align: middle;"></span> <?php echo esc_js(__('Search for Data', 'formflow')); ?>');

                if (response.success && response.data.submissions.length > 0) {
                    foundSubmissions = response.data.submissions;
                    var html = '<p><strong>' + response.data.submissions.length + ' <?php echo esc_js(__('submission(s) found', 'formflow')); ?></strong></p>';
                    html += '<table class="widefat striped"><thead><tr>';
                    html += '<th><?php echo esc_js(__('ID', 'formflow')); ?></th>';
                    html += '<th><?php echo esc_js(__('Name', 'formflow')); ?></th>';
                    html += '<th><?php echo esc_js(__('Email', 'formflow')); ?></th>';
                    html += '<th><?php echo esc_js(__('Account', 'formflow')); ?></th>';
                    html += '<th><?php echo esc_js(__('Date', 'formflow')); ?></th>';
                    html += '<th><?php echo esc_js(__('Status', 'formflow')); ?></th>';
                    html += '</tr></thead><tbody>';

                    response.data.submissions.forEach(function(sub) {
                        html += '<tr>';
                        html += '<td>' + sub.id + '</td>';
                        html += '<td>' + (sub.customer_name || '-') + '</td>';
                        html += '<td>' + (sub.form_data.email || '-') + '</td>';
                        html += '<td>' + (sub.form_data.account_number || '-') + '</td>';
                        html += '<td>' + sub.created_at + '</td>';
                        html += '<td><span class="isf-status isf-status-' + sub.status + '">' + sub.status + '</span></td>';
                        html += '</tr>';
                    });

                    html += '</tbody></table>';
                    $('#isf-gdpr-results-content').html(html);
                    $('#isf-gdpr-results').show();
                    $('#isf-gdpr-export, #isf-gdpr-anonymize, #isf-gdpr-delete').prop('disabled', false);
                } else {
                    $('#isf-gdpr-results-content').html('<p class="notice notice-warning"><?php echo esc_js(__('No data found for this email/account.', 'formflow')); ?></p>');
                    $('#isf-gdpr-results').show();
                    $('#isf-gdpr-export, #isf-gdpr-anonymize, #isf-gdpr-delete').prop('disabled', true);
                }
            },
            error: function() {
                $('#isf-gdpr-search').prop('disabled', false).html('<span class="dashicons dashicons-search" style="vertical-align: middle;"></span> <?php echo esc_js(__('Search for Data', 'formflow')); ?>');
                alert('<?php echo esc_js(__('Search failed. Please try again.', 'formflow')); ?>');
            }
        });
    });

    // GDPR Export
    $('#isf-gdpr-export').on('click', function() {
        if (foundSubmissions.length === 0) return;

        $(this).prop('disabled', true).text('<?php echo esc_js(__('Exporting...', 'formflow')); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'formflow_gdpr_export',
                nonce: $('input[name="nonce"]').val(),
                email: $('#gdpr_email').val(),
                account_number: $('#gdpr_account').val(),
                submission_ids: foundSubmissions.map(function(s) { return s.id; })
            },
            success: function(response) {
                $('#isf-gdpr-export').prop('disabled', false).html('<span class="dashicons dashicons-download" style="vertical-align: middle;"></span> <?php echo esc_js(__('Export Data (JSON)', 'formflow')); ?>');

                if (response.success) {
                    // Download the exported data
                    var dataStr = JSON.stringify(response.data.export_data, null, 2);
                    var dataUri = 'data:application/json;charset=utf-8,' + encodeURIComponent(dataStr);
                    var filename = response.data.filename || 'gdpr-export-' + new Date().toISOString().split('T')[0] + '.json';

                    var link = document.createElement('a');
                    link.setAttribute('href', dataUri);
                    link.setAttribute('download', filename);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Export failed.', 'formflow')); ?>');
                }
            },
            error: function() {
                $('#isf-gdpr-export').prop('disabled', false).html('<span class="dashicons dashicons-download" style="vertical-align: middle;"></span> <?php echo esc_js(__('Export Data (JSON)', 'formflow')); ?>');
                alert('<?php echo esc_js(__('Export failed. Please try again.', 'formflow')); ?>');
            }
        });
    });

    // GDPR Anonymize
    $('#isf-gdpr-anonymize').on('click', function() {
        if (foundSubmissions.length === 0) return;

        if (!confirm('<?php echo esc_js(__('This will anonymize all personal data in the found submissions. This action cannot be undone. Continue?', 'formflow')); ?>')) {
            return;
        }

        $(this).prop('disabled', true).text('<?php echo esc_js(__('Anonymizing...', 'formflow')); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'formflow_gdpr_anonymize',
                nonce: $('input[name="nonce"]').val(),
                submission_ids: foundSubmissions.map(function(s) { return s.id; }),
                email: $('#gdpr_email').val()
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Anonymization failed.', 'formflow')); ?>');
                    $('#isf-gdpr-anonymize').prop('disabled', false).html('<span class="dashicons dashicons-hidden" style="vertical-align: middle;"></span> <?php echo esc_js(__('Anonymize Data', 'formflow')); ?>');
                }
            }
        });
    });

    // GDPR Delete
    $('#isf-gdpr-delete').on('click', function() {
        if (foundSubmissions.length === 0) return;

        if (!confirm('<?php echo esc_js(__('WARNING: This will PERMANENTLY DELETE all found submissions and related data. This action CANNOT be undone. Are you absolutely sure?', 'formflow')); ?>')) {
            return;
        }

        $(this).prop('disabled', true).text('<?php echo esc_js(__('Deleting...', 'formflow')); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'formflow_gdpr_delete',
                nonce: $('input[name="nonce"]').val(),
                submission_ids: foundSubmissions.map(function(s) { return s.id; }),
                email: $('#gdpr_email').val()
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Deletion failed.', 'formflow')); ?>');
                    $('#isf-gdpr-delete').prop('disabled', false).html('<span class="dashicons dashicons-trash" style="vertical-align: middle;"></span> <?php echo esc_js(__('Permanently Delete', 'formflow')); ?>');
                }
            }
        });
    });

    // View Details
    $('.isf-view-details').on('click', function() {
        var details = $(this).data('details');
        $('#isf-details-content').text(JSON.stringify(details, null, 2));
        $('#isf-details-modal').show();
    });

    // Close Modal
    $('.isf-modal-close, .isf-modal').on('click', function(e) {
        if (e.target === this || $(this).hasClass('isf-modal-close')) {
            $('.isf-modal').hide();
        }
    });

    $('.isf-modal-content').on('click', function(e) {
        e.stopPropagation();
    });

    // Preview Retention
    $('#isf-preview-retention').on('click', function() {
        $(this).prop('disabled', true).text('<?php echo esc_js(__('Loading...', 'formflow')); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'formflow_preview_retention',
                nonce: '<?php echo wp_create_nonce('isf_admin_nonce'); ?>',
                retention_submissions_days: $('#retention_submissions_days').val(),
                retention_analytics_days: $('#retention_analytics_days').val(),
                retention_audit_log_days: $('#retention_audit_log_days').val(),
                retention_api_usage_days: $('#retention_api_usage_days').val()
            },
            success: function(response) {
                $('#isf-preview-retention').prop('disabled', false).text('<?php echo esc_js(__('Preview Affected Data', 'formflow')); ?>');

                if (response.success) {
                    var stats = response.data.stats;
                    var html = '<table class="widefat"><tbody>';
                    html += '<tr><th><?php echo esc_js(__('Submissions to process', 'formflow')); ?></th><td>' + (stats.submissions || 0) + '</td></tr>';
                    html += '<tr><th><?php echo esc_js(__('Analytics records to delete', 'formflow')); ?></th><td>' + (stats.analytics || 0) + '</td></tr>';
                    html += '<tr><th><?php echo esc_js(__('Audit logs to delete', 'formflow')); ?></th><td>' + (stats.audit_logs || 0) + '</td></tr>';
                    html += '<tr><th><?php echo esc_js(__('API usage records to delete', 'formflow')); ?></th><td>' + (stats.api_usage || 0) + '</td></tr>';
                    html += '</tbody></table>';
                    $('#isf-retention-preview-content').html(html);
                    $('#isf-retention-preview').show();
                }
            }
        });
    });

    // Run Retention
    $('#isf-run-retention').on('click', function() {
        if (!confirm('<?php echo esc_js(__('This will apply the retention policy now. Are you sure?', 'formflow')); ?>')) {
            return;
        }

        $(this).prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'formflow')); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'formflow_run_retention',
                nonce: '<?php echo wp_create_nonce('isf_admin_nonce'); ?>',
                retention_submissions_days: $('#retention_submissions_days').val(),
                retention_analytics_days: $('#retention_analytics_days').val(),
                retention_audit_log_days: $('#retention_audit_log_days').val(),
                retention_api_usage_days: $('#retention_api_usage_days').val(),
                anonymize_instead_of_delete: $('input[name="anonymize_instead_of_delete"]').is(':checked') ? 1 : 0
            },
            success: function(response) {
                $('#isf-run-retention').prop('disabled', false).text('<?php echo esc_js(__('Run Retention Now', 'formflow')); ?>');

                if (response.success) {
                    var r = response.data.results;
                    var msg = '<?php echo esc_js(__('Retention policy applied:', 'formflow')); ?>\n';
                    msg += '- <?php echo esc_js(__('Submissions anonymized:', 'formflow')); ?> ' + (r.submissions_anonymized || 0) + '\n';
                    msg += '- <?php echo esc_js(__('Submissions deleted:', 'formflow')); ?> ' + (r.submissions_deleted || 0) + '\n';
                    msg += '- <?php echo esc_js(__('Analytics deleted:', 'formflow')); ?> ' + (r.analytics_deleted || 0) + '\n';
                    msg += '- <?php echo esc_js(__('Audit logs deleted:', 'formflow')); ?> ' + (r.audit_logs_deleted || 0) + '\n';
                    msg += '- <?php echo esc_js(__('API usage deleted:', 'formflow')); ?> ' + (r.api_usage_deleted || 0);
                    alert(msg);
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Retention policy failed.', 'formflow')); ?>');
                }
            }
        });
    });
});
</script>

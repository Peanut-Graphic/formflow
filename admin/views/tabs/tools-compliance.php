<?php
/**
 * Tools Tab: Compliance
 *
 * GDPR tools, audit log, and data retention policy management.
 *
 * @package FormFlow
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<p class="description" style="font-size: 14px;">
    <?php esc_html_e('Manage GDPR compliance, audit logs, and data retention policies.', 'formflow'); ?>
</p>

<!-- Inner Tab Navigation -->
<div class="isf-inner-tabs" style="margin-top: 15px;">
    <a href="<?php echo esc_url(add_query_arg('compliance_tab', 'gdpr')); ?>"
       class="isf-inner-tab <?php echo ($compliance_tab === 'gdpr') ? 'isf-inner-tab-active' : ''; ?>">
        <?php esc_html_e('GDPR Tools', 'formflow'); ?>
    </a>
    <a href="<?php echo esc_url(add_query_arg('compliance_tab', 'audit')); ?>"
       class="isf-inner-tab <?php echo ($compliance_tab === 'audit') ? 'isf-inner-tab-active' : ''; ?>">
        <?php esc_html_e('Audit Log', 'formflow'); ?>
    </a>
    <a href="<?php echo esc_url(add_query_arg('compliance_tab', 'retention')); ?>"
       class="isf-inner-tab <?php echo ($compliance_tab === 'retention') ? 'isf-inner-tab-active' : ''; ?>">
        <?php esc_html_e('Data Retention', 'formflow'); ?>
    </a>
</div>

<?php if ($compliance_tab === 'gdpr') : ?>
    <!-- GDPR Tools -->
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
                <button type="button" class="button button-link-delete" id="isf-gdpr-delete" disabled>
                    <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                    <?php esc_html_e('Delete Data', 'formflow'); ?>
                </button>
            </div>
        </div>
    </div>

<?php elseif ($compliance_tab === 'audit') : ?>
    <!-- Audit Log -->
    <div class="isf-card">
        <h2><?php esc_html_e('Audit Log', 'formflow'); ?></h2>
        <p class="description">
            <?php esc_html_e('Track administrative actions and data changes for compliance purposes.', 'formflow'); ?>
        </p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 160px;"><?php esc_html_e('Date', 'formflow'); ?></th>
                    <th style="width: 120px;"><?php esc_html_e('User', 'formflow'); ?></th>
                    <th style="width: 150px;"><?php esc_html_e('Action', 'formflow'); ?></th>
                    <th><?php esc_html_e('Details', 'formflow'); ?></th>
                </tr>
            </thead>
            <tbody id="isf-audit-log-body">
                <tr>
                    <td colspan="4" style="text-align: center;">
                        <span class="spinner is-active" style="float: none;"></span>
                        <?php esc_html_e('Loading audit log...', 'formflow'); ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="tablenav bottom">
            <button type="button" class="button" id="isf-load-more-audit">
                <?php esc_html_e('Load More', 'formflow'); ?>
            </button>
            <button type="button" class="button" id="isf-export-audit">
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e('Export Audit Log', 'formflow'); ?>
            </button>
        </div>
    </div>

<?php elseif ($compliance_tab === 'retention') : ?>
    <!-- Data Retention -->
    <div class="isf-card">
        <h2><?php esc_html_e('Data Retention Policy', 'formflow'); ?></h2>
        <p class="description">
            <?php esc_html_e('Configure automatic data cleanup to maintain compliance with privacy regulations.', 'formflow'); ?>
        </p>

        <form method="post">
            <?php wp_nonce_field('isf_retention_settings'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="retention_submissions"><?php esc_html_e('Completed Submissions', 'formflow'); ?></label>
                    </th>
                    <td>
                        <select name="retention_submissions" id="retention_submissions">
                            <option value="0" <?php selected($settings['retention_submissions'] ?? 0, 0); ?>><?php esc_html_e('Keep indefinitely', 'formflow'); ?></option>
                            <option value="90" <?php selected($settings['retention_submissions'] ?? 0, 90); ?>><?php esc_html_e('90 days', 'formflow'); ?></option>
                            <option value="180" <?php selected($settings['retention_submissions'] ?? 0, 180); ?>><?php esc_html_e('180 days', 'formflow'); ?></option>
                            <option value="365" <?php selected($settings['retention_submissions'] ?? 0, 365); ?>><?php esc_html_e('1 year', 'formflow'); ?></option>
                            <option value="730" <?php selected($settings['retention_submissions'] ?? 0, 730); ?>><?php esc_html_e('2 years', 'formflow'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Automatically delete completed submissions older than this period.', 'formflow'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="retention_abandoned"><?php esc_html_e('Abandoned Submissions', 'formflow'); ?></label>
                    </th>
                    <td>
                        <select name="retention_abandoned" id="retention_abandoned">
                            <option value="7" <?php selected($settings['retention_abandoned'] ?? 30, 7); ?>><?php esc_html_e('7 days', 'formflow'); ?></option>
                            <option value="30" <?php selected($settings['retention_abandoned'] ?? 30, 30); ?>><?php esc_html_e('30 days', 'formflow'); ?></option>
                            <option value="90" <?php selected($settings['retention_abandoned'] ?? 30, 90); ?>><?php esc_html_e('90 days', 'formflow'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Delete incomplete/abandoned submissions after this period.', 'formflow'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="retention_logs"><?php esc_html_e('Activity Logs', 'formflow'); ?></label>
                    </th>
                    <td>
                        <select name="retention_logs" id="retention_logs">
                            <option value="30" <?php selected($settings['retention_logs'] ?? 90, 30); ?>><?php esc_html_e('30 days', 'formflow'); ?></option>
                            <option value="90" <?php selected($settings['retention_logs'] ?? 90, 90); ?>><?php esc_html_e('90 days', 'formflow'); ?></option>
                            <option value="180" <?php selected($settings['retention_logs'] ?? 90, 180); ?>><?php esc_html_e('180 days', 'formflow'); ?></option>
                            <option value="365" <?php selected($settings['retention_logs'] ?? 90, 365); ?>><?php esc_html_e('1 year', 'formflow'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Delete activity logs older than this period.', 'formflow'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="isf_save_retention" class="button button-primary" value="<?php esc_attr_e('Save Retention Policy', 'formflow'); ?>">
            </p>
        </form>
    </div>

    <div class="isf-card">
        <h2><?php esc_html_e('Manual Cleanup', 'formflow'); ?></h2>
        <p class="description">
            <?php esc_html_e('Run manual cleanup operations based on current retention settings.', 'formflow'); ?>
        </p>

        <p>
            <button type="button" class="button" id="isf-run-cleanup">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e('Run Cleanup Now', 'formflow'); ?>
            </button>
        </p>

        <div id="isf-cleanup-results" style="display: none; margin-top: 15px;"></div>
    </div>
<?php endif; ?>

<script>
jQuery(document).ready(function($) {
    // GDPR Search
    $('#isf-gdpr-search-form').on('submit', function(e) {
        e.preventDefault();
        var email = $('#gdpr_email').val();
        var account = $('#gdpr_account').val();

        $.post(isf_admin.ajax_url, {
            action: 'formflow_gdpr_search',
            nonce: isf_admin.nonce,
            email: email,
            account_number: account
        }, function(response) {
            if (response.success && response.data.count > 0) {
                $('#isf-gdpr-results-content').html('<p><?php echo esc_js(__('Found', 'formflow')); ?> ' + response.data.count + ' <?php echo esc_js(__('records', 'formflow')); ?></p>');
                $('#isf-gdpr-export, #isf-gdpr-delete').prop('disabled', false);
                $('#isf-gdpr-results').data('email', email).data('account', account);
            } else {
                $('#isf-gdpr-results-content').html('<p><?php echo esc_js(__('No records found.', 'formflow')); ?></p>');
                $('#isf-gdpr-export, #isf-gdpr-delete').prop('disabled', true);
            }
            $('#isf-gdpr-results').show();
        });
    });

    // Run cleanup
    $('#isf-run-cleanup').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).find('.dashicons').addClass('isf-spin');

        $.post(isf_admin.ajax_url, {
            action: 'formflow_run_retention_cleanup',
            nonce: isf_admin.nonce
        }, function(response) {
            $btn.prop('disabled', false).find('.dashicons').removeClass('isf-spin');
            if (response.success) {
                $('#isf-cleanup-results').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>').show();
            } else {
                $('#isf-cleanup-results').html('<div class="notice notice-error"><p>' + (response.data.message || '<?php echo esc_js(__('Cleanup failed.', 'formflow')); ?>') + '</p></div>').show();
            }
        });
    });

    // Load audit log
    <?php if ($compliance_tab === 'audit') : ?>
    loadAuditLog();

    function loadAuditLog(offset) {
        offset = offset || 0;
        $.post(isf_admin.ajax_url, {
            action: 'formflow_get_audit_log',
            nonce: isf_admin.nonce,
            offset: offset
        }, function(response) {
            if (response.success) {
                var html = '';
                if (response.data.logs.length === 0 && offset === 0) {
                    html = '<tr><td colspan="4"><?php echo esc_js(__('No audit log entries found.', 'formflow')); ?></td></tr>';
                } else {
                    response.data.logs.forEach(function(log) {
                        html += '<tr>';
                        html += '<td>' + log.created_at + '</td>';
                        html += '<td>' + (log.user_name || 'System') + '</td>';
                        html += '<td>' + log.action + '</td>';
                        html += '<td>' + (log.details || '—') + '</td>';
                        html += '</tr>';
                    });
                }
                if (offset === 0) {
                    $('#isf-audit-log-body').html(html);
                } else {
                    $('#isf-audit-log-body').append(html);
                }
            }
        });
    }

    $('#isf-load-more-audit').on('click', function() {
        var currentCount = $('#isf-audit-log-body tr').length;
        loadAuditLog(currentCount);
    });
    <?php endif; ?>
});
</script>

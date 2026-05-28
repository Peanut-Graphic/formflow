<?php
/**
 * Automation Tab: Scheduled Reports
 *
 * @package FormFlow
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="isf-reports-intro">
    <p><?php esc_html_e('Schedule automated email reports to receive regular summaries of form performance. You can also generate one-time reports using the report builder.', 'formflow'); ?></p>
</div>

<!-- Inner Tab Navigation -->
<div class="isf-inner-tabs">
    <a href="#scheduled" class="isf-inner-tab isf-inner-tab-active" data-tab="scheduled">
        <?php esc_html_e('Scheduled Reports', 'formflow'); ?>
    </a>
    <a href="#builder" class="isf-inner-tab" data-tab="builder">
        <?php esc_html_e('Report Builder', 'formflow'); ?>
    </a>
    <a href="#export" class="isf-inner-tab" data-tab="export">
        <?php esc_html_e('Export Data', 'formflow'); ?>
    </a>
</div>

<!-- Scheduled Reports Section -->
<div id="isf-inner-tab-scheduled" class="isf-inner-tab-content isf-inner-tab-active">
    <div class="isf-card">
        <div class="isf-card-header">
            <h2><?php esc_html_e('Scheduled Email Reports', 'formflow'); ?></h2>
            <button type="button" class="button button-primary" id="isf-add-scheduled-report">
                <span class="dashicons dashicons-plus-alt2"></span>
                <?php esc_html_e('Add New Report', 'formflow'); ?>
            </button>
        </div>

        <?php if (empty($scheduled_reports)) : ?>
            <div class="isf-empty-state">
                <span class="dashicons dashicons-email-alt"></span>
                <p><?php esc_html_e('No scheduled reports yet.', 'formflow'); ?></p>
                <p class="description"><?php esc_html_e('Create scheduled reports to receive automatic email summaries of your form performance.', 'formflow'); ?></p>
            </div>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Report Name', 'formflow'); ?></th>
                        <th><?php esc_html_e('Frequency', 'formflow'); ?></th>
                        <th><?php esc_html_e('Recipients', 'formflow'); ?></th>
                        <th><?php esc_html_e('Forms', 'formflow'); ?></th>
                        <th><?php esc_html_e('Last Sent', 'formflow'); ?></th>
                        <th><?php esc_html_e('Status', 'formflow'); ?></th>
                        <th style="width: 120px;"><?php esc_html_e('Actions', 'formflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scheduled_reports as $report) : ?>
                        <tr data-report-id="<?php echo esc_attr($report['id']); ?>">
                            <td><strong><?php echo esc_html($report['name']); ?></strong></td>
                            <td>
                                <?php
                                $frequencies = [
                                    'daily' => __('Daily', 'formflow'),
                                    'weekly' => __('Weekly', 'formflow'),
                                    'monthly' => __('Monthly', 'formflow'),
                                ];
                                echo esc_html($frequencies[$report['frequency']] ?? $report['frequency']);
                                ?>
                            </td>
                            <td>
                                <?php
                                $recipients = json_decode($report['recipients'], true) ?: [];
                                echo esc_html(implode(', ', $recipients));
                                ?>
                            </td>
                            <td>
                                <?php
                                if (empty($report['instance_id'])) {
                                    esc_html_e('All Forms', 'formflow');
                                } else {
                                    foreach ($instances as $inst) {
                                        if ($inst['id'] == $report['instance_id']) {
                                            echo esc_html($inst['name']);
                                            break;
                                        }
                                    }
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if (!empty($report['last_sent_at'])) {
                                    echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($report['last_sent_at'])));
                                } else {
                                    esc_html_e('Never', 'formflow');
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($report['is_active']) : ?>
                                    <span class="isf-status isf-status-active"><?php esc_html_e('Active', 'formflow'); ?></span>
                                <?php else : ?>
                                    <span class="isf-status isf-status-inactive"><?php esc_html_e('Paused', 'formflow'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small isf-send-report-now" data-id="<?php echo esc_attr($report['id']); ?>" title="<?php esc_attr_e('Send Now', 'formflow'); ?>">
                                    <span class="dashicons dashicons-email"></span>
                                </button>
                                <button type="button" class="button button-small isf-edit-scheduled-report" data-id="<?php echo esc_attr($report['id']); ?>" title="<?php esc_attr_e('Edit', 'formflow'); ?>">
                                    <span class="dashicons dashicons-edit"></span>
                                </button>
                                <button type="button" class="button button-small isf-delete-scheduled-report" data-id="<?php echo esc_attr($report['id']); ?>" title="<?php esc_attr_e('Delete', 'formflow'); ?>">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Report Builder Section -->
<div id="isf-inner-tab-builder" class="isf-inner-tab-content">
    <div class="isf-card">
        <h2><?php esc_html_e('Custom Report Builder', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Build a custom report by selecting the data you want to include.', 'formflow'); ?></p>

        <form id="isf-report-builder-form">
            <div class="isf-report-builder-grid">
                <div class="isf-report-builder-section">
                    <h3><?php esc_html_e('Date Range', 'formflow'); ?></h3>
                    <div class="isf-field-row">
                        <label for="report_date_from"><?php esc_html_e('From:', 'formflow'); ?></label>
                        <input type="date" id="report_date_from" name="date_from" value="<?php echo esc_attr(date('Y-m-d', strtotime('-30 days'))); ?>">
                    </div>
                    <div class="isf-field-row">
                        <label for="report_date_to"><?php esc_html_e('To:', 'formflow'); ?></label>
                        <input type="date" id="report_date_to" name="date_to" value="<?php echo esc_attr(date('Y-m-d')); ?>">
                    </div>
                </div>

                <div class="isf-report-builder-section">
                    <h3><?php esc_html_e('Form Selection', 'formflow'); ?></h3>
                    <div class="isf-field-row">
                        <label for="report_instance"><?php esc_html_e('Form:', 'formflow'); ?></label>
                        <select id="report_instance" name="instance_id">
                            <option value=""><?php esc_html_e('All Forms', 'formflow'); ?></option>
                            <?php foreach ($instances as $inst) : ?>
                                <option value="<?php echo esc_attr($inst['id']); ?>"><?php echo esc_html($inst['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="isf-field-row">
                        <label class="isf-checkbox-label">
                            <input type="checkbox" name="include_test" value="1">
                            <?php esc_html_e('Include test data', 'formflow'); ?>
                        </label>
                    </div>
                </div>

                <div class="isf-report-builder-section">
                    <h3><?php esc_html_e('Report Sections', 'formflow'); ?></h3>
                    <div class="isf-checkbox-group">
                        <label class="isf-checkbox-label">
                            <input type="checkbox" name="sections[]" value="summary" checked>
                            <?php esc_html_e('Summary Statistics', 'formflow'); ?>
                        </label>
                        <label class="isf-checkbox-label">
                            <input type="checkbox" name="sections[]" value="funnel" checked>
                            <?php esc_html_e('Funnel Analysis', 'formflow'); ?>
                        </label>
                        <label class="isf-checkbox-label">
                            <input type="checkbox" name="sections[]" value="daily">
                            <?php esc_html_e('Daily Breakdown', 'formflow'); ?>
                        </label>
                        <label class="isf-checkbox-label">
                            <input type="checkbox" name="sections[]" value="submissions">
                            <?php esc_html_e('Submission Details', 'formflow'); ?>
                        </label>
                    </div>
                </div>

                <div class="isf-report-builder-section">
                    <h3><?php esc_html_e('Output Format', 'formflow'); ?></h3>
                    <div class="isf-radio-group">
                        <label class="isf-radio-label">
                            <input type="radio" name="format" value="html" checked>
                            <?php esc_html_e('View in Browser', 'formflow'); ?>
                        </label>
                        <label class="isf-radio-label">
                            <input type="radio" name="format" value="csv">
                            <?php esc_html_e('Download CSV', 'formflow'); ?>
                        </label>
                    </div>
                </div>
            </div>

            <div class="isf-report-builder-actions">
                <button type="submit" class="button button-primary button-large">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <?php esc_html_e('Generate Report', 'formflow'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Export Data Section -->
<div id="isf-inner-tab-export" class="isf-inner-tab-content">
    <div class="isf-card">
        <h2><?php esc_html_e('Export Data', 'formflow'); ?></h2>
        <p class="description"><?php esc_html_e('Export your form data in CSV format for use in spreadsheets or other applications.', 'formflow'); ?></p>

        <div class="isf-export-options">
            <div class="isf-export-option">
                <h3><?php esc_html_e('Export Submissions', 'formflow'); ?></h3>
                <p><?php esc_html_e('Download all form submissions including customer details, dates, and statuses.', 'formflow'); ?></p>
                <button type="button" class="button" id="isf-export-all-submissions">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Export Submissions CSV', 'formflow'); ?>
                </button>
            </div>

            <div class="isf-export-option">
                <h3><?php esc_html_e('Export Analytics', 'formflow'); ?></h3>
                <p><?php esc_html_e('Download funnel analytics data for detailed analysis.', 'formflow'); ?></p>
                <button type="button" class="button" id="isf-export-analytics">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Export Analytics CSV', 'formflow'); ?>
                </button>
            </div>

            <div class="isf-export-option">
                <h3><?php esc_html_e('Export Activity Logs', 'formflow'); ?></h3>
                <p><?php esc_html_e('Download system logs for auditing or debugging purposes.', 'formflow'); ?></p>
                <button type="button" class="button" id="isf-export-logs">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Export Logs CSV', 'formflow'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Inner tab navigation
    $('.isf-inner-tab').on('click', function(e) {
        e.preventDefault();
        var tabId = $(this).data('tab');

        $('.isf-inner-tab').removeClass('isf-inner-tab-active');
        $(this).addClass('isf-inner-tab-active');

        $('.isf-inner-tab-content').removeClass('isf-inner-tab-active');
        $('#isf-inner-tab-' + tabId).addClass('isf-inner-tab-active');
    });

    // Scheduled report actions
    $(document).on('click', '.isf-send-report-now', function() {
        var $btn = $(this);
        $btn.find('.dashicons').addClass('isf-spin');

        $.post(isf_admin.ajax_url, {
            action: 'formflow_send_report_now',
            nonce: isf_admin.nonce,
            report_id: $btn.data('id')
        }, function(response) {
            $btn.find('.dashicons').removeClass('isf-spin');
            if (response.success) {
                alert('<?php echo esc_js(__('Report sent successfully!', 'formflow')); ?>');
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Failed to send report.', 'formflow')); ?>');
            }
        });
    });

    $(document).on('click', '.isf-delete-scheduled-report', function() {
        if (confirm('<?php echo esc_js(__('Are you sure you want to delete this report?', 'formflow')); ?>')) {
            var reportId = $(this).data('id');
            $.post(isf_admin.ajax_url, {
                action: 'formflow_delete_scheduled_report',
                nonce: isf_admin.nonce,
                report_id: reportId
            }, function(response) {
                if (response.success) {
                    $('tr[data-report-id="' + reportId + '"]').fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Failed to delete report.', 'formflow')); ?>');
                }
            });
        }
    });

    // Export buttons
    $('#isf-export-all-submissions').on('click', function() {
        window.location.href = isf_admin.ajax_url + '?action=isf_export_all_submissions&nonce=' + isf_admin.nonce;
    });

    $('#isf-export-analytics').on('click', function() {
        window.location.href = isf_admin.ajax_url + '?action=isf_export_analytics&nonce=' + isf_admin.nonce;
    });

    $('#isf-export-logs').on('click', function() {
        window.location.href = isf_admin.ajax_url + '?action=isf_export_logs&nonce=' + isf_admin.nonce;
    });
});
</script>

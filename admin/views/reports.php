<?php
/**
 * Reports Admin View
 *
 * Scheduled reports, custom report builder, and data export.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get scheduled reports
$scheduled_reports = $this->db->get_scheduled_reports();
?>

<div class="wrap isf-admin-wrap">
    <h1><?php esc_html_e('Reports', 'formflow'); ?></h1>

    <!-- Tab Navigation -->
    <nav class="nav-tab-wrapper">
        <a href="#scheduled" class="nav-tab nav-tab-active" data-tab="scheduled">
            <?php esc_html_e('Scheduled Reports', 'formflow'); ?>
        </a>
        <a href="#builder" class="nav-tab" data-tab="builder">
            <?php esc_html_e('Report Builder', 'formflow'); ?>
        </a>
        <a href="#export" class="nav-tab" data-tab="export">
            <?php esc_html_e('Export Data', 'formflow'); ?>
        </a>
    </nav>

    <!-- Scheduled Reports Tab -->
    <div id="tab-scheduled" class="isf-tab-content isf-tab-active">
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
                            <tr>
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
                                        $inst = $this->db->get_instance($report['instance_id']);
                                        echo esc_html($inst['name'] ?? 'Unknown');
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if ($report['last_sent_at']) {
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

    <!-- Report Builder Tab -->
    <div id="tab-builder" class="isf-tab-content">
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
                                <input type="checkbox" name="sections[]" value="devices">
                                <?php esc_html_e('Device & Browser Stats', 'formflow'); ?>
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
                            <label class="isf-radio-label">
                                <input type="radio" name="format" value="email">
                                <?php esc_html_e('Send via Email', 'formflow'); ?>
                            </label>
                        </div>
                        <div class="isf-field-row isf-email-field" style="display: none;">
                            <label for="report_email"><?php esc_html_e('Email to:', 'formflow'); ?></label>
                            <input type="email" id="report_email" name="email" placeholder="your@email.com">
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

        <!-- Report Preview Area -->
        <div id="isf-report-preview" class="isf-card" style="display: none;">
            <div class="isf-card-header">
                <h2><?php esc_html_e('Report Preview', 'formflow'); ?></h2>
                <div class="isf-card-actions">
                    <button type="button" class="button" id="isf-print-report">
                        <span class="dashicons dashicons-printer"></span>
                        <?php esc_html_e('Print', 'formflow'); ?>
                    </button>
                </div>
            </div>
            <div id="isf-report-content"></div>
        </div>
    </div>

    <!-- Export Data Tab -->
    <div id="tab-export" class="isf-tab-content">
        <div class="isf-card">
            <h2><?php esc_html_e('Export Submissions', 'formflow'); ?></h2>
            <p class="description"><?php esc_html_e('Export all submissions for a specific date range. Data is exported as CSV format.', 'formflow'); ?></p>

            <form id="isf-export-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="export_instance"><?php esc_html_e('Form', 'formflow'); ?></label>
                        </th>
                        <td>
                            <select id="export_instance" name="instance_id">
                                <option value=""><?php esc_html_e('All Forms', 'formflow'); ?></option>
                                <?php foreach ($instances as $inst) : ?>
                                    <option value="<?php echo esc_attr($inst['id']); ?>"><?php echo esc_html($inst['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="export_status"><?php esc_html_e('Status', 'formflow'); ?></label>
                        </th>
                        <td>
                            <select id="export_status" name="status">
                                <option value=""><?php esc_html_e('All Statuses', 'formflow'); ?></option>
                                <option value="completed"><?php esc_html_e('Completed', 'formflow'); ?></option>
                                <option value="in_progress"><?php esc_html_e('In Progress', 'formflow'); ?></option>
                                <option value="failed"><?php esc_html_e('Failed', 'formflow'); ?></option>
                                <option value="abandoned"><?php esc_html_e('Abandoned', 'formflow'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Date Range', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="date" id="export_date_from" name="date_from" value="<?php echo esc_attr(date('Y-m-d', strtotime('-30 days'))); ?>">
                            <span>&mdash;</span>
                            <input type="date" id="export_date_to" name="date_to" value="<?php echo esc_attr(date('Y-m-d')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Options', 'formflow'); ?></th>
                        <td>
                            <label class="isf-checkbox-label">
                                <input type="checkbox" name="include_test" value="1">
                                <?php esc_html_e('Include test data', 'formflow'); ?>
                            </label>
                            <br>
                            <label class="isf-checkbox-label">
                                <input type="checkbox" name="include_form_data" value="1" checked>
                                <?php esc_html_e('Include detailed form data (all fields)', 'formflow'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Export to CSV', 'formflow'); ?>
                    </button>
                    <span id="isf-export-count" class="description" style="margin-left: 15px;"></span>
                </p>
            </form>
        </div>

        <div class="isf-card">
            <h2><?php esc_html_e('Export Analytics Data', 'formflow'); ?></h2>
            <p class="description"><?php esc_html_e('Export analytics and funnel data for external analysis.', 'formflow'); ?></p>

            <form id="isf-export-analytics-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="analytics_instance"><?php esc_html_e('Form', 'formflow'); ?></label>
                        </th>
                        <td>
                            <select id="analytics_instance" name="instance_id">
                                <option value=""><?php esc_html_e('All Forms', 'formflow'); ?></option>
                                <?php foreach ($instances as $inst) : ?>
                                    <option value="<?php echo esc_attr($inst['id']); ?>"><?php echo esc_html($inst['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Date Range', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="date" id="analytics_date_from" name="date_from" value="<?php echo esc_attr(date('Y-m-d', strtotime('-30 days'))); ?>">
                            <span>&mdash;</span>
                            <input type="date" id="analytics_date_to" name="date_to" value="<?php echo esc_attr(date('Y-m-d')); ?>">
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Export Analytics CSV', 'formflow'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>
</div>

<!-- Scheduled Report Modal -->
<div id="isf-scheduled-report-modal" class="isf-modal" style="display: none;">
    <div class="isf-modal-content">
        <div class="isf-modal-header">
            <h2><?php esc_html_e('Scheduled Report', 'formflow'); ?></h2>
            <button type="button" class="isf-modal-close">&times;</button>
        </div>
        <div class="isf-modal-body">
            <form id="isf-scheduled-report-form">
                <input type="hidden" name="id" value="">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="sr_name"><?php esc_html_e('Report Name', 'formflow'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="sr_name" name="name" class="regular-text" required placeholder="<?php esc_attr_e('e.g., Weekly Performance Summary', 'formflow'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sr_frequency"><?php esc_html_e('Frequency', 'formflow'); ?></label>
                        </th>
                        <td>
                            <select id="sr_frequency" name="frequency" required>
                                <option value="daily"><?php esc_html_e('Daily', 'formflow'); ?></option>
                                <option value="weekly" selected><?php esc_html_e('Weekly (Mondays)', 'formflow'); ?></option>
                                <option value="monthly"><?php esc_html_e('Monthly (1st of month)', 'formflow'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sr_recipients"><?php esc_html_e('Recipients', 'formflow'); ?></label>
                        </th>
                        <td>
                            <textarea id="sr_recipients" name="recipients" class="large-text" rows="2" required placeholder="<?php esc_attr_e('email1@example.com, email2@example.com', 'formflow'); ?>"></textarea>
                            <p class="description"><?php esc_html_e('Separate multiple email addresses with commas.', 'formflow'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sr_instance"><?php esc_html_e('Form', 'formflow'); ?></label>
                        </th>
                        <td>
                            <select id="sr_instance" name="instance_id">
                                <option value=""><?php esc_html_e('All Forms', 'formflow'); ?></option>
                                <?php foreach ($instances as $inst) : ?>
                                    <option value="<?php echo esc_attr($inst['id']); ?>"><?php echo esc_html($inst['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Report Contents', 'formflow'); ?></th>
                        <td>
                            <label class="isf-checkbox-label">
                                <input type="checkbox" name="include_summary" value="1" checked>
                                <?php esc_html_e('Summary statistics', 'formflow'); ?>
                            </label>
                            <label class="isf-checkbox-label">
                                <input type="checkbox" name="include_funnel" value="1" checked>
                                <?php esc_html_e('Funnel analysis', 'formflow'); ?>
                            </label>
                            <label class="isf-checkbox-label">
                                <input type="checkbox" name="include_csv" value="1">
                                <?php esc_html_e('Attach CSV of submissions', 'formflow'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'formflow'); ?></th>
                        <td>
                            <label class="isf-checkbox-label">
                                <input type="checkbox" name="is_active" value="1" checked>
                                <?php esc_html_e('Active (report will be sent on schedule)', 'formflow'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        <div class="isf-modal-footer">
            <button type="button" class="button isf-modal-close"><?php esc_html_e('Cancel', 'formflow'); ?></button>
            <button type="button" class="button button-primary" id="isf-save-scheduled-report"><?php esc_html_e('Save Report', 'formflow'); ?></button>
        </div>
    </div>
</div>

<script>
(function($) {
    'use strict';

    $(document).ready(function() {
        // Tab switching
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            var tab = $(this).data('tab');

            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            $('.isf-tab-content').removeClass('isf-tab-active');
            $('#tab-' + tab).addClass('isf-tab-active');
        });

        // Show email field when email format is selected
        $('input[name="format"]').on('change', function() {
            if ($(this).val() === 'email') {
                $('.isf-email-field').slideDown();
            } else {
                $('.isf-email-field').slideUp();
            }
        });

        // Add scheduled report
        $('#isf-add-scheduled-report').on('click', function() {
            $('#isf-scheduled-report-form')[0].reset();
            $('#isf-scheduled-report-form input[name="id"]').val('');
            $('#isf-scheduled-report-modal').fadeIn(200);
        });

        // Edit scheduled report
        $('.isf-edit-scheduled-report').on('click', function() {
            var id = $(this).data('id');
            loadScheduledReport(id);
        });

        // Delete scheduled report
        $('.isf-delete-scheduled-report').on('click', function() {
            if (!confirm('<?php echo esc_js(__('Delete this scheduled report?', 'formflow')); ?>')) {
                return;
            }

            var $btn = $(this);
            var id = $btn.data('id');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'formflow_delete_scheduled_report',
                    nonce: isf_admin.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                    } else {
                        alert(response.data.message || 'Error deleting report.');
                    }
                }
            });
        });

        // Send report now
        $('.isf-send-report-now').on('click', function() {
            var $btn = $(this);
            var id = $btn.data('id');

            $btn.prop('disabled', true).find('.dashicons').addClass('isf-spin');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'formflow_send_report_now',
                    nonce: isf_admin.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        alert('<?php echo esc_js(__('Report sent successfully!', 'formflow')); ?>');
                    } else {
                        alert(response.data.message || 'Error sending report.');
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('isf-spin');
                }
            });
        });

        // Save scheduled report
        $('#isf-save-scheduled-report').on('click', function() {
            var $btn = $(this);
            var $form = $('#isf-scheduled-report-form');

            if (!$form[0].checkValidity()) {
                $form[0].reportValidity();
                return;
            }

            $btn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'formflow')); ?>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'formflow_save_scheduled_report',
                    nonce: isf_admin.nonce,
                    id: $form.find('input[name="id"]').val(),
                    name: $form.find('input[name="name"]').val(),
                    frequency: $form.find('select[name="frequency"]').val(),
                    recipients: $form.find('textarea[name="recipients"]').val(),
                    instance_id: $form.find('select[name="instance_id"]').val(),
                    include_summary: $form.find('input[name="include_summary"]').is(':checked') ? 1 : 0,
                    include_funnel: $form.find('input[name="include_funnel"]').is(':checked') ? 1 : 0,
                    include_csv: $form.find('input[name="include_csv"]').is(':checked') ? 1 : 0,
                    is_active: $form.find('input[name="is_active"]').is(':checked') ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || 'Error saving report.');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Save Report', 'formflow')); ?>');
                    }
                },
                error: function() {
                    alert('Error saving report.');
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Save Report', 'formflow')); ?>');
                }
            });
        });

        function loadScheduledReport(id) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'formflow_get_scheduled_report',
                    nonce: isf_admin.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        var report = response.data.report;
                        var $form = $('#isf-scheduled-report-form');

                        $form.find('input[name="id"]').val(report.id);
                        $form.find('input[name="name"]').val(report.name);
                        $form.find('select[name="frequency"]').val(report.frequency);
                        $form.find('textarea[name="recipients"]').val((report.recipients || []).join(', '));
                        $form.find('select[name="instance_id"]').val(report.instance_id || '');

                        var settings = report.settings || {};
                        $form.find('input[name="include_summary"]').prop('checked', settings.include_summary !== false);
                        $form.find('input[name="include_funnel"]').prop('checked', settings.include_funnel !== false);
                        $form.find('input[name="include_csv"]').prop('checked', settings.include_csv === true);
                        $form.find('input[name="is_active"]').prop('checked', report.is_active == 1);

                        $('#isf-scheduled-report-modal').fadeIn(200);
                    }
                }
            });
        }

        // Close modal
        $('.isf-modal-close, .isf-modal').on('click', function(e) {
            if (e.target === this || $(this).hasClass('isf-modal-close')) {
                $('#isf-scheduled-report-modal').fadeOut(200);
            }
        });

        $('.isf-modal-content').on('click', function(e) {
            e.stopPropagation();
        });

        // Report Builder form
        $('#isf-report-builder-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var format = $form.find('input[name="format"]:checked').val();

            $btn.prop('disabled', true).find('.dashicons').addClass('isf-spin');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'formflow_generate_custom_report',
                    nonce: isf_admin.nonce,
                    date_from: $form.find('input[name="date_from"]').val(),
                    date_to: $form.find('input[name="date_to"]').val(),
                    instance_id: $form.find('select[name="instance_id"]').val(),
                    include_test: $form.find('input[name="include_test"]').is(':checked') ? 1 : 0,
                    sections: $form.find('input[name="sections[]"]:checked').map(function() { return this.value; }).get(),
                    format: format,
                    email: $form.find('input[name="email"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        if (format === 'html') {
                            $('#isf-report-content').html(response.data.html);
                            $('#isf-report-preview').slideDown();
                            $('html, body').animate({ scrollTop: $('#isf-report-preview').offset().top - 50 }, 500);
                        } else if (format === 'csv') {
                            downloadCSV(response.data.csv, response.data.filename);
                        } else if (format === 'email') {
                            alert(response.data.message || '<?php echo esc_js(__('Report sent successfully!', 'formflow')); ?>');
                        }
                    } else {
                        alert(response.data.message || 'Error generating report.');
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('isf-spin');
                }
            });
        });

        // Export submissions form
        $('#isf-export-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');

            $btn.prop('disabled', true).find('.dashicons').addClass('isf-spin');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'formflow_export_submissions_csv',
                    nonce: isf_admin.nonce,
                    instance_id: $form.find('select[name="instance_id"]').val(),
                    status: $form.find('select[name="status"]').val(),
                    date_from: $form.find('input[name="date_from"]').val(),
                    date_to: $form.find('input[name="date_to"]').val(),
                    include_test: $form.find('input[name="include_test"]').is(':checked') ? 1 : 0,
                    include_form_data: $form.find('input[name="include_form_data"]').is(':checked') ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        downloadCSV(response.data.csv, response.data.filename);
                    } else {
                        alert(response.data.message || 'Error exporting data.');
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('isf-spin');
                }
            });
        });

        // Export analytics form
        $('#isf-export-analytics-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');

            $btn.prop('disabled', true).find('.dashicons').addClass('isf-spin');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'formflow_export_analytics_csv',
                    nonce: isf_admin.nonce,
                    instance_id: $form.find('select[name="instance_id"]').val(),
                    date_from: $form.find('input[name="date_from"]').val(),
                    date_to: $form.find('input[name="date_to"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        downloadCSV(response.data.csv, response.data.filename);
                    } else {
                        alert(response.data.message || 'Error exporting analytics.');
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('isf-spin');
                }
            });
        });

        function downloadCSV(csvContent, filename) {
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            var url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Print report
        $('#isf-print-report').on('click', function() {
            var content = document.getElementById('isf-report-content').innerHTML;
            var printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>Report</title>');
            printWindow.document.write('<style>body{font-family:Arial,sans-serif;padding:20px;} table{border-collapse:collapse;width:100%;margin:15px 0;} th,td{border:1px solid #ddd;padding:8px;text-align:left;} th{background:#f5f5f5;}</style>');
            printWindow.document.write('</head><body>');
            printWindow.document.write(content);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.print();
        });
    });
})(jQuery);
</script>

<style>
.isf-tab-content { display: none; margin-top: 20px; }
.isf-tab-content.isf-tab-active { display: block; }

.isf-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #dcdcde;
}

.isf-card-header h2 { margin: 0; }

.isf-empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #646970;
}

.isf-empty-state .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    color: #c3c4c7;
    margin-bottom: 15px;
}

.isf-report-builder-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

@media (max-width: 782px) {
    .isf-report-builder-grid { grid-template-columns: 1fr; }
}

.isf-report-builder-section {
    background: #f6f7f7;
    padding: 20px;
    border-radius: 4px;
}

.isf-report-builder-section h3 {
    margin: 0 0 15px;
    font-size: 14px;
    color: #1d2327;
}

.isf-field-row {
    margin-bottom: 12px;
}

.isf-field-row:last-child { margin-bottom: 0; }

.isf-field-row label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.isf-field-row input[type="date"],
.isf-field-row select {
    width: 100%;
}

.isf-checkbox-group,
.isf-radio-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.isf-checkbox-label,
.isf-radio-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.isf-report-builder-actions {
    margin-top: 25px;
    text-align: center;
}

.isf-report-builder-actions .button-large {
    padding: 8px 30px;
    font-size: 14px;
}

.isf-report-builder-actions .dashicons {
    margin-right: 5px;
}

#isf-report-preview {
    margin-top: 20px;
}

.isf-card-actions {
    display: flex;
    gap: 10px;
}

.isf-spin {
    animation: isf-spin 1s linear infinite;
}

@keyframes isf-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Report content styles */
.isf-report-section {
    margin-bottom: 30px;
}

.isf-report-section h3 {
    margin: 0 0 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #2271b1;
}

.isf-report-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.isf-report-stat {
    background: #f0f6fc;
    padding: 15px;
    border-radius: 4px;
    text-align: center;
}

.isf-report-stat-value {
    display: block;
    font-size: 24px;
    font-weight: 600;
    color: #1d2327;
}

.isf-report-stat-label {
    display: block;
    font-size: 12px;
    color: #646970;
    margin-top: 5px;
}
</style>

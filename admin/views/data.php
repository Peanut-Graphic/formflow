<?php
/**
 * Combined Data View
 *
 * Displays Submissions, Analytics, and Activity Logs in a tabbed interface.
 *
 * @package FormFlow
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include breadcrumbs partial
require_once ISF_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';

// Base URL for tabs (preserves filters)
$base_url = admin_url('admin.php?page=isf-data');
?>

<div class="wrap isf-admin-wrap">
    <?php isf_breadcrumbs(['Dashboard' => 'isf-dashboard'], __('Data & Analytics', 'formflow')); ?>

    <h1><?php esc_html_e('Data & Analytics', 'formflow'); ?></h1>

    <!-- Tab Navigation -->
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url(add_query_arg('tab', 'submissions', $base_url)); ?>"
           class="nav-tab <?php echo ($tab === 'submissions') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-list-view"></span>
            <?php esc_html_e('Submissions', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'analytics', $base_url)); ?>"
           class="nav-tab <?php echo ($tab === 'analytics') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-chart-bar"></span>
            <?php esc_html_e('Analytics', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'activity', $base_url)); ?>"
           class="nav-tab <?php echo ($tab === 'activity') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-media-text"></span>
            <?php esc_html_e('Activity Logs', 'formflow'); ?>
        </a>
    </nav>

    <div class="isf-tab-content">
        <?php if ($tab === 'submissions') : ?>
            <!-- Submissions Tab -->
            <?php include ISF_PLUGIN_DIR . 'admin/views/tabs/data-submissions.php'; ?>

        <?php elseif ($tab === 'analytics') : ?>
            <!-- Analytics Tab -->
            <?php include ISF_PLUGIN_DIR . 'admin/views/tabs/data-analytics.php'; ?>

        <?php elseif ($tab === 'activity') : ?>
            <!-- Activity Logs Tab -->
            <?php include ISF_PLUGIN_DIR . 'admin/views/tabs/data-activity.php'; ?>

        <?php endif; ?>
    </div>
</div>

<!-- Submission Details Modal -->
<div id="isf-submission-modal" class="isf-modal" style="display:none;">
    <div class="isf-modal-content isf-modal-large">
        <div class="isf-modal-header">
            <h2><?php esc_html_e('Submission Details', 'formflow'); ?></h2>
            <button type="button" class="isf-modal-close">&times;</button>
        </div>
        <div class="isf-modal-body" id="isf-submission-content">
            <div class="isf-loading">
                <span class="spinner is-active"></span>
                <?php esc_html_e('Loading...', 'formflow'); ?>
            </div>
        </div>
    </div>
</div>

<!-- Log Details Modal -->
<div id="isf-details-modal" class="isf-modal" style="display:none;">
    <div class="isf-modal-content">
        <span class="isf-modal-close">&times;</span>
        <h3><?php esc_html_e('Log Details', 'formflow'); ?></h3>
        <pre id="isf-details-content"></pre>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // HTML escape function to prevent XSS
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // View submission details modal
    $('.isf-view-submission').on('click', function() {
        var submissionId = $(this).data('id');
        $('#isf-submission-content').html('<div class="isf-loading"><span class="spinner is-active"></span> <?php echo esc_js(__('Loading...', 'formflow')); ?></div>');
        $('#isf-submission-modal').show();

        $.ajax({
            url: isf_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_get_submission_details',
                nonce: isf_admin.nonce,
                submission_id: submissionId
            },
            success: function(response) {
                if (response.success) {
                    renderSubmissionDetails(response.data);
                } else {
                    $('#isf-submission-content').html('<div class="isf-error">' + (response.data.message || '<?php echo esc_js(__('Error loading submission.', 'formflow')); ?>') + '</div>');
                }
            },
            error: function() {
                $('#isf-submission-content').html('<div class="isf-error"><?php echo esc_js(__('Error loading submission.', 'formflow')); ?></div>');
            }
        });
    });

    function renderSubmissionDetails(data) {
        var s = data.submission;
        var fd = data.form_data;

        var html = '<div class="isf-submission-details">';

        // Header with status
        html += '<div class="isf-detail-header">';
        html += '<span class="isf-detail-id">#' + s.id + '</span>';
        html += '<span class="isf-status isf-status-' + s.status + '">' + s.status.replace('_', ' ') + '</span>';
        if (s.is_test) {
            html += '<span class="isf-status isf-status-test"><?php echo esc_js(__('Test', 'formflow')); ?></span>';
        }
        html += '</div>';

        // Basic Info Section
        html += '<div class="isf-detail-section">';
        html += '<h4><?php echo esc_js(__('Basic Information', 'formflow')); ?></h4>';
        html += '<table class="isf-detail-table">';
        html += '<tr><th><?php echo esc_js(__('Form Instance', 'formflow')); ?></th><td>' + (s.instance_name || '—') + '</td></tr>';
        html += '<tr><th><?php echo esc_js(__('Session ID', 'formflow')); ?></th><td><code>' + s.session_id + '</code></td></tr>';
        html += '<tr><th><?php echo esc_js(__('Account Number', 'formflow')); ?></th><td>' + (s.account_number || '—') + '</td></tr>';
        html += '<tr><th><?php echo esc_js(__('Current Step', 'formflow')); ?></th><td>' + s.step + '/5</td></tr>';
        html += '<tr><th><?php echo esc_js(__('Created', 'formflow')); ?></th><td>' + s.created_at + '</td></tr>';
        if (s.completed_at) {
            html += '<tr><th><?php echo esc_js(__('Completed', 'formflow')); ?></th><td>' + s.completed_at + '</td></tr>';
        }
        html += '</table>';
        html += '</div>';

        // Customer Info Section
        if (fd.first_name || fd.last_name || fd.email) {
            html += '<div class="isf-detail-section">';
            html += '<h4><?php echo esc_js(__('Customer Information', 'formflow')); ?></h4>';
            html += '<table class="isf-detail-table">';
            if (fd.first_name || fd.last_name) {
                html += '<tr><th><?php echo esc_js(__('Name', 'formflow')); ?></th><td>' + (fd.first_name || '') + ' ' + (fd.last_name || '') + '</td></tr>';
            }
            if (fd.email) {
                html += '<tr><th><?php echo esc_js(__('Email', 'formflow')); ?></th><td><a href="mailto:' + fd.email + '">' + fd.email + '</a></td></tr>';
            }
            if (fd.phone) {
                html += '<tr><th><?php echo esc_js(__('Phone', 'formflow')); ?></th><td>' + fd.phone + '</td></tr>';
            }
            if (fd.street || fd.city || fd.state) {
                var address = [fd.street, fd.city, fd.state, fd.zip].filter(Boolean).join(', ');
                html += '<tr><th><?php echo esc_js(__('Address', 'formflow')); ?></th><td>' + address + '</td></tr>';
            }
            html += '</table>';
            html += '</div>';
        }

        // Device & Program Section
        if (fd.device_type || fd.promo_code) {
            html += '<div class="isf-detail-section">';
            html += '<h4><?php echo esc_js(__('Program Details', 'formflow')); ?></h4>';
            html += '<table class="isf-detail-table">';
            if (fd.device_type) {
                var deviceLabel = fd.device_type === 'thermostat' ? '<?php echo esc_js(__('Smart Thermostat', 'formflow')); ?>' : '<?php echo esc_js(__('Outdoor Switch (DCU)', 'formflow')); ?>';
                html += '<tr><th><?php echo esc_js(__('Device Type', 'formflow')); ?></th><td>' + deviceLabel + '</td></tr>';
            }
            if (fd.promo_code) {
                html += '<tr><th><?php echo esc_js(__('Promo Code', 'formflow')); ?></th><td><code>' + fd.promo_code + '</code></td></tr>';
            }
            if (fd.confirmation_number) {
                html += '<tr><th><?php echo esc_js(__('Confirmation #', 'formflow')); ?></th><td><strong>' + fd.confirmation_number + '</strong></td></tr>';
            }
            html += '</table>';
            html += '</div>';
        }

        // Schedule Section
        if (fd.schedule_date || fd.schedule_time) {
            html += '<div class="isf-detail-section">';
            html += '<h4><?php echo esc_js(__('Installation Appointment', 'formflow')); ?></h4>';
            html += '<table class="isf-detail-table">';
            if (fd.schedule_date) {
                html += '<tr><th><?php echo esc_js(__('Date', 'formflow')); ?></th><td>' + fd.schedule_date + '</td></tr>';
            }
            if (fd.schedule_time || fd.schedule_time_display) {
                html += '<tr><th><?php echo esc_js(__('Time', 'formflow')); ?></th><td>' + (fd.schedule_time_display || fd.schedule_time) + '</td></tr>';
            }
            html += '</table>';
            html += '</div>';
        }

        // Technical Section
        html += '<div class="isf-detail-section">';
        html += '<h4><?php echo esc_js(__('Technical Details', 'formflow')); ?></h4>';
        html += '<table class="isf-detail-table">';
        html += '<tr><th><?php echo esc_js(__('IP Address', 'formflow')); ?></th><td>' + escapeHtml(s.ip_address || '—') + '</td></tr>';
        if (s.user_agent) {
            html += '<tr><th><?php echo esc_js(__('User Agent', 'formflow')); ?></th><td class="isf-user-agent">' + escapeHtml(s.user_agent) + '</td></tr>';
        }
        html += '</table>';
        html += '</div>';

        // Raw Form Data (collapsible)
        html += '<div class="isf-detail-section isf-collapsible">';
        html += '<h4 class="isf-collapsible-header"><?php echo esc_js(__('Raw Form Data', 'formflow')); ?> <span class="dashicons dashicons-arrow-down-alt2"></span></h4>';
        html += '<div class="isf-collapsible-content" style="display:none;">';
        html += '<pre class="isf-raw-data">' + JSON.stringify(fd, null, 2) + '</pre>';
        html += '</div>';
        html += '</div>';

        html += '</div>';

        $('#isf-submission-content').html(html);

        // Handle collapsible sections
        $('.isf-collapsible-header').off('click').on('click', function() {
            $(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
            $(this).next('.isf-collapsible-content').slideToggle(200);
        });
    }

    // View log details modal
    $('.isf-view-details').on('click', function(e) {
        e.preventDefault();
        var details = $(this).data('details');
        $('#isf-details-content').text(JSON.stringify(details, null, 2));
        $('#isf-details-modal').show();
    });

    // Close modals
    $('.isf-modal-close').on('click', function() {
        $(this).closest('.isf-modal').hide();
    });

    $(window).on('click', function(e) {
        if ($(e.target).hasClass('isf-modal')) {
            $(e.target).hide();
        }
    });

    // Export CSV
    $('#isf-export-csv').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).find('.dashicons').addClass('isf-spin');

        var params = new URLSearchParams(window.location.search);

        $.ajax({
            url: isf_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'formflow_export_submissions_csv',
                nonce: isf_admin.nonce,
                instance_id: params.get('instance_id') || '',
                status: params.get('status') || '',
                search: params.get('search') || '',
                date_from: params.get('date_from') || '',
                date_to: params.get('date_to') || ''
            },
            success: function(response) {
                if (response.success) {
                    var blob = new Blob([response.data.csv], { type: 'text/csv;charset=utf-8;' });
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = response.data.filename;
                    link.click();
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Export failed.', 'formflow')); ?>');
                }
                $btn.prop('disabled', false).find('.dashicons').removeClass('isf-spin');
            },
            error: function() {
                alert('<?php echo esc_js(__('Export failed.', 'formflow')); ?>');
                $btn.prop('disabled', false).find('.dashicons').removeClass('isf-spin');
            }
        });
    });

    // Bulk Actions - Select All
    $('#isf-select-all').on('change', function() {
        $('.isf-row-cb').prop('checked', $(this).prop('checked'));
        updateBulkCount();
    });

    // Bulk Actions - Individual checkbox
    $(document).on('change', '.isf-row-cb', function() {
        updateBulkCount();
        var allChecked = $('.isf-row-cb:not(:checked)').length === 0;
        $('#isf-select-all').prop('checked', allChecked);
    });

    function updateBulkCount() {
        var count = $('.isf-row-cb:checked').length;
        if (count > 0) {
            $('#isf-bulk-count').show().find('.count').text(count);
        } else {
            $('#isf-bulk-count').hide();
        }
    }

    // Apply Bulk Action
    $('#isf-apply-bulk').on('click', function() {
        var action = $('#isf-bulk-action').val();
        var ids = $('.isf-row-cb:checked').map(function() {
            return $(this).val();
        }).get();

        if (!action) {
            alert('<?php echo esc_js(__('Please select a bulk action.', 'formflow')); ?>');
            return;
        }

        if (ids.length === 0) {
            alert('<?php echo esc_js(__('Please select at least one item.', 'formflow')); ?>');
            return;
        }

        if (action === 'delete') {
            if (!confirm('<?php echo esc_js(__('Are you sure you want to delete the selected items? This cannot be undone.', 'formflow')); ?>')) {
                return;
            }
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'formflow')); ?>');

        var currentTab = '<?php echo esc_js($tab); ?>';
        var isSubmissions = currentTab === 'submissions';
        var ajaxAction = isSubmissions ? 'isf_bulk_submissions_action' : 'isf_bulk_logs_action';
        var idKey = isSubmissions ? 'submission_ids' : 'log_ids';

        var data = {
            action: ajaxAction,
            nonce: isf_admin.nonce,
            bulk_action: action
        };
        data[idKey] = ids;

        $.ajax({
            url: isf_admin.ajax_url,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Action failed.', 'formflow')); ?>');
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Apply', 'formflow')); ?>');
                }
            },
            error: function() {
                alert('<?php echo esc_js(__('Action failed.', 'formflow')); ?>');
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Apply', 'formflow')); ?>');
            }
        });
    });
});
</script>

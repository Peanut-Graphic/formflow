<?php
/**
 * Tools Tab: Diagnostics
 *
 * @package FormFlow
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<p class="description" style="font-size: 14px;">
    <?php esc_html_e('Run comprehensive health checks to verify all plugin systems are functioning correctly.', 'formflow'); ?>
</p>

<!-- Quick Health Status -->
<div class="isf-diagnostics-quick-status" id="isf-quick-status">
    <div class="isf-quick-status-loading">
        <span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
        <?php esc_html_e('Checking system health...', 'formflow'); ?>
    </div>
</div>

<!-- Test Controls -->
<div class="isf-card" style="margin-top: 20px;">
    <h2 style="margin-top: 0;"><?php esc_html_e('Run Full Diagnostics', 'formflow'); ?></h2>

    <table class="form-table">
        <tr>
            <th scope="row">
                <label for="isf-test-instance"><?php esc_html_e('Test Instance', 'formflow'); ?></label>
            </th>
            <td>
                <select id="isf-test-instance" style="min-width: 300px;">
                    <option value=""><?php esc_html_e('-- No instance (skip API tests) --', 'formflow'); ?></option>
                    <?php foreach ($instances as $instance): ?>
                        <option value="<?php echo esc_attr($instance['id']); ?>">
                            <?php echo esc_html($instance['name']); ?>
                            <?php if ($instance['settings']['demo_mode'] ?? false): ?>
                                (Demo Mode)
                            <?php endif; ?>
                            <?php if (!$instance['is_active']): ?>
                                (Inactive)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php esc_html_e('Select a form instance to include API connectivity tests.', 'formflow'); ?>
                </p>
            </td>
        </tr>
    </table>

    <p>
        <button type="button" id="isf-run-diagnostics" class="button button-primary button-hero">
            <span class="dashicons dashicons-admin-tools" style="margin-top: 4px;"></span>
            <?php esc_html_e('Run Full Diagnostics', 'formflow'); ?>
        </button>
    </p>
</div>

<!-- Results Container -->
<div id="isf-diagnostics-results" style="display: none;">
    <h2><?php esc_html_e('Diagnostic Results', 'formflow'); ?></h2>

    <!-- Summary -->
    <div id="isf-results-summary" class="isf-results-summary" style="margin-bottom: 20px;"></div>

    <!-- Detailed Results -->
    <div id="isf-results-details" class="isf-card"></div>
</div>

<!-- Debug Tools -->
<div class="isf-card" style="margin-top: 20px;">
    <h2><?php esc_html_e('Debug Tools', 'formflow'); ?></h2>

    <table class="form-table">
        <tr>
            <th scope="row"><?php esc_html_e('Rate Limiting', 'formflow'); ?></th>
            <td>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('isf_clear_rate_limits'); ?>
                    <button type="submit" name="isf_clear_rate_limits" class="button">
                        <?php esc_html_e('Clear All Rate Limits', 'formflow'); ?>
                    </button>
                </form>
                <p class="description">
                    <?php esc_html_e('Clear all IP-based rate limiting to resolve "Too many requests" errors.', 'formflow'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Transient Cache', 'formflow'); ?></th>
            <td>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('isf_clear_transients'); ?>
                    <button type="submit" name="isf_clear_transients" class="button">
                        <?php esc_html_e('Clear Plugin Transients', 'formflow'); ?>
                    </button>
                </form>
                <p class="description">
                    <?php esc_html_e('Clear cached data like API health status and analytics summaries.', 'formflow'); ?>
                </p>
            </td>
        </tr>
    </table>
</div>

<script>
jQuery(document).ready(function($) {
    // Quick health check on page load
    $.post(isf_admin.ajax_url, {
        action: 'formflow_quick_health_check',
        nonce: isf_admin.nonce
    }, function(response) {
        if (response.success) {
            var status = response.data;
            var statusClass = status.overall === 'healthy' ? 'isf-status-healthy' : (status.overall === 'warning' ? 'isf-status-warning' : 'isf-status-error');
            var statusIcon = status.overall === 'healthy' ? 'yes-alt' : (status.overall === 'warning' ? 'warning' : 'dismiss');
            var statusText = status.overall === 'healthy' ? '<?php echo esc_js(__('All Systems Operational', 'formflow')); ?>' :
                           (status.overall === 'warning' ? '<?php echo esc_js(__('Some Issues Detected', 'formflow')); ?>' :
                           '<?php echo esc_js(__('Critical Issues Found', 'formflow')); ?>');

            var html = '<div class="isf-quick-status-result ' + statusClass + '">';
            html += '<span class="dashicons dashicons-' + statusIcon + '"></span>';
            html += '<strong>' + statusText + '</strong>';
            if (status.issues && status.issues.length > 0) {
                html += '<p style="margin: 5px 0 0;">' + status.issues.join(', ') + '</p>';
            }
            html += '</div>';

            $('#isf-quick-status').html(html);
        }
    });

    // Run full diagnostics
    $('#isf-run-diagnostics').on('click', function() {
        var $btn = $(this);
        var instanceId = $('#isf-test-instance').val();

        $btn.prop('disabled', true).find('.dashicons').addClass('isf-spin');

        $.post(isf_admin.ajax_url, {
            action: 'formflow_run_diagnostics',
            nonce: isf_admin.nonce,
            instance_id: instanceId
        }, function(response) {
            $btn.prop('disabled', false).find('.dashicons').removeClass('isf-spin');

            if (response.success) {
                renderDiagnosticResults(response.data);
                $('#isf-diagnostics-results').show();
            } else {
                alert(response.data.message || '<?php echo esc_js(__('Diagnostics failed.', 'formflow')); ?>');
            }
        });
    });

    function renderDiagnosticResults(data) {
        // Use summary from backend or calculate from tests
        var passed = data.summary ? data.summary.passed : 0;
        var failed = data.summary ? data.summary.failed : 0;
        var warnings = data.summary ? data.summary.warnings : 0;

        var summary = '<div class="isf-results-grid">';
        summary += '<div class="isf-result-stat isf-stat-pass"><span class="count" style="color: #46b450; font-size: 48px; font-weight: bold;">' + passed + '</span><span class="label"><?php echo esc_js(__('Passed', 'formflow')); ?></span></div>';
        summary += '<div class="isf-result-stat isf-stat-fail"><span class="count" style="color: #dc3232; font-size: 48px; font-weight: bold;">' + failed + '</span><span class="label"><?php echo esc_js(__('Failed', 'formflow')); ?></span></div>';
        if (warnings > 0) {
            summary += '<div class="isf-result-stat isf-stat-warning"><span class="count" style="color: #ffb900; font-size: 48px; font-weight: bold;">' + warnings + '</span><span class="label"><?php echo esc_js(__('Warnings', 'formflow')); ?></span></div>';
        }
        summary += '</div>';

        $('#isf-results-summary').html(summary);

        // Group tests by category
        var tests = data.tests || [];
        var grouped = {};
        for (var i = 0; i < tests.length; i++) {
            var test = tests[i];
            var cat = test.category || 'General';
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(test);
        }

        var details = '';
        for (var category in grouped) {
            details += '<h3>' + category + '</h3>';
            details += '<table class="widefat striped">';
            details += '<thead><tr><th><?php echo esc_js(__('Test', 'formflow')); ?></th><th><?php echo esc_js(__('Status', 'formflow')); ?></th><th><?php echo esc_js(__('Message', 'formflow')); ?></th></tr></thead>';
            details += '<tbody>';

            var catTests = grouped[category];
            for (var i = 0; i < catTests.length; i++) {
                var test = catTests[i];
                var icon, cls;
                if (test.status === 'passed') {
                    icon = 'yes';
                    cls = 'isf-status-pass';
                } else if (test.status === 'warning') {
                    icon = 'warning';
                    cls = 'isf-status-warning';
                } else {
                    icon = 'no';
                    cls = 'isf-status-fail';
                }
                details += '<tr>';
                details += '<td>' + test.name + '</td>';
                details += '<td><span class="dashicons dashicons-' + icon + ' ' + cls + '" style="color: ' + (test.status === 'passed' ? '#46b450' : (test.status === 'warning' ? '#ffb900' : '#dc3232')) + ';"></span></td>';
                details += '<td>' + (test.message || '') + '</td>';
                details += '</tr>';
            }

            details += '</tbody></table>';
        }

        $('#isf-results-details').html(details);
    }
});
</script>

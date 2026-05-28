<?php
/**
 * Diagnostics Admin Page View
 *
 * Provides system health checks and end-to-end testing.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap isf-admin-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-heart" style="font-size: 30px; width: 30px; height: 30px; margin-right: 10px; vertical-align: middle;"></span>
        <?php esc_html_e('System Diagnostics', 'formflow'); ?>
    </h1>

    <p class="description" style="margin-top: 10px; font-size: 14px;">
        <?php esc_html_e('Run comprehensive health checks to verify all plugin systems are functioning correctly.', 'formflow'); ?>
    </p>

    <hr class="wp-header-end">

    <!-- Quick Health Status -->
    <div class="isf-diagnostics-quick-status" id="isf-quick-status">
        <div class="isf-quick-status-loading">
            <span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
            <?php esc_html_e('Checking system health...', 'formflow'); ?>
        </div>
    </div>

    <!-- Test Controls -->
    <div class="isf-diagnostics-controls" style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
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
                        <?php esc_html_e('Select a form instance to include API connectivity tests. Demo mode instances will use mock API responses.', 'formflow'); ?>
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
        <div id="isf-results-summary" class="isf-results-summary" style="margin-bottom: 20px;">
            <!-- Filled by JavaScript -->
        </div>

        <!-- Detailed Results -->
        <div id="isf-results-details" class="isf-results-details">
            <!-- Filled by JavaScript -->
        </div>

        <!-- Export Options -->
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ccd0d4;">
            <button type="button" id="isf-export-results" class="button">
                <span class="dashicons dashicons-download" style="margin-top: 4px;"></span>
                <?php esc_html_e('Export Results (JSON)', 'formflow'); ?>
            </button>
            <button type="button" id="isf-copy-results" class="button">
                <span class="dashicons dashicons-clipboard" style="margin-top: 4px;"></span>
                <?php esc_html_e('Copy to Clipboard', 'formflow'); ?>
            </button>
        </div>
    </div>
</div>

<style>
.isf-diagnostics-quick-status {
    margin: 20px 0;
    padding: 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.isf-quick-status-loading {
    display: flex;
    align-items: center;
    color: #666;
}

.isf-quick-status-result {
    display: flex;
    align-items: center;
    gap: 15px;
}

.isf-quick-status-icon {
    font-size: 48px;
    line-height: 1;
}

.isf-quick-status-icon.healthy {
    color: #46b450;
}

.isf-quick-status-icon.warning {
    color: #ffb900;
}

.isf-quick-status-icon.critical {
    color: #dc3232;
}

.isf-quick-status-text h3 {
    margin: 0 0 5px 0;
    font-size: 18px;
}

.isf-quick-status-text p {
    margin: 0;
    color: #666;
}

.isf-quick-status-checks {
    display: flex;
    gap: 20px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.isf-quick-check {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 13px;
}

.isf-quick-check .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.isf-quick-check.pass .dashicons {
    color: #46b450;
}

.isf-quick-check.fail .dashicons {
    color: #dc3232;
}

.isf-results-summary {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.isf-summary-card {
    flex: 1;
    min-width: 150px;
    padding: 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    text-align: center;
}

.isf-summary-card .number {
    font-size: 36px;
    font-weight: 600;
    line-height: 1.2;
}

.isf-summary-card .label {
    color: #666;
    margin-top: 5px;
}

.isf-summary-card.passed .number {
    color: #46b450;
}

.isf-summary-card.failed .number {
    color: #dc3232;
}

.isf-summary-card.warnings .number {
    color: #ffb900;
}

.isf-results-details {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.isf-test-category {
    border-bottom: 1px solid #eee;
}

.isf-test-category:last-child {
    border-bottom: none;
}

.isf-category-header {
    padding: 15px 20px;
    background: #f9f9f9;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.isf-category-header:hover {
    background: #f0f0f0;
}

.isf-category-tests {
    padding: 0;
}

.isf-test-row {
    display: flex;
    align-items: flex-start;
    padding: 12px 20px;
    border-bottom: 1px solid #f0f0f0;
    gap: 15px;
}

.isf-test-row:last-child {
    border-bottom: none;
}

.isf-test-status {
    width: 24px;
    text-align: center;
    flex-shrink: 0;
}

.isf-test-status .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

.isf-test-status.passed .dashicons {
    color: #46b450;
}

.isf-test-status.failed .dashicons {
    color: #dc3232;
}

.isf-test-status.warning .dashicons {
    color: #ffb900;
}

.isf-test-name {
    min-width: 200px;
    font-weight: 500;
    flex-shrink: 0;
}

.isf-test-message {
    flex: 1;
    color: #666;
}

.isf-test-details {
    font-size: 12px;
    color: #999;
    margin-top: 5px;
}

/* Running state */
#isf-run-diagnostics.running {
    pointer-events: none;
}

#isf-run-diagnostics.running .dashicons {
    animation: isf-spin 1s linear infinite;
}

@keyframes isf-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<script>
jQuery(document).ready(function($) {
    var diagnosticsResults = null;

    // Quick health check on page load
    function runQuickHealthCheck() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'formflow_quick_health_check',
                nonce: isf_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayQuickStatus(response.data);
                } else {
                    $('#isf-quick-status').html('<p class="error">' + (response.data.message || 'Health check failed') + '</p>');
                }
            },
            error: function() {
                $('#isf-quick-status').html('<p class="error">Failed to check system health.</p>');
            }
        });
    }

    function displayQuickStatus(data) {
        var iconClass = data.overall === 'healthy' ? 'yes-alt' : (data.overall === 'warning' ? 'warning' : 'dismiss');
        var statusText = data.overall === 'healthy' ? '<?php echo esc_js(__('All Systems Operational', 'formflow')); ?>' :
                        (data.overall === 'warning' ? '<?php echo esc_js(__('Some Issues Detected', 'formflow')); ?>' :
                        '<?php echo esc_js(__('Critical Issues Found', 'formflow')); ?>');
        var issueText = data.issues.length > 0 ? data.issues.join(', ') : '<?php echo esc_js(__('No issues detected', 'formflow')); ?>';

        var html = '<div class="isf-quick-status-result">';
        html += '<div class="isf-quick-status-icon ' + data.overall + '"><span class="dashicons dashicons-' + iconClass + '"></span></div>';
        html += '<div class="isf-quick-status-text">';
        html += '<h3>' + statusText + '</h3>';
        html += '<p>' + issueText + '</p>';
        html += '</div>';
        html += '</div>';

        // Quick checks row
        html += '<div class="isf-quick-status-checks">';
        html += '<div class="isf-quick-check ' + (data.checks.database ? 'pass' : 'fail') + '">';
        html += '<span class="dashicons dashicons-' + (data.checks.database ? 'yes' : 'no') + '"></span> Database';
        html += '</div>';
        html += '<div class="isf-quick-check ' + (data.checks.tables ? 'pass' : 'fail') + '">';
        html += '<span class="dashicons dashicons-' + (data.checks.tables ? 'yes' : 'no') + '"></span> Tables';
        html += '</div>';
        html += '<div class="isf-quick-check ' + (data.checks.encryption ? 'pass' : 'fail') + '">';
        html += '<span class="dashicons dashicons-' + (data.checks.encryption ? 'yes' : 'no') + '"></span> Encryption';
        html += '</div>';
        html += '<div class="isf-quick-check ' + (data.checks.instances ? 'pass' : 'fail') + '">';
        html += '<span class="dashicons dashicons-' + (data.checks.instances ? 'yes' : 'no') + '"></span> Instances (' + data.instance_count + ')';
        html += '</div>';
        html += '<div class="isf-quick-check ' + (data.checks.cron ? 'pass' : 'fail') + '">';
        html += '<span class="dashicons dashicons-' + (data.checks.cron ? 'yes' : 'no') + '"></span> Cron';
        html += '</div>';
        html += '</div>';

        $('#isf-quick-status').html(html);
    }

    // Run quick check on load
    runQuickHealthCheck();

    // Full diagnostics
    $('#isf-run-diagnostics').on('click', function() {
        var $btn = $(this);
        var instanceId = $('#isf-test-instance').val();

        $btn.addClass('running').prop('disabled', true);
        $btn.find('.dashicons').removeClass('dashicons-admin-tools').addClass('dashicons-update');

        $('#isf-diagnostics-results').hide();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'formflow_run_diagnostics',
                nonce: isf_admin.nonce,
                instance_id: instanceId
            },
            success: function(response) {
                if (response.success) {
                    diagnosticsResults = response.data;
                    displayResults(response.data);
                } else {
                    alert(response.data.message || 'Diagnostics failed');
                }
            },
            error: function() {
                alert('Failed to run diagnostics.');
            },
            complete: function() {
                $btn.removeClass('running').prop('disabled', false);
                $btn.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-admin-tools');
            }
        });
    });

    function displayResults(data) {
        // Summary cards
        var summaryHtml = '';
        summaryHtml += '<div class="isf-summary-card"><div class="number">' + data.summary.total + '</div><div class="label"><?php echo esc_js(__('Total Tests', 'formflow')); ?></div></div>';
        summaryHtml += '<div class="isf-summary-card passed"><div class="number">' + data.summary.passed + '</div><div class="label"><?php echo esc_js(__('Passed', 'formflow')); ?></div></div>';
        summaryHtml += '<div class="isf-summary-card failed"><div class="number">' + data.summary.failed + '</div><div class="label"><?php echo esc_js(__('Failed', 'formflow')); ?></div></div>';
        summaryHtml += '<div class="isf-summary-card warnings"><div class="number">' + data.summary.warnings + '</div><div class="label"><?php echo esc_js(__('Warnings', 'formflow')); ?></div></div>';
        $('#isf-results-summary').html(summaryHtml);

        // Group tests by category
        var categories = {};
        data.tests.forEach(function(test) {
            if (!categories[test.category]) {
                categories[test.category] = [];
            }
            categories[test.category].push(test);
        });

        // Build details HTML
        var detailsHtml = '';
        Object.keys(categories).forEach(function(category) {
            var tests = categories[category];
            var passedCount = tests.filter(function(t) { return t.status === 'passed'; }).length;
            var failedCount = tests.filter(function(t) { return t.status === 'failed'; }).length;
            var warningCount = tests.filter(function(t) { return t.status === 'warning'; }).length;

            var statusBadges = '';
            if (failedCount > 0) statusBadges += '<span style="color: #dc3232;">' + failedCount + ' failed</span> ';
            if (warningCount > 0) statusBadges += '<span style="color: #ffb900;">' + warningCount + ' warnings</span> ';
            if (passedCount === tests.length) statusBadges = '<span style="color: #46b450;">All passed</span>';

            detailsHtml += '<div class="isf-test-category">';
            detailsHtml += '<div class="isf-category-header">';
            detailsHtml += '<span>' + category + ' (' + tests.length + ' tests)</span>';
            detailsHtml += '<span>' + statusBadges + '</span>';
            detailsHtml += '</div>';
            detailsHtml += '<div class="isf-category-tests">';

            tests.forEach(function(test) {
                var icon = test.status === 'passed' ? 'yes-alt' : (test.status === 'failed' ? 'dismiss' : 'warning');
                detailsHtml += '<div class="isf-test-row">';
                detailsHtml += '<div class="isf-test-status ' + test.status + '"><span class="dashicons dashicons-' + icon + '"></span></div>';
                detailsHtml += '<div class="isf-test-name">' + test.name + '</div>';
                detailsHtml += '<div class="isf-test-message">' + test.message;
                if (test.details && Object.keys(test.details).length > 0) {
                    detailsHtml += '<div class="isf-test-details">' + JSON.stringify(test.details) + '</div>';
                }
                detailsHtml += '</div>';
                detailsHtml += '</div>';
            });

            detailsHtml += '</div>';
            detailsHtml += '</div>';
        });

        $('#isf-results-details').html(detailsHtml);

        // Add meta info
        var metaHtml = '<p style="color: #666; margin-top: 15px; font-size: 12px;">';
        metaHtml += 'Plugin Version: ' + data.version + ' | ';
        metaHtml += 'PHP: ' + data.php_version + ' | ';
        metaHtml += 'WordPress: ' + data.wp_version + ' | ';
        metaHtml += 'Tested: ' + data.timestamp;
        metaHtml += '</p>';
        $('#isf-results-details').append(metaHtml);

        $('#isf-diagnostics-results').show();
    }

    // Export results
    $('#isf-export-results').on('click', function() {
        if (!diagnosticsResults) return;

        var dataStr = JSON.stringify(diagnosticsResults, null, 2);
        var blob = new Blob([dataStr], {type: 'application/json'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'isf-diagnostics-' + new Date().toISOString().slice(0,10) + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });

    // Copy to clipboard
    $('#isf-copy-results').on('click', function() {
        if (!diagnosticsResults) return;

        var textResults = 'FormFlow Diagnostics Report\n';
        textResults += '=====================================\n\n';
        textResults += 'Version: ' + diagnosticsResults.version + '\n';
        textResults += 'PHP: ' + diagnosticsResults.php_version + '\n';
        textResults += 'WordPress: ' + diagnosticsResults.wp_version + '\n';
        textResults += 'Tested: ' + diagnosticsResults.timestamp + '\n\n';

        textResults += 'Summary: ' + diagnosticsResults.summary.passed + ' passed, ';
        textResults += diagnosticsResults.summary.failed + ' failed, ';
        textResults += diagnosticsResults.summary.warnings + ' warnings\n\n';

        // Group by category
        var categories = {};
        diagnosticsResults.tests.forEach(function(test) {
            if (!categories[test.category]) categories[test.category] = [];
            categories[test.category].push(test);
        });

        Object.keys(categories).forEach(function(category) {
            textResults += category + '\n' + '-'.repeat(category.length) + '\n';
            categories[category].forEach(function(test) {
                var status = test.status === 'passed' ? '[PASS]' : (test.status === 'failed' ? '[FAIL]' : '[WARN]');
                textResults += status + ' ' + test.name + ': ' + test.message + '\n';
            });
            textResults += '\n';
        });

        navigator.clipboard.writeText(textResults).then(function() {
            alert('<?php echo esc_js(__('Results copied to clipboard!', 'formflow')); ?>');
        });
    });

    // Toggle category visibility
    $(document).on('click', '.isf-category-header', function() {
        $(this).next('.isf-category-tests').slideToggle(200);
    });
});
</script>

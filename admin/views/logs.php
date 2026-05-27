<?php
/**
 * Admin Logs View
 *
 * Displays submission logs and activity.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap isf-admin-wrap">
    <h1><?php esc_html_e('Logs & Activity', 'formflow'); ?></h1>

    <!-- View Tabs (Standardized nav-tab-wrapper) -->
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url(add_query_arg('view', 'submissions')); ?>"
           class="nav-tab <?php echo ($view === 'submissions') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-list-view" style="margin-right: 4px;"></span>
            <?php esc_html_e('Submissions', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('view', 'logs')); ?>"
           class="nav-tab <?php echo ($view === 'logs') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-media-text" style="margin-right: 4px;"></span>
            <?php esc_html_e('Activity Logs', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('view', 'debug')); ?>"
           class="nav-tab <?php echo ($view === 'debug') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-tools" style="margin-right: 4px;"></span>
            <?php esc_html_e('Debug', 'formflow'); ?>
        </a>
    </nav>

    <!-- Filters (hidden on debug view) -->
    <?php if ($view !== 'debug') : ?>
    <form method="get" class="isf-log-filters">
        <input type="hidden" name="page" value="isf-logs">
        <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">

        <select name="instance_id">
            <option value=""><?php esc_html_e('All Forms', 'formflow'); ?></option>
            <?php foreach ($instances as $inst) : ?>
                <option value="<?php echo esc_attr($inst['id']); ?>"
                        <?php selected($filters['instance_id'], $inst['id']); ?>>
                    <?php echo esc_html($inst['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($view === 'submissions') : ?>
            <select name="status">
                <option value=""><?php esc_html_e('All Statuses', 'formflow'); ?></option>
                <option value="completed" <?php selected($filters['status'], 'completed'); ?>>
                    <?php esc_html_e('Completed', 'formflow'); ?>
                </option>
                <option value="in_progress" <?php selected($filters['status'], 'in_progress'); ?>>
                    <?php esc_html_e('In Progress', 'formflow'); ?>
                </option>
                <option value="failed" <?php selected($filters['status'], 'failed'); ?>>
                    <?php esc_html_e('Failed', 'formflow'); ?>
                </option>
                <option value="abandoned" <?php selected($filters['status'], 'abandoned'); ?>>
                    <?php esc_html_e('Abandoned', 'formflow'); ?>
                </option>
            </select>
        <?php else : ?>
            <select name="type">
                <option value=""><?php esc_html_e('All Types', 'formflow'); ?></option>
                <option value="info" <?php selected($filters['type'], 'info'); ?>>
                    <?php esc_html_e('Info', 'formflow'); ?>
                </option>
                <option value="warning" <?php selected($filters['type'], 'warning'); ?>>
                    <?php esc_html_e('Warning', 'formflow'); ?>
                </option>
                <option value="error" <?php selected($filters['type'], 'error'); ?>>
                    <?php esc_html_e('Error', 'formflow'); ?>
                </option>
                <option value="api_call" <?php selected($filters['type'], 'api_call'); ?>>
                    <?php esc_html_e('API Call', 'formflow'); ?>
                </option>
                <option value="security" <?php selected($filters['type'], 'security'); ?>>
                    <?php esc_html_e('Security', 'formflow'); ?>
                </option>
            </select>
        <?php endif; ?>

        <input type="text" name="search" placeholder="<?php esc_attr_e('Search...', 'formflow'); ?>"
               value="<?php echo esc_attr($filters['search']); ?>">

        <button type="submit" class="button"><?php esc_html_e('Filter', 'formflow'); ?></button>

        <?php if (!empty(array_filter($filters))) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=isf-logs&view=' . $view)); ?>" class="button">
                <?php esc_html_e('Clear', 'formflow'); ?>
            </a>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <!-- Bulk Actions & Export (hidden on debug view) -->
    <?php if ($view !== 'debug') : ?>
    <div class="isf-bulk-actions-bar">
        <div class="isf-bulk-left">
            <select id="isf-bulk-action" class="isf-bulk-select">
                <option value=""><?php esc_html_e('Bulk Actions', 'formflow'); ?></option>
                <?php if ($view === 'submissions') : ?>
                    <option value="mark_test"><?php esc_html_e('Mark as Test Data', 'formflow'); ?></option>
                    <option value="mark_production"><?php esc_html_e('Mark as Production Data', 'formflow'); ?></option>
                <?php endif; ?>
                <option value="delete"><?php esc_html_e('Delete', 'formflow'); ?></option>
            </select>
            <button type="button" id="isf-apply-bulk" class="button">
                <?php esc_html_e('Apply', 'formflow'); ?>
            </button>
            <span id="isf-bulk-count" class="isf-bulk-count" style="display: none;">
                (<span class="count">0</span> <?php esc_html_e('selected', 'formflow'); ?>)
            </span>
        </div>
        <?php if ($view === 'submissions') : ?>
        <div class="isf-bulk-right">
            <button type="button" id="isf-export-csv" class="button">
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e('Export CSV', 'formflow'); ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Results Table -->
    <?php if ($view === 'debug') : ?>
        <!-- Debug Log View -->
        <?php
        global $wpdb;
        $table_logs = $wpdb->prefix . 'isf_logs';
        $recent_errors = $wpdb->get_results(
            "SELECT * FROM {$table_logs}
             WHERE log_type IN ('error', 'warning')
             ORDER BY created_at DESC
             LIMIT 50",
            ARRAY_A
        );
        ?>
        <?php
        // Handle clear rate limits action
        if (isset($_POST['isf_clear_rate_limits']) && wp_verify_nonce($_POST['_wpnonce'], 'isf_clear_rate_limits')) {
            // Clear all rate limit transients
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_isf_rate_%' OR option_name LIKE '_transient_timeout_isf_rate_%'");
            echo '<div class="notice notice-success"><p>' . esc_html__('Rate limits cleared successfully!', 'formflow') . '</p></div>';
        }

        // Handle disable/enable rate limiting
        if (isset($_POST['isf_toggle_rate_limit']) && wp_verify_nonce($_POST['_wpnonce'], 'isf_toggle_rate_limit')) {
            $settings = get_option('isf_settings', []);
            $settings['disable_rate_limit'] = !empty($_POST['disable_rate_limit']);
            update_option('isf_settings', $settings);
            echo '<div class="notice notice-success"><p>' . esc_html__('Rate limit settings updated!', 'formflow') . '</p></div>';
        }

        $settings = get_option('isf_settings', []);
        $rate_limit_disabled = !empty($settings['disable_rate_limit']);
        ?>

        <div class="isf-debug-panel">
            <!-- Rate Limit Controls -->
            <div style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:20px;">
                <h3 style="margin-top:0;"><?php esc_html_e('Rate Limiting Controls', 'formflow'); ?></h3>
                <p class="description"><?php esc_html_e('If forms are showing "Network error" due to rate limiting (429 errors), use these controls.', 'formflow'); ?></p>

                <div style="display:flex; gap:10px; align-items:center; margin-top:10px;">
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('isf_clear_rate_limits'); ?>
                        <button type="submit" name="isf_clear_rate_limits" class="button">
                            <?php esc_html_e('Clear All Rate Limits', 'formflow'); ?>
                        </button>
                    </form>

                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('isf_toggle_rate_limit'); ?>
                        <input type="hidden" name="disable_rate_limit" value="<?php echo $rate_limit_disabled ? '0' : '1'; ?>">
                        <button type="submit" name="isf_toggle_rate_limit" class="button <?php echo $rate_limit_disabled ? 'button-primary' : ''; ?>">
                            <?php echo $rate_limit_disabled
                                ? esc_html__('Enable Rate Limiting', 'formflow')
                                : esc_html__('Disable Rate Limiting', 'formflow'); ?>
                        </button>
                    </form>

                    <span style="color: <?php echo $rate_limit_disabled ? '#d63638' : '#00a32a'; ?>; font-weight:bold;">
                        <?php echo $rate_limit_disabled
                            ? esc_html__('Rate limiting is DISABLED', 'formflow')
                            : esc_html__('Rate limiting is ENABLED (120 req/min)', 'formflow'); ?>
                    </span>
                </div>
            </div>

            <h2><?php esc_html_e('Recent Errors & Warnings (Last 50)', 'formflow'); ?></h2>
            <p class="description"><?php esc_html_e('Copy the content below and share it for debugging purposes.', 'formflow'); ?></p>

            <div style="margin-bottom: 15px;">
                <button type="button" class="button button-primary" id="isf-copy-debug">
                    <?php esc_html_e('Copy to Clipboard', 'formflow'); ?>
                </button>
                <button type="button" class="button" id="isf-refresh-debug" onclick="location.reload();">
                    <?php esc_html_e('Refresh', 'formflow'); ?>
                </button>
            </div>

            <textarea id="isf-debug-output" readonly style="width:100%; height:500px; font-family:monospace; font-size:12px; background:#f1f1f1; padding:10px;"><?php
                echo "=== FormFlow Debug Log ===\n";
                echo "Generated: " . current_time('Y-m-d H:i:s') . "\n";
                echo "Plugin Version: " . ISF_VERSION . "\n";
                echo "WordPress Version: " . get_bloginfo('version') . "\n";
                echo "PHP Version: " . phpversion() . "\n";
                echo "==========================================\n\n";

                if (empty($recent_errors)) {
                    echo "No errors or warnings found in the log.\n";
                } else {
                    foreach ($recent_errors as $log) {
                        echo "[" . esc_html($log['created_at']) . "] ";
                        echo strtoupper($log['log_type']) . ": ";
                        echo esc_html($log['message']) . "\n";
                        if (!empty($log['details'])) {
                            $details = json_decode($log['details'], true);
                            if ($details) {
                                echo "  Details: " . json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                            }
                        }
                        echo "  Instance ID: " . ($log['instance_id'] ?: 'N/A') . "\n";
                        echo "  Submission ID: " . ($log['submission_id'] ?: 'N/A') . "\n";
                        echo "---\n";
                    }
                }

                // Also show last PHP error if WP_DEBUG is on
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $error_log_path = ini_get('error_log');
                    if ($error_log_path && file_exists($error_log_path) && is_readable($error_log_path)) {
                        echo "\n=== Last 20 PHP Error Log Lines ===\n";
                        $lines = file($error_log_path);
                        $last_lines = array_slice($lines, -20);
                        foreach ($last_lines as $line) {
                            echo esc_html($line);
                        }
                    }
                }
            ?></textarea>

            <script>
            jQuery(document).ready(function($) {
                $('#isf-copy-debug').on('click', function() {
                    var textarea = document.getElementById('isf-debug-output');
                    textarea.select();
                    document.execCommand('copy');
                    $(this).text('Copied!');
                    setTimeout(function() {
                        $('#isf-copy-debug').text('<?php echo esc_js(__('Copy to Clipboard', 'formflow')); ?>');
                    }, 2000);
                });
            });
            </script>
        </div>
    <?php elseif ($view === 'submissions') : ?>
        <!-- Submissions Table -->
        <table class="wp-list-table widefat fixed striped" id="isf-submissions-table">
            <thead>
                <tr>
                    <th class="column-cb check-column" style="width:30px;"><input type="checkbox" id="isf-select-all"></th>
                    <th class="column-id" style="width:50px;"><?php esc_html_e('ID', 'formflow'); ?></th>
                    <th class="column-form"><?php esc_html_e('Form', 'formflow'); ?></th>
                    <th class="column-account"><?php esc_html_e('Account', 'formflow'); ?></th>
                    <th class="column-customer"><?php esc_html_e('Customer', 'formflow'); ?></th>
                    <th class="column-device"><?php esc_html_e('Device', 'formflow'); ?></th>
                    <th class="column-status"><?php esc_html_e('Status', 'formflow'); ?></th>
                    <th class="column-step" style="width:60px;"><?php esc_html_e('Step', 'formflow'); ?></th>
                    <th class="column-date"><?php esc_html_e('Date', 'formflow'); ?></th>
                    <th class="column-actions" style="width:80px;"><?php esc_html_e('Actions', 'formflow'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)) : ?>
                    <tr>
                        <td colspan="10"><?php esc_html_e('No submissions found.', 'formflow'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td class="column-cb check-column"><input type="checkbox" class="isf-row-cb" value="<?php echo esc_attr($item['id']); ?>"></td>
                            <td class="column-id"><?php echo esc_html($item['id']); ?></td>
                            <td class="column-form"><?php echo esc_html($item['instance_name'] ?? 'Unknown'); ?></td>
                            <td class="column-account">
                                <?php if ($item['account_number']) : ?>
                                    <code><?php echo esc_html(\ISF\Encryption::mask($item['account_number'], 0, 4)); ?></code>
                                <?php else : ?>
                                    <span class="isf-na">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="column-customer"><?php echo esc_html($item['customer_name'] ?: '—'); ?></td>
                            <td class="column-device">
                                <?php if ($item['device_type']) : ?>
                                    <?php echo esc_html(ucfirst($item['device_type'])); ?>
                                <?php else : ?>
                                    <span class="isf-na">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="column-status">
                                <span class="isf-status isf-status-<?php echo esc_attr($item['status']); ?>">
                                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $item['status']))); ?>
                                </span>
                                <?php if (!empty($item['is_test'])) : ?>
                                    <span class="isf-status isf-status-test"><?php esc_html_e('Test', 'formflow'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-step"><?php echo esc_html($item['step']); ?>/5</td>
                            <td class="column-date">
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item['created_at']))); ?>
                            </td>
                            <td class="column-actions">
                                <button type="button" class="button button-small isf-view-submission"
                                        data-id="<?php echo esc_attr($item['id']); ?>"
                                        title="<?php esc_attr_e('View Details', 'formflow'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    <?php else : ?>
        <!-- Activity Logs Table -->
        <table class="wp-list-table widefat fixed striped" id="isf-logs-table">
            <thead>
                <tr>
                    <th class="column-cb check-column" style="width:30px;"><input type="checkbox" id="isf-select-all"></th>
                    <th class="column-id"><?php esc_html_e('ID', 'formflow'); ?></th>
                    <th class="column-type"><?php esc_html_e('Type', 'formflow'); ?></th>
                    <th class="column-form"><?php esc_html_e('Form', 'formflow'); ?></th>
                    <th class="column-message"><?php esc_html_e('Message', 'formflow'); ?></th>
                    <th class="column-date"><?php esc_html_e('Date', 'formflow'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)) : ?>
                    <tr>
                        <td colspan="6"><?php esc_html_e('No logs found.', 'formflow'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td class="column-cb check-column"><input type="checkbox" class="isf-row-cb" value="<?php echo esc_attr($item['id']); ?>"></td>
                            <td class="column-id"><?php echo esc_html($item['id']); ?></td>
                            <td class="column-type">
                                <span class="isf-log-type isf-log-type-<?php echo esc_attr($item['log_type']); ?>">
                                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $item['log_type']))); ?>
                                </span>
                            </td>
                            <td class="column-form"><?php echo esc_html($item['instance_name'] ?? '—'); ?></td>
                            <td class="column-message">
                                <?php echo esc_html($item['message']); ?>
                                <?php if (!empty($item['details'])) : ?>
                                    <a href="#" class="isf-view-details" data-details="<?php echo esc_attr(json_encode($item['details'])); ?>">
                                        <?php esc_html_e('[details]', 'formflow'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td class="column-date">
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item['created_at']))); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Pagination -->
    <?php if ($total_pages > 1) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf(
                        esc_html__('%d items', 'formflow'),
                        $total_items
                    ); ?>
                </span>
                <span class="pagination-links">
                    <?php if ($page > 1) : ?>
                        <a class="first-page button" href="<?php echo esc_url(add_query_arg('paged', 1)); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('First page', 'formflow'); ?></span>
                            <span aria-hidden="true">«</span>
                        </a>
                        <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Previous page', 'formflow'); ?></span>
                            <span aria-hidden="true">‹</span>
                        </a>
                    <?php endif; ?>

                    <span class="paging-input">
                        <?php printf(
                            esc_html__('%1$d of %2$d', 'formflow'),
                            $page,
                            $total_pages
                        ); ?>
                    </span>

                    <?php if ($page < $total_pages) : ?>
                        <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Next page', 'formflow'); ?></span>
                            <span aria-hidden="true">›</span>
                        </a>
                        <a class="last-page button" href="<?php echo esc_url(add_query_arg('paged', $total_pages)); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Last page', 'formflow'); ?></span>
                            <span aria-hidden="true">»</span>
                        </a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Log Details Modal -->
<div id="isf-details-modal" class="isf-modal" style="display:none;">
    <div class="isf-modal-content">
        <span class="isf-modal-close">&times;</span>
        <h3><?php esc_html_e('Log Details', 'formflow'); ?></h3>
        <pre id="isf-details-content"></pre>
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

<script>
jQuery(document).ready(function($) {
    // HTML escape function to prevent XSS
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // View log details modal
    $('.isf-view-details').on('click', function(e) {
        e.preventDefault();
        var details = $(this).data('details');
        $('#isf-details-content').text(JSON.stringify(details, null, 2));
        $('#isf-details-modal').show();
    });

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

        // Get current filters
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
                    // Trigger download
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

        var isSubmissions = '<?php echo $view; ?>' === 'submissions';
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

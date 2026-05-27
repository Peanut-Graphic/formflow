<?php
/**
 * Analytics Admin View
 *
 * Displays form analytics including funnel, timing, and drop-off data.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap isf-analytics" id="isf-analytics-content">
    <div class="isf-analytics-header">
        <h1><?php esc_html_e('Form Analytics', 'formflow'); ?></h1>
        <div class="isf-analytics-actions">
            <button type="button" class="button" id="isf-export-pdf">
                <span class="dashicons dashicons-pdf"></span>
                <?php esc_html_e('Export PDF', 'formflow'); ?>
            </button>
            <button type="button" class="button button-secondary isf-clear-analytics" data-instance="<?php echo esc_attr($instance_id); ?>">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e('Clear Analytics', 'formflow'); ?>
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="isf-analytics-filters">
        <form method="get" action="" id="isf-analytics-form">
            <input type="hidden" name="page" value="isf-analytics">

            <div class="isf-filter-row">
                <label for="instance_id"><?php esc_html_e('Form:', 'formflow'); ?></label>
                <select name="instance_id" id="instance_id">
                    <option value=""><?php esc_html_e('All Forms', 'formflow'); ?></option>
                    <?php foreach ($instances as $inst) : ?>
                        <option value="<?php echo esc_attr($inst['id']); ?>" <?php selected($instance_id, $inst['id']); ?>>
                            <?php echo esc_html($inst['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="isf-checkbox-inline">
                    <input type="checkbox" name="show_test" value="1" <?php checked($show_test ?? false); ?>>
                    <?php esc_html_e('Include Test Data', 'formflow'); ?>
                </label>
            </div>

            <div class="isf-filter-row isf-date-filters">
                <div class="isf-date-presets">
                    <span class="isf-preset-label"><?php esc_html_e('Quick:', 'formflow'); ?></span>
                    <button type="button" class="button button-small isf-date-preset" data-preset="today"><?php esc_html_e('Today', 'formflow'); ?></button>
                    <button type="button" class="button button-small isf-date-preset" data-preset="yesterday"><?php esc_html_e('Yesterday', 'formflow'); ?></button>
                    <button type="button" class="button button-small isf-date-preset" data-preset="last7"><?php esc_html_e('Last 7 Days', 'formflow'); ?></button>
                    <button type="button" class="button button-small isf-date-preset" data-preset="last30"><?php esc_html_e('Last 30 Days', 'formflow'); ?></button>
                    <button type="button" class="button button-small isf-date-preset" data-preset="thismonth"><?php esc_html_e('This Month', 'formflow'); ?></button>
                    <button type="button" class="button button-small isf-date-preset" data-preset="lastmonth"><?php esc_html_e('Last Month', 'formflow'); ?></button>
                </div>

                <div class="isf-date-custom">
                    <label for="date_from"><?php esc_html_e('From:', 'formflow'); ?></label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($date_from); ?>">

                    <label for="date_to"><?php esc_html_e('To:', 'formflow'); ?></label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($date_to); ?>">

                    <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'formflow'); ?></button>
                </div>
            </div>
        </form>
    </div>

    <!-- Test Data Management -->
    <?php if (!empty($test_counts['submissions']) || !empty($test_counts['analytics'])) : ?>
    <div class="isf-test-data-notice notice notice-warning">
        <p>
            <strong><?php esc_html_e('Test Data:', 'formflow'); ?></strong>
            <?php
            printf(
                esc_html__('%d test submissions and %d test analytics records found.', 'formflow'),
                $test_counts['submissions'],
                $test_counts['analytics']
            );
            ?>
            <?php if (!$show_test) : ?>
                <em><?php esc_html_e('(excluded from stats below)', 'formflow'); ?></em>
            <?php endif; ?>
            <button type="button" class="button button-small isf-delete-test-data" data-instance="<?php echo esc_attr($instance_id); ?>">
                <?php esc_html_e('Delete All Test Data', 'formflow'); ?>
            </button>
        </p>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="isf-section-header">
        <h2><?php esc_html_e('Overview', 'formflow'); ?></h2>
        <p class="isf-section-description"><?php esc_html_e('Key performance metrics for your enrollment forms during the selected date range.', 'formflow'); ?></p>
    </div>
    <div class="isf-analytics-summary">
        <div class="isf-stat-card">
            <span class="isf-stat-value"><?php echo esc_html(number_format($summary['total_started'])); ?></span>
            <span class="isf-stat-label"><?php esc_html_e('Forms Started', 'formflow'); ?></span>
            <span class="isf-stat-help"><?php esc_html_e('Total unique sessions that began the enrollment process', 'formflow'); ?></span>
        </div>
        <div class="isf-stat-card isf-stat-success">
            <span class="isf-stat-value"><?php echo esc_html(number_format($summary['total_completed'])); ?></span>
            <span class="isf-stat-label"><?php esc_html_e('Completed', 'formflow'); ?></span>
            <span class="isf-stat-help"><?php esc_html_e('Enrollments successfully submitted', 'formflow'); ?></span>
        </div>
        <div class="isf-stat-card isf-stat-warning">
            <span class="isf-stat-value"><?php echo esc_html(number_format($summary['total_abandoned'])); ?></span>
            <span class="isf-stat-label"><?php esc_html_e('Abandoned', 'formflow'); ?></span>
            <span class="isf-stat-help"><?php esc_html_e('Users who left without completing enrollment', 'formflow'); ?></span>
        </div>
        <div class="isf-stat-card">
            <span class="isf-stat-value"><?php echo esc_html($summary['completion_rate']); ?>%</span>
            <span class="isf-stat-label"><?php esc_html_e('Completion Rate', 'formflow'); ?></span>
            <span class="isf-stat-help"><?php esc_html_e('Percentage of started forms that were completed', 'formflow'); ?></span>
        </div>
        <div class="isf-stat-card">
            <span class="isf-stat-value"><?php echo esc_html($summary['avg_completion_time_formatted']); ?></span>
            <span class="isf-stat-label"><?php esc_html_e('Avg. Time to Complete', 'formflow'); ?></span>
            <span class="isf-stat-help"><?php esc_html_e('Average duration from start to submission', 'formflow'); ?></span>
        </div>
    </div>

    <!-- Visual Charts Section -->
    <div class="isf-charts-section">
        <div class="isf-chart-container">
            <h2><?php esc_html_e('Enrollment Funnel', 'formflow'); ?></h2>
            <p class="isf-chart-description"><?php esc_html_e('Visual representation of user progression through each step. Wider bars indicate more users; drop-off percentages show where users leave the process.', 'formflow'); ?></p>
            <div class="isf-funnel-chart" id="isf-funnel-chart">
                <?php if (!empty($funnel)) : ?>
                    <?php
                    $max_entered = $funnel[0]['sessions_entered'] ?? 1;
                    foreach ($funnel as $index => $step) :
                        $width_pct = ($step['sessions_entered'] / max($max_entered, 1)) * 100;
                        $colors = ['#4285f4', '#34a853', '#fbbc04', '#ea4335', '#673ab7'];
                        $color = $colors[$index % count($colors)];
                    ?>
                    <div class="isf-funnel-step">
                        <div class="isf-funnel-bar-visual" style="width: <?php echo esc_attr($width_pct); ?>%; background-color: <?php echo esc_attr($color); ?>;">
                            <span class="isf-funnel-step-label">
                                <?php echo esc_html($step['step'] . '. ' . $step['step_name']); ?>
                            </span>
                            <span class="isf-funnel-step-value"><?php echo esc_html(number_format($step['sessions_entered'])); ?></span>
                        </div>
                        <?php if ($step['drop_off_rate'] > 0) : ?>
                        <div class="isf-funnel-dropoff">
                            <span class="isf-dropoff-arrow">&darr;</span>
                            <span class="isf-dropoff-text"><?php echo esc_html($step['drop_off_rate']); ?>% <?php esc_html_e('drop-off', 'formflow'); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p class="isf-no-data"><?php esc_html_e('No funnel data available.', 'formflow'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="isf-chart-container">
            <h2><?php esc_html_e('Daily Trends (Last 30 Days)', 'formflow'); ?></h2>
            <p class="isf-chart-description"><?php esc_html_e('Track enrollment activity over time. Blue line shows forms started; green line shows completions. Use this to identify patterns and peak enrollment days.', 'formflow'); ?></p>
            <?php if (!empty($daily)) : ?>
            <canvas id="isf-daily-chart" height="250"></canvas>
            <?php else : ?>
            <p class="isf-no-data"><?php esc_html_e('No daily data available.', 'formflow'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="isf-analytics-grid">
        <!-- Funnel Analysis Table -->
        <div class="isf-analytics-section">
            <h2><?php esc_html_e('Step-by-Step Funnel', 'formflow'); ?></h2>
            <p class="description"><?php esc_html_e('Detailed breakdown of user progression. High drop-off rates indicate steps that may need improvement. Average time helps identify where users spend the most effort.', 'formflow'); ?></p>

            <?php if (!empty($funnel)) : ?>
                <table class="widefat isf-funnel-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Step', 'formflow'); ?></th>
                            <th><?php esc_html_e('Users Entered', 'formflow'); ?></th>
                            <th><?php esc_html_e('Drop-off Rate', 'formflow'); ?></th>
                            <th><?php esc_html_e('Avg. Time', 'formflow'); ?></th>
                            <th><?php esc_html_e('Funnel', 'formflow'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $max_entered = $funnel[0]['sessions_entered'] ?? 1;
                        foreach ($funnel as $step) :
                            $funnel_width = ($step['sessions_entered'] / max($max_entered, 1)) * 100;
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($step['step']); ?>.</strong>
                                    <?php echo esc_html($step['step_name']); ?>
                                </td>
                                <td><?php echo esc_html(number_format($step['sessions_entered'])); ?></td>
                                <td>
                                    <?php if ($step['drop_off_rate'] > 0) : ?>
                                        <span class="isf-dropoff-badge"><?php echo esc_html($step['drop_off_rate']); ?>%</span>
                                    <?php else : ?>
                                        <span class="isf-dropoff-badge isf-dropoff-zero">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($step['avg_time_formatted']); ?></td>
                                <td>
                                    <div class="isf-funnel-bar">
                                        <div class="isf-funnel-fill" style="width: <?php echo esc_attr($funnel_width); ?>%;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="isf-no-data"><?php esc_html_e('No funnel data available for the selected period.', 'formflow'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Drop-off Analysis -->
        <div class="isf-analytics-section">
            <h2><?php esc_html_e('Where Users Drop Off', 'formflow'); ?></h2>
            <p class="description"><?php esc_html_e('Identifies the last step users visited before leaving. Focus optimization efforts on steps with the highest abandonment counts.', 'formflow'); ?></p>

            <?php if (!empty($dropoff)) : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Step', 'formflow'); ?></th>
                            <th><?php esc_html_e('Abandoned', 'formflow'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dropoff as $item) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($item['step']); ?>.</strong>
                                    <?php echo esc_html($item['step_name'] ?? 'Step ' . $item['step']); ?>
                                </td>
                                <td><?php echo esc_html(number_format($item['abandoned_count'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="isf-no-data"><?php esc_html_e('No drop-off data available.', 'formflow'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Time on Step -->
        <div class="isf-analytics-section">
            <h2><?php esc_html_e('Time Spent per Step', 'formflow'); ?></h2>
            <p class="description"><?php esc_html_e('How long users spend on each step. Unusually long times may indicate confusion or complexity. Very short times on early steps could suggest users are abandoning quickly.', 'formflow'); ?></p>

            <?php if (!empty($timing)) : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Step', 'formflow'); ?></th>
                            <th><?php esc_html_e('Avg. Time', 'formflow'); ?></th>
                            <th><?php esc_html_e('Min', 'formflow'); ?></th>
                            <th><?php esc_html_e('Max', 'formflow'); ?></th>
                            <th><?php esc_html_e('Samples', 'formflow'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timing as $step) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($step['step']); ?>.</strong>
                                    <?php echo esc_html($step['step_name']); ?>
                                </td>
                                <td><strong><?php echo esc_html($step['avg_time_formatted']); ?></strong></td>
                                <td><?php echo esc_html($step['min_time_seconds']); ?>s</td>
                                <td><?php echo esc_html($step['max_time_seconds']); ?>s</td>
                                <td><?php echo esc_html(number_format($step['sample_size'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="isf-no-data"><?php esc_html_e('No timing data available.', 'formflow'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Device Breakdown -->
        <div class="isf-analytics-section">
            <h2><?php esc_html_e('Device & Browser', 'formflow'); ?></h2>
            <p class="description"><?php esc_html_e('Understand your audience\'s technology. High mobile usage suggests prioritizing mobile-friendly design. Browser data helps identify compatibility issues.', 'formflow'); ?></p>

            <div class="isf-device-stats">
                <div class="isf-device-card">
                    <span class="isf-device-icon dashicons dashicons-desktop"></span>
                    <span class="isf-device-value"><?php echo esc_html(number_format($devices['desktop'])); ?></span>
                    <span class="isf-device-label"><?php esc_html_e('Desktop', 'formflow'); ?></span>
                </div>
                <div class="isf-device-card">
                    <span class="isf-device-icon dashicons dashicons-smartphone"></span>
                    <span class="isf-device-value"><?php echo esc_html(number_format($devices['mobile'])); ?></span>
                    <span class="isf-device-label"><?php esc_html_e('Mobile', 'formflow'); ?> (<?php echo esc_html($devices['mobile_percentage']); ?>%)</span>
                </div>
            </div>

            <?php if (!empty($devices['browsers'])) : ?>
                <h3><?php esc_html_e('Browser Breakdown', 'formflow'); ?></h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Browser', 'formflow'); ?></th>
                            <th><?php esc_html_e('Sessions', 'formflow'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices['browsers'] as $browser => $count) : ?>
                            <tr>
                                <td><?php echo esc_html($browser); ?></td>
                                <td><?php echo esc_html(number_format($count)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<!-- html2canvas and jsPDF for PDF export -->
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>

<script>
(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize daily chart
        initDailyChart();

        // Date preset buttons
        $('.isf-date-preset').on('click', function() {
            var preset = $(this).data('preset');
            var today = new Date();
            var dateFrom, dateTo;

            switch (preset) {
                case 'today':
                    dateFrom = dateTo = formatDate(today);
                    break;
                case 'yesterday':
                    var yesterday = new Date(today);
                    yesterday.setDate(yesterday.getDate() - 1);
                    dateFrom = dateTo = formatDate(yesterday);
                    break;
                case 'last7':
                    dateTo = formatDate(today);
                    var sevenDaysAgo = new Date(today);
                    sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 6);
                    dateFrom = formatDate(sevenDaysAgo);
                    break;
                case 'last30':
                    dateTo = formatDate(today);
                    var thirtyDaysAgo = new Date(today);
                    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 29);
                    dateFrom = formatDate(thirtyDaysAgo);
                    break;
                case 'thismonth':
                    dateFrom = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
                    dateTo = formatDate(today);
                    break;
                case 'lastmonth':
                    var lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    dateFrom = formatDate(lastMonth);
                    var lastDayOfLastMonth = new Date(today.getFullYear(), today.getMonth(), 0);
                    dateTo = formatDate(lastDayOfLastMonth);
                    break;
            }

            $('#date_from').val(dateFrom);
            $('#date_to').val(dateTo);

            // Auto-submit the form
            $('#isf-analytics-form').submit();
        });

        function formatDate(date) {
            var year = date.getFullYear();
            var month = String(date.getMonth() + 1).padStart(2, '0');
            var day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }

        // Delete test data button
        $('.isf-delete-test-data').on('click', function() {
            if (!confirm('<?php echo esc_js(__('Are you sure you want to delete ALL test data? This cannot be undone.', 'formflow')); ?>')) {
                return;
            }

            var $btn = $(this);
            var instanceId = $btn.data('instance') || 0;

            $btn.prop('disabled', true).text('<?php echo esc_js(__('Deleting...', 'formflow')); ?>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'formflow_delete_test_data',
                    nonce: '<?php echo wp_create_nonce('isf_admin_nonce'); ?>',
                    instance_id: instanceId
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || 'Error deleting test data.');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Delete All Test Data', 'formflow')); ?>');
                    }
                },
                error: function() {
                    alert('Error deleting test data.');
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Delete All Test Data', 'formflow')); ?>');
                }
            });
        });

        // Clear analytics button
        $('.isf-clear-analytics').on('click', function() {
            if (!confirm('<?php echo esc_js(__('Are you sure you want to clear ALL analytics data? This cannot be undone.', 'formflow')); ?>')) {
                return;
            }

            var $btn = $(this);
            var instanceId = $btn.data('instance') || 0;

            $btn.prop('disabled', true).find('.dashicons').removeClass('dashicons-trash').addClass('dashicons-update spin');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'formflow_clear_analytics',
                    nonce: '<?php echo wp_create_nonce('isf_admin_nonce'); ?>',
                    instance_id: instanceId
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || 'Error clearing analytics.');
                        $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-trash');
                    }
                },
                error: function() {
                    alert('Error clearing analytics.');
                    $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-trash');
                }
            });
        });

        // PDF Export button
        $('#isf-export-pdf').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).find('.dashicons').removeClass('dashicons-pdf').addClass('dashicons-update spin');

            exportToPDF().then(function() {
                $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-pdf');
            }).catch(function(err) {
                console.error('PDF export error:', err);
                alert('Error generating PDF. Please try again.');
                $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-pdf');
            });
        });
    });

    // PDF Export function
    function exportToPDF() {
        return new Promise(function(resolve, reject) {
            var element = document.getElementById('isf-analytics-content');
            var filename = 'analytics-report-' + new Date().toISOString().slice(0, 10) + '.pdf';

            // Hide buttons during capture
            var buttons = element.querySelectorAll('.isf-analytics-actions, .isf-delete-test-data');
            buttons.forEach(function(btn) { btn.style.visibility = 'hidden'; });

            html2canvas(element, {
                scale: 2,
                useCORS: true,
                logging: false,
                backgroundColor: '#f0f0f1'
            }).then(function(canvas) {
                // Restore buttons
                buttons.forEach(function(btn) { btn.style.visibility = 'visible'; });

                var imgData = canvas.toDataURL('image/png');
                var { jsPDF } = window.jspdf;

                // Calculate dimensions
                var imgWidth = 210; // A4 width in mm
                var pageHeight = 297; // A4 height in mm
                var imgHeight = (canvas.height * imgWidth) / canvas.width;
                var heightLeft = imgHeight;
                var position = 0;

                var pdf = new jsPDF('p', 'mm', 'a4');

                // Add title
                pdf.setFontSize(18);
                pdf.text('Form Analytics Report', 105, 15, { align: 'center' });
                pdf.setFontSize(10);
                pdf.text('Generated: ' + new Date().toLocaleString(), 105, 22, { align: 'center' });

                position = 30;

                // Add image, handling multiple pages if needed
                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= (pageHeight - position);

                while (heightLeft > 0) {
                    position = heightLeft - imgHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }

                pdf.save(filename);
                resolve();
            }).catch(function(err) {
                buttons.forEach(function(btn) { btn.style.visibility = 'visible'; });
                reject(err);
            });
        });
    }

    function initDailyChart() {
        var canvas = document.getElementById('isf-daily-chart');
        if (!canvas) return;

        var dailyData = <?php echo json_encode(array_reverse($daily)); ?>;
        if (!dailyData.length) return;

        var labels = dailyData.map(function(d) {
            var date = new Date(d.date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });

        var startedData = dailyData.map(function(d) { return parseInt(d.started) || 0; });
        var completedData = dailyData.map(function(d) { return parseInt(d.completed) || 0; });

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: '<?php echo esc_js(__('Started', 'formflow')); ?>',
                        data: startedData,
                        borderColor: '#4285f4',
                        backgroundColor: 'rgba(66, 133, 244, 0.1)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: '<?php echo esc_js(__('Completed', 'formflow')); ?>',
                        data: completedData,
                        borderColor: '#34a853',
                        backgroundColor: 'rgba(52, 168, 83, 0.1)',
                        fill: true,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

})(jQuery);
</script>

<style>
/* Header with actions */
.isf-analytics-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 10px;
}

.isf-analytics-header h1 {
    margin: 0;
}

.isf-analytics-actions {
    display: flex;
    gap: 10px;
}

.isf-analytics-actions .button .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
    margin-right: 4px;
    vertical-align: text-bottom;
}

.isf-analytics-actions .button .dashicons.spin {
    animation: isf-spin 1s linear infinite;
}

@keyframes isf-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.isf-clear-analytics {
    color: #b32d2e !important;
    border-color: #b32d2e !important;
}

.isf-clear-analytics:hover {
    background: #b32d2e !important;
    color: #fff !important;
}

/* Section headers and descriptions */
.isf-section-header {
    margin-bottom: 15px;
}

.isf-section-header h2 {
    margin: 0 0 5px;
    font-size: 18px;
}

.isf-section-description {
    margin: 0;
    color: #646970;
    font-size: 13px;
}

.isf-chart-description {
    margin: 0 0 15px;
    color: #646970;
    font-size: 13px;
    line-height: 1.5;
}

.isf-stat-help {
    display: block;
    margin-top: 8px;
    font-size: 11px;
    color: #888;
    line-height: 1.4;
}

.isf-analytics-filters {
    background: #fff;
    padding: 15px 20px;
    margin: 20px 0;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.isf-analytics-filters form {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.isf-analytics-filters label {
    font-weight: 500;
}

.isf-analytics-filters select,
.isf-analytics-filters input[type="date"] {
    min-width: 150px;
}

.isf-checkbox-inline {
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: normal !important;
}

.isf-checkbox-inline input[type="checkbox"] {
    margin: 0;
}

.isf-test-data-notice {
    display: flex;
    align-items: center;
}

.isf-test-data-notice p {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin: 0;
}

.isf-test-data-notice em {
    color: #666;
}

.isf-analytics-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.isf-stat-card {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    text-align: center;
}

.isf-stat-card.isf-stat-success {
    border-left: 4px solid #46b450;
}

.isf-stat-card.isf-stat-warning {
    border-left: 4px solid #ffb900;
}

.isf-stat-value {
    display: block;
    font-size: 32px;
    font-weight: 600;
    color: #1d2327;
    line-height: 1.2;
}

.isf-stat-label {
    display: block;
    margin-top: 8px;
    color: #646970;
    font-size: 13px;
}

/* Charts Section */
.isf-charts-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

@media (max-width: 1200px) {
    .isf-charts-section {
        grid-template-columns: 1fr;
    }
}

.isf-chart-container {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    overflow: hidden;
    min-width: 0;
}

.isf-chart-container h2 {
    margin: 0 0 5px;
    font-size: 16px;
}

.isf-chart-container canvas {
    max-height: 300px;
}

/* Funnel Visual Chart */
.isf-funnel-chart {
    padding: 10px 0;
    overflow: hidden;
}

.isf-funnel-step {
    margin-bottom: 5px;
    overflow: hidden;
}

.isf-funnel-bar-visual {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    border-radius: 4px;
    color: #fff;
    font-weight: 500;
    min-width: 120px;
    max-width: 100%;
    box-sizing: border-box;
}

.isf-funnel-step-label {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
    min-width: 0;
}

.isf-funnel-step-value {
    margin-left: 10px;
    font-weight: 700;
    flex-shrink: 0;
}

.isf-funnel-dropoff {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 5px 0 5px 20px;
    color: #d63638;
    font-size: 12px;
}

.isf-dropoff-arrow {
    font-size: 14px;
}

/* Analytics Grid */
.isf-analytics-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

@media (max-width: 1200px) {
    .isf-analytics-grid {
        grid-template-columns: 1fr;
    }
}

.isf-analytics-section {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.isf-analytics-section h2 {
    margin: 0 0 5px;
    font-size: 16px;
}

.isf-analytics-section .description {
    margin: 0 0 15px;
    color: #646970;
}

.isf-funnel-table td {
    vertical-align: middle;
}

.isf-funnel-bar {
    background: #f0f0f1;
    height: 20px;
    border-radius: 3px;
    overflow: hidden;
    min-width: 100px;
}

.isf-funnel-fill {
    background: linear-gradient(90deg, #2271b1, #135e96);
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
}

.isf-dropoff-badge {
    display: inline-block;
    padding: 2px 8px;
    background: #ffb900;
    color: #1d2327;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.isf-dropoff-badge.isf-dropoff-zero {
    background: #f0f0f1;
    color: #646970;
}

.isf-device-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}

.isf-device-card {
    flex: 1;
    text-align: center;
    padding: 20px;
    background: #f0f6fc;
    border-radius: 4px;
}

.isf-device-icon {
    font-size: 32px;
    color: #2271b1;
}

.isf-device-value {
    display: block;
    font-size: 24px;
    font-weight: 600;
    margin: 10px 0 5px;
}

.isf-device-label {
    color: #646970;
    font-size: 13px;
}

.isf-daily-section {
    grid-column: 1 / -1;
}

.isf-no-data {
    color: #646970;
    font-style: italic;
    padding: 20px;
    text-align: center;
    background: #f0f0f1;
    border-radius: 4px;
}
</style>

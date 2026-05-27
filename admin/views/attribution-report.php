<?php
/**
 * Attribution Report Admin View
 *
 * Displays marketing attribution analytics including channel performance,
 * conversion attribution, and customer journey analysis.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include help tooltip function
require_once ISF_PLUGIN_DIR . 'admin/views/partials/help-tooltip.php';

// Initialize calculators (using fully qualified class names since this is an included view)
$attribution_calculator = new \ISF\Analytics\AttributionCalculator();
$handoff_tracker = new \ISF\Analytics\HandoffTracker();
$visitor_tracker = new \ISF\Analytics\VisitorTracker();

// Check analytics configuration status
$analytics_configured = false;
$instances_with_analytics = [];
foreach ($instances as $inst) {
    if (\ISF\FeatureManager::is_enabled($inst, 'visitor_analytics')) {
        $analytics_configured = true;
        $instances_with_analytics[] = $inst['name'];
    }
}

// Get attribution data
$attribution = [];
$channel_performance = [];
$time_to_conversion = [];
$touchpoint_analysis = [];
$handoff_stats = [];

if ($instance_id > 0) {
    $attribution = $attribution_calculator->calculate_attribution(
        $instance_id,
        $date_from,
        $date_to,
        $attribution_model ?? 'first_touch'
    );

    $channel_performance = $attribution_calculator->get_channel_performance(
        $instance_id,
        $date_from,
        $date_to,
        $attribution_model ?? 'first_touch'
    );

    $time_to_conversion = $attribution_calculator->get_time_to_conversion(
        $instance_id,
        $date_from,
        $date_to
    );

    $touchpoint_analysis = $attribution_calculator->get_touchpoint_analysis(
        $instance_id,
        $date_from,
        $date_to
    );

    $handoff_stats = $handoff_tracker->get_handoff_stats(
        $instance_id,
        $date_from,
        $date_to
    );
}

// Attribution models for dropdown
$models = [
    'first_touch' => __('First Touch', 'formflow'),
    'last_touch' => __('Last Touch', 'formflow'),
    'linear' => __('Linear', 'formflow'),
    'time_decay' => __('Time Decay', 'formflow'),
    'position_based' => __('Position Based', 'formflow'),
];
?>

<div class="wrap isf-attribution-report" id="isf-attribution-content">
    <div class="isf-attribution-header">
        <h1>
            <?php esc_html_e('Marketing Attribution', 'formflow'); ?>
            <?php isf_help_tooltip('Track which marketing campaigns, channels, and touchpoints drive form completions. Enable Visitor Analytics on your form instances to start collecting data.'); ?>
        </h1>
        <p class="description">
            <?php esc_html_e('Understand which marketing channels drive enrollments and how customers interact with your forms.', 'formflow'); ?>
        </p>
    </div>

    <?php if (!$analytics_configured) : ?>
    <!-- Setup Required Notice -->
    <div class="isf-setup-notice isf-notice-warning">
        <span class="dashicons dashicons-warning"></span>
        <div class="isf-notice-content">
            <h3><?php esc_html_e('Analytics Not Configured', 'formflow'); ?></h3>
            <p><?php esc_html_e('To track marketing attribution, you need to enable Visitor Analytics on at least one form instance.', 'formflow'); ?></p>
            <div class="isf-setup-steps">
                <h4><?php esc_html_e('Quick Setup Guide:', 'formflow'); ?></h4>
                <ol>
                    <li><?php esc_html_e('Go to FF Forms → Dashboard and edit a form instance', 'formflow'); ?></li>
                    <li><?php esc_html_e('Navigate to the Features tab', 'formflow'); ?></li>
                    <li><?php esc_html_e('Enable "Visitor Analytics" and save', 'formflow'); ?></li>
                    <li><?php esc_html_e('Use UTM parameters in your marketing links (e.g., ?utm_source=email&utm_campaign=spring2025)', 'formflow'); ?></li>
                </ol>
            </div>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=isf-dashboard')); ?>" class="button button-primary">
                    <?php esc_html_e('Go to Dashboard', 'formflow'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=isf-analytics-settings')); ?>" class="button">
                    <?php esc_html_e('Analytics Settings', 'formflow'); ?>
                </a>
            </p>
        </div>
    </div>
    <?php else : ?>
    <!-- Configuration Status -->
    <div class="isf-setup-notice isf-notice-success">
        <span class="dashicons dashicons-yes-alt"></span>
        <div class="isf-notice-content">
            <strong><?php esc_html_e('Analytics Active', 'formflow'); ?></strong>
            <?php printf(
                esc_html__('Tracking enabled on: %s', 'formflow'),
                esc_html(implode(', ', $instances_with_analytics))
            ); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="isf-attribution-filters">
        <form method="get" action="" id="isf-attribution-form">
            <input type="hidden" name="page" value="isf-attribution">

            <div class="isf-filter-row">
                <label for="instance_id">
                    <?php esc_html_e('Form:', 'formflow'); ?>
                    <?php isf_help_tooltip('Select the form instance to analyze. Only forms with Visitor Analytics enabled will show data.'); ?>
                </label>
                <select name="instance_id" id="instance_id" required>
                    <option value=""><?php esc_html_e('Select a form...', 'formflow'); ?></option>
                    <?php foreach ($instances as $inst) :
                        $has_analytics = \ISF\FeatureManager::is_enabled($inst, 'visitor_analytics');
                    ?>
                        <option value="<?php echo esc_attr($inst['id']); ?>" <?php selected($instance_id, $inst['id']); ?>>
                            <?php echo esc_html($inst['name']); ?>
                            <?php if ($has_analytics) : ?> ✓<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="attribution_model">
                    <?php esc_html_e('Attribution Model:', 'formflow'); ?>
                    <?php isf_help_tooltip('How credit is assigned to marketing touchpoints. First Touch credits the first interaction, Last Touch credits the final one, Linear splits evenly, Time Decay favors recent, Position Based gives 40% to first and last.'); ?>
                </label>
                <select name="attribution_model" id="attribution_model">
                    <?php foreach ($models as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($attribution_model ?? 'first_touch', $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="isf-filter-row isf-date-filters">
                <div class="isf-date-presets">
                    <span class="isf-preset-label"><?php esc_html_e('Quick:', 'formflow'); ?></span>
                    <button type="button" class="button button-small isf-date-preset" data-preset="last7"><?php esc_html_e('7 Days', 'formflow'); ?></button>
                    <button type="button" class="button button-small isf-date-preset" data-preset="last30"><?php esc_html_e('30 Days', 'formflow'); ?></button>
                    <button type="button" class="button button-small isf-date-preset" data-preset="last90"><?php esc_html_e('90 Days', 'formflow'); ?></button>
                    <button type="button" class="button button-small isf-date-preset" data-preset="thisyear"><?php esc_html_e('This Year', 'formflow'); ?></button>
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

    <?php if ($instance_id > 0) : ?>

    <!-- Export Actions Bar -->
    <div class="isf-export-actions">
        <span class="isf-export-label"><?php esc_html_e('Export:', 'formflow'); ?></span>
        <button type="button" class="button isf-export-btn" data-export="channel_performance" data-format="csv">
            <span class="dashicons dashicons-download"></span> <?php esc_html_e('Channels CSV', 'formflow'); ?>
        </button>
        <button type="button" class="button isf-export-btn" data-export="campaigns" data-format="csv">
            <span class="dashicons dashicons-download"></span> <?php esc_html_e('Campaigns CSV', 'formflow'); ?>
        </button>
        <button type="button" class="button isf-export-btn" data-export="handoffs" data-format="csv">
            <span class="dashicons dashicons-download"></span> <?php esc_html_e('Handoffs CSV', 'formflow'); ?>
        </button>
        <button type="button" class="button isf-export-btn" data-export="full_report" data-format="csv">
            <span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e('Full Report', 'formflow'); ?>
        </button>
    </div>

    <!-- Summary Cards -->
    <div class="isf-attribution-summary">
        <div class="isf-summary-card">
            <span class="isf-card-icon dashicons dashicons-chart-bar"></span>
            <div class="isf-card-content">
                <span class="isf-card-value"><?php echo esc_html($attribution['total_conversions'] ?? 0); ?></span>
                <span class="isf-card-label"><?php esc_html_e('Total Conversions', 'formflow'); ?></span>
            </div>
        </div>

        <div class="isf-summary-card">
            <span class="isf-card-icon dashicons dashicons-admin-site"></span>
            <div class="isf-card-content">
                <span class="isf-card-value"><?php echo esc_html(count($attribution['by_source'] ?? [])); ?></span>
                <span class="isf-card-label"><?php esc_html_e('Traffic Sources', 'formflow'); ?></span>
            </div>
        </div>

        <div class="isf-summary-card">
            <span class="isf-card-icon dashicons dashicons-clock"></span>
            <div class="isf-card-content">
                <span class="isf-card-value"><?php echo esc_html(number_format($time_to_conversion['average_hours'] ?? 0, 1)); ?>h</span>
                <span class="isf-card-label"><?php esc_html_e('Avg. Time to Convert', 'formflow'); ?></span>
            </div>
        </div>

        <div class="isf-summary-card">
            <span class="isf-card-icon dashicons dashicons-randomize"></span>
            <div class="isf-card-content">
                <span class="isf-card-value"><?php echo esc_html(number_format($touchpoint_analysis['average_touches'] ?? 0, 1)); ?></span>
                <span class="isf-card-label"><?php esc_html_e('Avg. Touchpoints', 'formflow'); ?></span>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="isf-attribution-grid">

        <!-- Channel Performance -->
        <div class="isf-card isf-channel-performance">
            <h2><?php esc_html_e('Channel Performance', 'formflow'); ?></h2>
            <?php if (!empty($channel_performance)) : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Channel', 'formflow'); ?></th>
                        <th><?php esc_html_e('Medium', 'formflow'); ?></th>
                        <th><?php esc_html_e('Visitors', 'formflow'); ?></th>
                        <th><?php esc_html_e('Conversions', 'formflow'); ?></th>
                        <th><?php esc_html_e('Conv. Rate', 'formflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($channel_performance, 0, 10) as $channel) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($channel['channel']); ?></strong></td>
                        <td><?php echo esc_html($channel['medium'] ?: '-'); ?></td>
                        <td><?php echo esc_html(number_format($channel['unique_visitors'])); ?></td>
                        <td><?php echo esc_html(number_format($channel['conversions'], 2)); ?></td>
                        <td>
                            <span class="isf-conversion-rate <?php echo $channel['conversion_rate'] >= 5 ? 'good' : ($channel['conversion_rate'] >= 2 ? 'average' : 'low'); ?>">
                                <?php echo esc_html($channel['conversion_rate']); ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p class="isf-no-data"><?php esc_html_e('No channel data available for this period.', 'formflow'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Attribution by Source -->
        <div class="isf-card isf-attribution-by-source">
            <h2><?php esc_html_e('Attribution by Source', 'formflow'); ?></h2>
            <?php if (!empty($attribution['by_source'])) : ?>
            <div class="isf-chart-container" id="isf-source-chart">
                <table class="widefat striped">
                    <tbody>
                        <?php
                        $max_value = max($attribution['by_source']);
                        foreach ($attribution['by_source'] as $source => $value) :
                            $percentage = $max_value > 0 ? ($value / $max_value) * 100 : 0;
                        ?>
                        <tr>
                            <td style="width: 120px;"><?php echo esc_html($source); ?></td>
                            <td>
                                <div class="isf-bar-chart">
                                    <div class="isf-bar" style="width: <?php echo esc_attr($percentage); ?>%;"></div>
                                    <span class="isf-bar-value"><?php echo esc_html(number_format($value, 2)); ?></span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else : ?>
            <p class="isf-no-data"><?php esc_html_e('No attribution data available for this period.', 'formflow'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Campaign Performance -->
        <div class="isf-card isf-campaign-performance">
            <h2><?php esc_html_e('Campaign Performance', 'formflow'); ?></h2>
            <?php if (!empty($attribution['by_campaign'])) : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Campaign', 'formflow'); ?></th>
                        <th><?php esc_html_e('Attributed Conversions', 'formflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($attribution['by_campaign'], 0, 10, true) as $campaign => $value) : ?>
                    <tr>
                        <td><code><?php echo esc_html($campaign); ?></code></td>
                        <td><?php echo esc_html(number_format($value, 2)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p class="isf-no-data"><?php esc_html_e('No campaign data available. Use UTM campaign tags in your marketing URLs.', 'formflow'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Time to Conversion -->
        <div class="isf-card isf-time-to-conversion">
            <h2><?php esc_html_e('Time to Conversion', 'formflow'); ?></h2>
            <?php if (!empty($time_to_conversion['buckets'])) : ?>
            <div class="isf-time-buckets">
                <?php
                $total = array_sum($time_to_conversion['buckets']);
                foreach ($time_to_conversion['buckets'] as $bucket => $count) :
                    $percentage = $total > 0 ? ($count / $total) * 100 : 0;
                    $labels = [
                        'same_session' => __('Same Session', 'formflow'),
                        'same_day' => __('Same Day', 'formflow'),
                        'within_week' => __('Within Week', 'formflow'),
                        'within_month' => __('Within Month', 'formflow'),
                        'over_month' => __('Over Month', 'formflow'),
                    ];
                ?>
                <div class="isf-bucket">
                    <span class="isf-bucket-label"><?php echo esc_html($labels[$bucket] ?? $bucket); ?></span>
                    <div class="isf-bucket-bar">
                        <div class="isf-bucket-fill" style="width: <?php echo esc_attr($percentage); ?>%;"></div>
                    </div>
                    <span class="isf-bucket-count"><?php echo esc_html($count); ?> (<?php echo esc_html(round($percentage)); ?>%)</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else : ?>
            <p class="isf-no-data"><?php esc_html_e('No conversion timing data available.', 'formflow'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Touchpoint Distribution -->
        <div class="isf-card isf-touchpoint-distribution">
            <h2><?php esc_html_e('Touchpoint Distribution', 'formflow'); ?></h2>
            <?php if (!empty($touchpoint_analysis['buckets'])) : ?>
            <div class="isf-touchpoint-buckets">
                <?php
                $total = array_sum($touchpoint_analysis['buckets']);
                foreach ($touchpoint_analysis['buckets'] as $bucket => $count) :
                    $percentage = $total > 0 ? ($count / $total) * 100 : 0;
                ?>
                <div class="isf-bucket">
                    <span class="isf-bucket-label"><?php echo esc_html($bucket); ?> <?php echo $bucket === '1' ? __('touch', 'formflow') : __('touches', 'formflow'); ?></span>
                    <div class="isf-bucket-bar">
                        <div class="isf-bucket-fill" style="width: <?php echo esc_attr($percentage); ?>%;"></div>
                    </div>
                    <span class="isf-bucket-count"><?php echo esc_html($count); ?> (<?php echo esc_html(round($percentage)); ?>%)</span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="isf-stats-summary">
                <?php printf(
                    esc_html__('Median: %s touchpoints | Max: %s touchpoints', 'formflow'),
                    '<strong>' . esc_html(number_format($touchpoint_analysis['median_touches'] ?? 0, 1)) . '</strong>',
                    '<strong>' . esc_html($touchpoint_analysis['max_touches'] ?? 0) . '</strong>'
                ); ?>
            </p>
            <?php else : ?>
            <p class="isf-no-data"><?php esc_html_e('No touchpoint data available.', 'formflow'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Handoff Performance (if applicable) -->
        <?php if (!empty($handoff_stats['total'])) : ?>
        <div class="isf-card isf-handoff-performance">
            <h2><?php esc_html_e('External Handoff Performance', 'formflow'); ?></h2>
            <div class="isf-handoff-stats">
                <div class="isf-stat">
                    <span class="isf-stat-value"><?php echo esc_html($handoff_stats['total']); ?></span>
                    <span class="isf-stat-label"><?php esc_html_e('Total Handoffs', 'formflow'); ?></span>
                </div>
                <div class="isf-stat">
                    <span class="isf-stat-value"><?php echo esc_html($handoff_stats['by_status']['completed'] ?? 0); ?></span>
                    <span class="isf-stat-label"><?php esc_html_e('Completed', 'formflow'); ?></span>
                </div>
                <div class="isf-stat">
                    <span class="isf-stat-value"><?php echo esc_html($handoff_stats['completion_rate']); ?>%</span>
                    <span class="isf-stat-label"><?php esc_html_e('Completion Rate', 'formflow'); ?></span>
                </div>
                <div class="isf-stat">
                    <span class="isf-stat-value"><?php echo esc_html(number_format($handoff_stats['avg_completion_hours'] ?? 0, 1)); ?>h</span>
                    <span class="isf-stat-label"><?php esc_html_e('Avg. Time to Complete', 'formflow'); ?></span>
                </div>
            </div>
            <div class="isf-handoff-status-breakdown">
                <?php foreach ($handoff_stats['by_status'] as $status => $count) : ?>
                <span class="isf-status-badge isf-status-<?php echo esc_attr($status); ?>">
                    <?php echo esc_html(ucfirst($status)); ?>: <?php echo esc_html($count); ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Attribution Model Explanation -->
    <div class="isf-card isf-model-explanation">
        <h3><?php esc_html_e('Attribution Model Explanation', 'formflow'); ?></h3>
        <div class="isf-model-descriptions">
            <div class="isf-model">
                <strong><?php esc_html_e('First Touch', 'formflow'); ?></strong>
                <p><?php esc_html_e('100% credit to the first marketing touchpoint that brought the visitor.', 'formflow'); ?></p>
            </div>
            <div class="isf-model">
                <strong><?php esc_html_e('Last Touch', 'formflow'); ?></strong>
                <p><?php esc_html_e('100% credit to the last marketing touchpoint before conversion.', 'formflow'); ?></p>
            </div>
            <div class="isf-model">
                <strong><?php esc_html_e('Linear', 'formflow'); ?></strong>
                <p><?php esc_html_e('Credit split equally among all touchpoints in the journey.', 'formflow'); ?></p>
            </div>
            <div class="isf-model">
                <strong><?php esc_html_e('Time Decay', 'formflow'); ?></strong>
                <p><?php esc_html_e('More credit to touchpoints closer to conversion (7-day half-life).', 'formflow'); ?></p>
            </div>
            <div class="isf-model">
                <strong><?php esc_html_e('Position Based', 'formflow'); ?></strong>
                <p><?php esc_html_e('40% to first touch, 40% to last touch, 20% split among middle touchpoints.', 'formflow'); ?></p>
            </div>
        </div>
    </div>

    <?php else : ?>

    <!-- No instance selected -->
    <div class="isf-no-selection">
        <span class="dashicons dashicons-chart-pie"></span>
        <h2><?php esc_html_e('Select a form to view attribution data', 'formflow'); ?></h2>
        <p><?php esc_html_e('Choose a form instance from the dropdown above to see marketing attribution analytics.', 'formflow'); ?></p>
    </div>

    <?php endif; ?>
</div>

<style>
.isf-attribution-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.isf-summary-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.isf-card-icon {
    font-size: 32px;
    color: #2271b1;
}

.isf-card-value {
    display: block;
    font-size: 24px;
    font-weight: 600;
    color: #1d2327;
}

.isf-card-label {
    display: block;
    font-size: 12px;
    color: #646970;
    text-transform: uppercase;
}

.isf-attribution-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-top: 20px;
}

.isf-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
}

.isf-card h2 {
    margin: 0 0 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    font-size: 16px;
}

.isf-bar-chart {
    display: flex;
    align-items: center;
    gap: 10px;
}

.isf-bar {
    height: 20px;
    background: #2271b1;
    border-radius: 2px;
    min-width: 5px;
}

.isf-bar-value {
    font-size: 12px;
    color: #646970;
}

.isf-conversion-rate {
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
}

.isf-conversion-rate.good {
    background: #d1fae5;
    color: #065f46;
}

.isf-conversion-rate.average {
    background: #fef3c7;
    color: #92400e;
}

.isf-conversion-rate.low {
    background: #fee2e2;
    color: #991b1b;
}

.isf-bucket {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.isf-bucket-label {
    width: 100px;
    font-size: 12px;
    color: #646970;
}

.isf-bucket-bar {
    flex: 1;
    height: 16px;
    background: #f0f0f1;
    border-radius: 2px;
    overflow: hidden;
}

.isf-bucket-fill {
    height: 100%;
    background: #2271b1;
}

.isf-bucket-count {
    width: 80px;
    text-align: right;
    font-size: 12px;
    color: #646970;
}

.isf-handoff-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 15px;
}

.isf-stat {
    text-align: center;
}

.isf-stat-value {
    display: block;
    font-size: 24px;
    font-weight: 600;
    color: #1d2327;
}

.isf-stat-label {
    font-size: 11px;
    color: #646970;
    text-transform: uppercase;
}

.isf-status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    margin-right: 8px;
}

.isf-status-redirected {
    background: #e0f2fe;
    color: #0369a1;
}

.isf-status-completed {
    background: #d1fae5;
    color: #065f46;
}

.isf-status-expired {
    background: #f3f4f6;
    color: #6b7280;
}

.isf-no-selection {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.isf-no-selection .dashicons {
    font-size: 48px;
    color: #ccd0d4;
}

.isf-no-data {
    color: #646970;
    font-style: italic;
    text-align: center;
    padding: 20px;
}

.isf-model-explanation {
    margin-top: 20px;
}

.isf-model-descriptions {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 15px;
}

.isf-model {
    padding: 10px;
    background: #f9fafb;
    border-radius: 4px;
}

.isf-model strong {
    display: block;
    margin-bottom: 5px;
    font-size: 13px;
}

.isf-model p {
    margin: 0;
    font-size: 11px;
    color: #646970;
}

.isf-stats-summary {
    margin-top: 15px;
    padding-top: 10px;
    border-top: 1px solid #eee;
    font-size: 12px;
    color: #646970;
}

/* Setup notices */
.isf-setup-notice {
    display: flex;
    gap: 15px;
    padding: 15px 20px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.isf-setup-notice > .dashicons {
    font-size: 24px;
    margin-top: 3px;
}

.isf-notice-warning {
    background: #fff8e5;
    border: 1px solid #f0c36d;
}

.isf-notice-warning > .dashicons {
    color: #9a6700;
}

.isf-notice-success {
    background: #ecfdf5;
    border: 1px solid #6ee7b7;
}

.isf-notice-success > .dashicons {
    color: #059669;
}

.isf-notice-content h3 {
    margin: 0 0 10px;
    font-size: 14px;
}

.isf-notice-content p {
    margin: 0 0 10px;
}

.isf-setup-steps {
    background: rgba(255,255,255,0.5);
    padding: 10px 15px;
    border-radius: 4px;
    margin: 10px 0;
}

.isf-setup-steps h4 {
    margin: 0 0 8px;
    font-size: 12px;
    text-transform: uppercase;
}

.isf-setup-steps ol {
    margin: 0;
    padding-left: 20px;
}

.isf-setup-steps li {
    margin-bottom: 5px;
    font-size: 13px;
}

.isf-filter-row label {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

/* Export actions bar */
.isf-export-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 15px;
    background: #f6f7f7;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    margin-bottom: 20px;
}

.isf-export-label {
    font-weight: 500;
    color: #1d2327;
}

.isf-export-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.isf-export-btn .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.isf-export-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

@media (max-width: 1200px) {
    .isf-attribution-grid {
        grid-template-columns: 1fr;
    }

    .isf-model-descriptions {
        grid-template-columns: repeat(2, 1fr);
    }

    .isf-handoff-stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .isf-export-actions {
        flex-wrap: wrap;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Date preset buttons
    $('.isf-date-preset').on('click', function() {
        var preset = $(this).data('preset');
        var today = new Date();
        var from, to;

        switch(preset) {
            case 'last7':
                from = new Date(today - 7 * 24 * 60 * 60 * 1000);
                to = today;
                break;
            case 'last30':
                from = new Date(today - 30 * 24 * 60 * 60 * 1000);
                to = today;
                break;
            case 'last90':
                from = new Date(today - 90 * 24 * 60 * 60 * 1000);
                to = today;
                break;
            case 'thisyear':
                from = new Date(today.getFullYear(), 0, 1);
                to = today;
                break;
        }

        if (from && to) {
            $('#date_from').val(formatDate(from));
            $('#date_to').val(formatDate(to));
            $('#isf-attribution-form').submit();
        }
    });

    function formatDate(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    // Auto-submit on instance or model change
    $('#instance_id, #attribution_model').on('change', function() {
        if ($('#instance_id').val()) {
            $('#isf-attribution-form').submit();
        }
    });

    // Export buttons
    $('.isf-export-btn').on('click', function() {
        var $btn = $(this);
        var exportType = $btn.data('export');
        var format = $btn.data('format');

        // Build export URL
        var params = new URLSearchParams({
            action: 'formflow_export_attribution',
            export_type: exportType,
            format: format,
            instance_id: $('#instance_id').val(),
            date_from: $('#date_from').val(),
            date_to: $('#date_to').val(),
            attribution_model: $('#attribution_model').val(),
            _wpnonce: '<?php echo wp_create_nonce('isf_export_attribution'); ?>'
        });

        // Disable button during export
        $btn.prop('disabled', true).find('.dashicons').removeClass('dashicons-download dashicons-media-spreadsheet').addClass('dashicons-update spin');

        // Trigger download
        window.location.href = ajaxurl + '?' + params.toString();

        // Re-enable button after delay
        setTimeout(function() {
            $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin').addClass(exportType === 'full_report' ? 'dashicons-media-spreadsheet' : 'dashicons-download');
        }, 2000);
    });
});
</script>

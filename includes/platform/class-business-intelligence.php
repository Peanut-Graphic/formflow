<?php
/**
 * Business Intelligence & Reporting Suite
 *
 * Advanced analytics, reporting, and data visualization platform
 *
 * @package FormFlow
 * @since 2.0.0
 */

namespace ISF\Platform;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Business Intelligence class
 */
class BusinessIntelligence {

    /**
     * Report types
     */
    const REPORT_TYPE_ENROLLMENT = 'enrollment';
    const REPORT_TYPE_CONVERSION = 'conversion';
    const REPORT_TYPE_ATTRIBUTION = 'attribution';
    const REPORT_TYPE_PROGRAM = 'program';
    const REPORT_TYPE_GEOGRAPHIC = 'geographic';
    const REPORT_TYPE_TEMPORAL = 'temporal';
    const REPORT_TYPE_CUSTOM = 'custom';

    /**
     * Export formats
     */
    const EXPORT_CSV = 'csv';
    const EXPORT_EXCEL = 'excel';
    const EXPORT_PDF = 'pdf';
    const EXPORT_JSON = 'json';

    /**
     * Chart types
     */
    const CHART_LINE = 'line';
    const CHART_BAR = 'bar';
    const CHART_PIE = 'pie';
    const CHART_DONUT = 'donut';
    const CHART_AREA = 'area';
    const CHART_FUNNEL = 'funnel';
    const CHART_HEATMAP = 'heatmap';
    const CHART_MAP = 'map';
    const CHART_TABLE = 'table';
    const CHART_KPI = 'kpi';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', [$this, 'register_endpoints']);
        add_action('wp_ajax_isf_bi_data', [$this, 'ajax_get_data']);
        add_action('wp_ajax_formflow_bi_data', [$this, 'ajax_get_data']);
        add_action('wp_ajax_isf_bi_export', [$this, 'ajax_export']);
        add_action('wp_ajax_formflow_bi_export', [$this, 'ajax_export']);
        add_action('wp_ajax_isf_bi_save_report', [$this, 'ajax_save_report']);
        add_action('wp_ajax_formflow_bi_save_report', [$this, 'ajax_save_report']);
        add_action('wp_ajax_isf_bi_schedule_report', [$this, 'ajax_schedule_report']);
        add_action('wp_ajax_formflow_bi_schedule_report', [$this, 'ajax_schedule_report']);
        add_action('isf_scheduled_report', [$this, 'run_scheduled_report'], 10, 1);
    }

    /**
     * Register REST API endpoints
     */
    public function register_endpoints(): void {
        add_action('rest_api_init', function() {
            register_rest_route('formflow/v2', '/bi/reports', [
                'methods' => 'GET',
                'callback' => [$this, 'api_list_reports'],
                'permission_callback' => [$this, 'check_api_permission']
            ]);

            register_rest_route('formflow/v2', '/bi/reports/(?P<id>\d+)', [
                'methods' => 'GET',
                'callback' => [$this, 'api_get_report'],
                'permission_callback' => [$this, 'check_api_permission']
            ]);

            register_rest_route('formflow/v2', '/bi/query', [
                'methods' => 'POST',
                'callback' => [$this, 'api_run_query'],
                'permission_callback' => [$this, 'check_api_permission']
            ]);

            register_rest_route('formflow/v2', '/bi/dashboards', [
                'methods' => ['GET', 'POST'],
                'callback' => [$this, 'api_dashboards'],
                'permission_callback' => [$this, 'check_api_permission']
            ]);
        });
    }

    /**
     * Check API permission
     */
    public function check_api_permission(\WP_REST_Request $request): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get enrollment analytics
     */
    public function get_enrollment_analytics(array $params = []): array {
        global $wpdb;

        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
            'instance_id' => null,
            'utility' => null,
            'program' => null,
            'group_by' => 'day' // day, week, month, hour
        ];
        $params = wp_parse_args($params, $defaults);

        $where = ['1=1'];
        $where_values = [];

        // Date range
        $where[] = 'DATE(e.created_at) >= %s';
        $where_values[] = $params['start_date'];
        $where[] = 'DATE(e.created_at) <= %s';
        $where_values[] = $params['end_date'];

        // Instance filter
        if ($params['instance_id']) {
            $where[] = 'e.instance_id = %d';
            $where_values[] = $params['instance_id'];
        }

        // Utility filter
        if ($params['utility']) {
            $where[] = 'i.utility = %s';
            $where_values[] = $params['utility'];
        }

        // Program filter
        if ($params['program']) {
            $where[] = 'e.program = %s';
            $where_values[] = $params['program'];
        }

        // Date grouping
        $date_format = match($params['group_by']) {
            'hour' => '%Y-%m-%d %H:00',
            'week' => '%x-W%v',
            'month' => '%Y-%m',
            default => '%Y-%m-%d'
        };

        $enrollments_table = $wpdb->prefix . 'isf_enrollments';
        $instances_table = $wpdb->prefix . 'isf_instances';

        // Main query
        $query = $wpdb->prepare(
            "SELECT
                DATE_FORMAT(e.created_at, '{$date_format}') as period,
                COUNT(*) as total_enrollments,
                COUNT(CASE WHEN e.status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN e.status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN e.status = 'failed' THEN 1 END) as failed,
                AVG(TIMESTAMPDIFF(SECOND, e.created_at, e.completed_at)) as avg_completion_time,
                COUNT(DISTINCT e.account_number) as unique_accounts,
                COUNT(DISTINCT e.instance_id) as instances_used
             FROM {$enrollments_table} e
             LEFT JOIN {$instances_table} i ON e.instance_id = i.id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY period
             ORDER BY period ASC",
            ...$where_values
        );

        $results = $wpdb->get_results($query, ARRAY_A);

        // Calculate summary stats
        $summary = [
            'total_enrollments' => array_sum(array_column($results, 'total_enrollments')),
            'completed' => array_sum(array_column($results, 'completed')),
            'pending' => array_sum(array_column($results, 'pending')),
            'failed' => array_sum(array_column($results, 'failed')),
            'completion_rate' => 0,
            'avg_completion_time' => 0,
            'unique_accounts' => 0
        ];

        if ($summary['total_enrollments'] > 0) {
            $summary['completion_rate'] = round(($summary['completed'] / $summary['total_enrollments']) * 100, 2);
        }

        // Get unique accounts total
        $unique_query = $wpdb->prepare(
            "SELECT COUNT(DISTINCT account_number) as unique_accounts
             FROM {$enrollments_table} e
             LEFT JOIN {$instances_table} i ON e.instance_id = i.id
             WHERE " . implode(' AND ', $where),
            ...$where_values
        );
        $summary['unique_accounts'] = (int) $wpdb->get_var($unique_query);

        // Calculate average completion time
        $non_null_times = array_filter(array_column($results, 'avg_completion_time'));
        if (!empty($non_null_times)) {
            $summary['avg_completion_time'] = round(array_sum($non_null_times) / count($non_null_times));
        }

        return [
            'summary' => $summary,
            'timeline' => $results,
            'params' => $params
        ];
    }

    /**
     * Get conversion funnel data
     */
    public function get_conversion_funnel(array $params = []): array {
        global $wpdb;

        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
            'instance_id' => null
        ];
        $params = wp_parse_args($params, $defaults);

        $analytics_table = $wpdb->prefix . 'isf_analytics';

        $where = ['1=1'];
        $where_values = [];

        $where[] = 'DATE(created_at) >= %s';
        $where_values[] = $params['start_date'];
        $where[] = 'DATE(created_at) <= %s';
        $where_values[] = $params['end_date'];

        if ($params['instance_id']) {
            $where[] = 'instance_id = %d';
            $where_values[] = $params['instance_id'];
        }

        // Get funnel steps
        $query = $wpdb->prepare(
            "SELECT
                step_number,
                COUNT(DISTINCT session_id) as sessions,
                COUNT(*) as total_visits,
                AVG(time_on_step) as avg_time_on_step
             FROM {$analytics_table}
             WHERE " . implode(' AND ', $where) . "
             GROUP BY step_number
             ORDER BY step_number ASC",
            ...$where_values
        );

        $steps = $wpdb->get_results($query, ARRAY_A);

        // Calculate conversion rates between steps
        $funnel = [];
        $previous_sessions = null;

        foreach ($steps as $step) {
            $entry = [
                'step' => (int) $step['step_number'],
                'sessions' => (int) $step['sessions'],
                'total_visits' => (int) $step['total_visits'],
                'avg_time_on_step' => round((float) $step['avg_time_on_step'], 1),
                'conversion_rate' => 100,
                'dropoff_rate' => 0
            ];

            if ($previous_sessions !== null && $previous_sessions > 0) {
                $entry['conversion_rate'] = round(($step['sessions'] / $previous_sessions) * 100, 2);
                $entry['dropoff_rate'] = round(100 - $entry['conversion_rate'], 2);
            }

            $previous_sessions = (int) $step['sessions'];
            $funnel[] = $entry;
        }

        // Calculate overall funnel metrics
        $overall = [
            'total_starts' => $funnel[0]['sessions'] ?? 0,
            'total_completions' => end($funnel)['sessions'] ?? 0,
            'overall_conversion_rate' => 0,
            'biggest_dropoff_step' => null,
            'biggest_dropoff_rate' => 0
        ];

        if ($overall['total_starts'] > 0) {
            $overall['overall_conversion_rate'] = round(
                ($overall['total_completions'] / $overall['total_starts']) * 100,
                2
            );
        }

        // Find biggest dropoff
        foreach ($funnel as $step) {
            if ($step['dropoff_rate'] > $overall['biggest_dropoff_rate']) {
                $overall['biggest_dropoff_rate'] = $step['dropoff_rate'];
                $overall['biggest_dropoff_step'] = $step['step'];
            }
        }

        return [
            'funnel' => $funnel,
            'overall' => $overall,
            'params' => $params
        ];
    }

    /**
     * Get attribution analytics
     */
    public function get_attribution_analytics(array $params = []): array {
        global $wpdb;

        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
            'model' => 'last_touch', // first_touch, last_touch, linear
            'instance_id' => null
        ];
        $params = wp_parse_args($params, $defaults);

        $enrollments_table = $wpdb->prefix . 'isf_enrollments';
        $analytics_table = $wpdb->prefix . 'isf_analytics';

        $where = ['e.status = %s'];
        $where_values = ['completed'];

        $where[] = 'DATE(e.created_at) >= %s';
        $where_values[] = $params['start_date'];
        $where[] = 'DATE(e.created_at) <= %s';
        $where_values[] = $params['end_date'];

        if ($params['instance_id']) {
            $where[] = 'e.instance_id = %d';
            $where_values[] = $params['instance_id'];
        }

        // Source attribution
        $source_query = $wpdb->prepare(
            "SELECT
                COALESCE(e.utm_source, 'Direct') as source,
                COUNT(*) as enrollments,
                COUNT(DISTINCT e.account_number) as unique_accounts
             FROM {$enrollments_table} e
             WHERE " . implode(' AND ', $where) . "
             GROUP BY source
             ORDER BY enrollments DESC",
            ...$where_values
        );

        $by_source = $wpdb->get_results($source_query, ARRAY_A);

        // Medium attribution
        $medium_query = $wpdb->prepare(
            "SELECT
                COALESCE(e.utm_medium, 'None') as medium,
                COUNT(*) as enrollments
             FROM {$enrollments_table} e
             WHERE " . implode(' AND ', $where) . "
             GROUP BY medium
             ORDER BY enrollments DESC",
            ...$where_values
        );

        $by_medium = $wpdb->get_results($medium_query, ARRAY_A);

        // Campaign attribution
        $campaign_query = $wpdb->prepare(
            "SELECT
                COALESCE(e.utm_campaign, 'No Campaign') as campaign,
                COUNT(*) as enrollments
             FROM {$enrollments_table} e
             WHERE " . implode(' AND ', $where) . "
             GROUP BY campaign
             ORDER BY enrollments DESC
             LIMIT 20",
            ...$where_values
        );

        $by_campaign = $wpdb->get_results($campaign_query, ARRAY_A);

        // Device type breakdown
        $device_query = $wpdb->prepare(
            "SELECT
                COALESCE(e.device_type, 'Unknown') as device,
                COUNT(*) as enrollments
             FROM {$enrollments_table} e
             WHERE " . implode(' AND ', $where) . "
             GROUP BY device
             ORDER BY enrollments DESC",
            ...$where_values
        );

        $by_device = $wpdb->get_results($device_query, ARRAY_A);

        // Total for percentage calculations
        $total_enrollments = array_sum(array_column($by_source, 'enrollments'));

        // Add percentages
        foreach ($by_source as &$item) {
            $item['percentage'] = $total_enrollments > 0
                ? round(($item['enrollments'] / $total_enrollments) * 100, 2)
                : 0;
        }

        foreach ($by_medium as &$item) {
            $item['percentage'] = $total_enrollments > 0
                ? round(($item['enrollments'] / $total_enrollments) * 100, 2)
                : 0;
        }

        return [
            'by_source' => $by_source,
            'by_medium' => $by_medium,
            'by_campaign' => $by_campaign,
            'by_device' => $by_device,
            'total_enrollments' => $total_enrollments,
            'attribution_model' => $params['model'],
            'params' => $params
        ];
    }

    /**
     * Get geographic analytics
     */
    public function get_geographic_analytics(array $params = []): array {
        global $wpdb;

        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
            'instance_id' => null,
            'granularity' => 'state' // state, zip, city
        ];
        $params = wp_parse_args($params, $defaults);

        $enrollments_table = $wpdb->prefix . 'isf_enrollments';

        $where = ['1=1'];
        $where_values = [];

        $where[] = 'DATE(created_at) >= %s';
        $where_values[] = $params['start_date'];
        $where[] = 'DATE(created_at) <= %s';
        $where_values[] = $params['end_date'];

        if ($params['instance_id']) {
            $where[] = 'instance_id = %d';
            $where_values[] = $params['instance_id'];
        }

        $group_field = match($params['granularity']) {
            'zip' => 'postal_code',
            'city' => 'city',
            default => 'state'
        };

        $query = $wpdb->prepare(
            "SELECT
                {$group_field} as location,
                COUNT(*) as enrollments,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(DISTINCT account_number) as unique_accounts
             FROM {$enrollments_table}
             WHERE " . implode(' AND ', $where) . "
                AND {$group_field} IS NOT NULL
                AND {$group_field} != ''
             GROUP BY {$group_field}
             ORDER BY enrollments DESC
             LIMIT 100",
            ...$where_values
        );

        $results = $wpdb->get_results($query, ARRAY_A);

        // Add completion rates
        foreach ($results as &$row) {
            $row['completion_rate'] = $row['enrollments'] > 0
                ? round(($row['completed'] / $row['enrollments']) * 100, 2)
                : 0;
        }

        // Get overall geographic distribution
        $total = array_sum(array_column($results, 'enrollments'));
        foreach ($results as &$row) {
            $row['percentage'] = $total > 0
                ? round(($row['enrollments'] / $total) * 100, 2)
                : 0;
        }

        return [
            'distribution' => $results,
            'total_enrollments' => $total,
            'granularity' => $params['granularity'],
            'params' => $params
        ];
    }

    /**
     * Get program performance analytics
     */
    public function get_program_analytics(array $params = []): array {
        global $wpdb;

        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
            'utility' => null
        ];
        $params = wp_parse_args($params, $defaults);

        $enrollments_table = $wpdb->prefix . 'isf_enrollments';
        $instances_table = $wpdb->prefix . 'isf_instances';

        $where = ['1=1'];
        $where_values = [];

        $where[] = 'DATE(e.created_at) >= %s';
        $where_values[] = $params['start_date'];
        $where[] = 'DATE(e.created_at) <= %s';
        $where_values[] = $params['end_date'];

        if ($params['utility']) {
            $where[] = 'i.utility = %s';
            $where_values[] = $params['utility'];
        }

        $query = $wpdb->prepare(
            "SELECT
                COALESCE(e.program, i.name) as program,
                i.utility,
                COUNT(*) as total_enrollments,
                COUNT(CASE WHEN e.status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN e.status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN e.status = 'failed' THEN 1 END) as failed,
                COUNT(DISTINCT e.account_number) as unique_accounts,
                AVG(TIMESTAMPDIFF(SECOND, e.created_at, e.completed_at)) as avg_completion_time,
                MIN(e.created_at) as first_enrollment,
                MAX(e.created_at) as last_enrollment
             FROM {$enrollments_table} e
             LEFT JOIN {$instances_table} i ON e.instance_id = i.id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY program, i.utility
             ORDER BY total_enrollments DESC",
            ...$where_values
        );

        $programs = $wpdb->get_results($query, ARRAY_A);

        // Calculate completion rates and format times
        foreach ($programs as &$program) {
            $program['completion_rate'] = $program['total_enrollments'] > 0
                ? round(($program['completed'] / $program['total_enrollments']) * 100, 2)
                : 0;
            $program['avg_completion_time'] = $program['avg_completion_time']
                ? $this->format_duration((int) $program['avg_completion_time'])
                : 'N/A';
        }

        // Summary stats
        $summary = [
            'total_programs' => count($programs),
            'total_enrollments' => array_sum(array_column($programs, 'total_enrollments')),
            'total_completed' => array_sum(array_column($programs, 'completed')),
            'best_performing' => null,
            'worst_performing' => null
        ];

        if (!empty($programs)) {
            usort($programs, fn($a, $b) => $b['completion_rate'] <=> $a['completion_rate']);
            $summary['best_performing'] = $programs[0]['program'];
            $summary['worst_performing'] = end($programs)['program'];
        }

        return [
            'programs' => $programs,
            'summary' => $summary,
            'params' => $params
        ];
    }

    /**
     * Get temporal analytics (time-based patterns)
     */
    public function get_temporal_analytics(array $params = []): array {
        global $wpdb;

        $defaults = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d'),
            'instance_id' => null
        ];
        $params = wp_parse_args($params, $defaults);

        $enrollments_table = $wpdb->prefix . 'isf_enrollments';

        $where = ['1=1'];
        $where_values = [];

        $where[] = 'DATE(created_at) >= %s';
        $where_values[] = $params['start_date'];
        $where[] = 'DATE(created_at) <= %s';
        $where_values[] = $params['end_date'];

        if ($params['instance_id']) {
            $where[] = 'instance_id = %d';
            $where_values[] = $params['instance_id'];
        }

        // Hour of day distribution
        $hourly_query = $wpdb->prepare(
            "SELECT
                HOUR(created_at) as hour,
                COUNT(*) as enrollments
             FROM {$enrollments_table}
             WHERE " . implode(' AND ', $where) . "
             GROUP BY hour
             ORDER BY hour",
            ...$where_values
        );

        $by_hour = $wpdb->get_results($hourly_query, ARRAY_A);

        // Day of week distribution
        $daily_query = $wpdb->prepare(
            "SELECT
                DAYOFWEEK(created_at) as day_num,
                DAYNAME(created_at) as day_name,
                COUNT(*) as enrollments
             FROM {$enrollments_table}
             WHERE " . implode(' AND ', $where) . "
             GROUP BY day_num, day_name
             ORDER BY day_num",
            ...$where_values
        );

        $by_day = $wpdb->get_results($daily_query, ARRAY_A);

        // Week of year trend
        $weekly_query = $wpdb->prepare(
            "SELECT
                YEARWEEK(created_at, 1) as year_week,
                MIN(DATE(created_at)) as week_start,
                COUNT(*) as enrollments
             FROM {$enrollments_table}
             WHERE " . implode(' AND ', $where) . "
             GROUP BY year_week
             ORDER BY year_week",
            ...$where_values
        );

        $by_week = $wpdb->get_results($weekly_query, ARRAY_A);

        // Find peak times
        $peak_hour = null;
        $peak_day = null;
        $max_hour_enrollments = 0;
        $max_day_enrollments = 0;

        foreach ($by_hour as $hour) {
            if ($hour['enrollments'] > $max_hour_enrollments) {
                $max_hour_enrollments = (int) $hour['enrollments'];
                $peak_hour = (int) $hour['hour'];
            }
        }

        foreach ($by_day as $day) {
            if ($day['enrollments'] > $max_day_enrollments) {
                $max_day_enrollments = (int) $day['enrollments'];
                $peak_day = $day['day_name'];
            }
        }

        return [
            'by_hour' => $by_hour,
            'by_day' => $by_day,
            'by_week' => $by_week,
            'insights' => [
                'peak_hour' => $peak_hour !== null ? sprintf('%02d:00', $peak_hour) : 'N/A',
                'peak_day' => $peak_day ?? 'N/A',
                'peak_hour_enrollments' => $max_hour_enrollments,
                'peak_day_enrollments' => $max_day_enrollments
            ],
            'params' => $params
        ];
    }

    /**
     * Get KPI dashboard data
     */
    public function get_kpi_dashboard(array $params = []): array {
        global $wpdb;

        $defaults = [
            'period' => '30d', // 7d, 30d, 90d, ytd, all
            'instance_id' => null,
            'compare_previous' => true
        ];
        $params = wp_parse_args($params, $defaults);

        // Calculate date ranges
        $dates = $this->get_period_dates($params['period']);
        $current_start = $dates['start'];
        $current_end = $dates['end'];

        $previous_dates = $this->get_previous_period_dates($params['period']);
        $previous_start = $previous_dates['start'];
        $previous_end = $previous_dates['end'];

        $enrollments_table = $wpdb->prefix . 'isf_enrollments';

        // Current period metrics
        $current = $this->calculate_period_metrics(
            $current_start,
            $current_end,
            $params['instance_id']
        );

        // Previous period metrics for comparison
        $previous = null;
        if ($params['compare_previous']) {
            $previous = $this->calculate_period_metrics(
                $previous_start,
                $previous_end,
                $params['instance_id']
            );
        }

        // Calculate changes
        $kpis = [
            'total_enrollments' => [
                'value' => $current['total_enrollments'],
                'previous' => $previous['total_enrollments'] ?? null,
                'change' => $this->calculate_change(
                    $current['total_enrollments'],
                    $previous['total_enrollments'] ?? null
                ),
                'label' => __('Total Enrollments', 'formflow'),
                'format' => 'number'
            ],
            'completion_rate' => [
                'value' => $current['completion_rate'],
                'previous' => $previous['completion_rate'] ?? null,
                'change' => $this->calculate_change(
                    $current['completion_rate'],
                    $previous['completion_rate'] ?? null
                ),
                'label' => __('Completion Rate', 'formflow'),
                'format' => 'percentage'
            ],
            'unique_accounts' => [
                'value' => $current['unique_accounts'],
                'previous' => $previous['unique_accounts'] ?? null,
                'change' => $this->calculate_change(
                    $current['unique_accounts'],
                    $previous['unique_accounts'] ?? null
                ),
                'label' => __('Unique Accounts', 'formflow'),
                'format' => 'number'
            ],
            'avg_completion_time' => [
                'value' => $current['avg_completion_time'],
                'previous' => $previous['avg_completion_time'] ?? null,
                'change' => $this->calculate_change(
                    $current['avg_completion_time'],
                    $previous['avg_completion_time'] ?? null,
                    true // Lower is better
                ),
                'label' => __('Avg. Completion Time', 'formflow'),
                'format' => 'duration'
            ],
            'daily_average' => [
                'value' => $current['daily_average'],
                'previous' => $previous['daily_average'] ?? null,
                'change' => $this->calculate_change(
                    $current['daily_average'],
                    $previous['daily_average'] ?? null
                ),
                'label' => __('Daily Average', 'formflow'),
                'format' => 'decimal'
            ]
        ];

        return [
            'kpis' => $kpis,
            'period' => [
                'label' => $this->get_period_label($params['period']),
                'start' => $current_start,
                'end' => $current_end
            ],
            'params' => $params
        ];
    }

    /**
     * Calculate period metrics
     */
    private function calculate_period_metrics(string $start_date, string $end_date, ?int $instance_id = null): array {
        global $wpdb;

        $enrollments_table = $wpdb->prefix . 'isf_enrollments';

        $where = ['DATE(created_at) >= %s', 'DATE(created_at) <= %s'];
        $where_values = [$start_date, $end_date];

        if ($instance_id) {
            $where[] = 'instance_id = %d';
            $where_values[] = $instance_id;
        }

        $query = $wpdb->prepare(
            "SELECT
                COUNT(*) as total_enrollments,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(DISTINCT account_number) as unique_accounts,
                AVG(CASE WHEN status = 'completed' THEN TIMESTAMPDIFF(SECOND, created_at, completed_at) END) as avg_completion_time
             FROM {$enrollments_table}
             WHERE " . implode(' AND ', $where),
            ...$where_values
        );

        $result = $wpdb->get_row($query, ARRAY_A);

        $days = max(1, (strtotime($end_date) - strtotime($start_date)) / 86400);

        return [
            'total_enrollments' => (int) ($result['total_enrollments'] ?? 0),
            'completed' => (int) ($result['completed'] ?? 0),
            'completion_rate' => $result['total_enrollments'] > 0
                ? round(($result['completed'] / $result['total_enrollments']) * 100, 2)
                : 0,
            'unique_accounts' => (int) ($result['unique_accounts'] ?? 0),
            'avg_completion_time' => (int) ($result['avg_completion_time'] ?? 0),
            'daily_average' => round($result['total_enrollments'] / $days, 1)
        ];
    }

    /**
     * Get period dates
     */
    private function get_period_dates(string $period): array {
        $end = date('Y-m-d');

        return match($period) {
            '7d' => ['start' => date('Y-m-d', strtotime('-7 days')), 'end' => $end],
            '30d' => ['start' => date('Y-m-d', strtotime('-30 days')), 'end' => $end],
            '90d' => ['start' => date('Y-m-d', strtotime('-90 days')), 'end' => $end],
            'ytd' => ['start' => date('Y-01-01'), 'end' => $end],
            'all' => ['start' => '2020-01-01', 'end' => $end],
            default => ['start' => date('Y-m-d', strtotime('-30 days')), 'end' => $end]
        };
    }

    /**
     * Get previous period dates for comparison
     */
    private function get_previous_period_dates(string $period): array {
        $current = $this->get_period_dates($period);
        $days = (strtotime($current['end']) - strtotime($current['start'])) / 86400;

        return [
            'start' => date('Y-m-d', strtotime($current['start'] . " - {$days} days")),
            'end' => date('Y-m-d', strtotime($current['start'] . ' - 1 day'))
        ];
    }

    /**
     * Calculate percentage change
     */
    private function calculate_change($current, $previous, bool $lower_is_better = false): ?array {
        if ($previous === null || $previous == 0) {
            return null;
        }

        $change = (($current - $previous) / $previous) * 100;
        $direction = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat');

        // For metrics where lower is better (like completion time), flip the sentiment
        $sentiment = 'neutral';
        if ($change != 0) {
            if ($lower_is_better) {
                $sentiment = $change < 0 ? 'positive' : 'negative';
            } else {
                $sentiment = $change > 0 ? 'positive' : 'negative';
            }
        }

        return [
            'value' => round(abs($change), 1),
            'direction' => $direction,
            'sentiment' => $sentiment
        ];
    }

    /**
     * Get period label
     */
    private function get_period_label(string $period): string {
        return match($period) {
            '7d' => __('Last 7 Days', 'formflow'),
            '30d' => __('Last 30 Days', 'formflow'),
            '90d' => __('Last 90 Days', 'formflow'),
            'ytd' => __('Year to Date', 'formflow'),
            'all' => __('All Time', 'formflow'),
            default => __('Last 30 Days', 'formflow')
        };
    }

    /**
     * Format duration in human-readable format
     */
    private function format_duration(int $seconds): string {
        if ($seconds < 60) {
            return sprintf(__('%d seconds', 'formflow'), $seconds);
        } elseif ($seconds < 3600) {
            $minutes = round($seconds / 60);
            return sprintf(__('%d minutes', 'formflow'), $minutes);
        } else {
            $hours = floor($seconds / 3600);
            $minutes = round(($seconds % 3600) / 60);
            return sprintf(__('%d hr %d min', 'formflow'), $hours, $minutes);
        }
    }

    /**
     * Create custom report
     */
    public function create_report(array $data): int|\WP_Error {
        global $wpdb;

        $required = ['name', 'type', 'config'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new \WP_Error('missing_field', sprintf(__('Missing required field: %s', 'formflow'), $field));
            }
        }

        $table = $wpdb->prefix . 'isf_reports';

        $inserted = $wpdb->insert(
            $table,
            [
                'name' => sanitize_text_field($data['name']),
                'description' => sanitize_textarea_field($data['description'] ?? ''),
                'type' => sanitize_text_field($data['type']),
                'config' => wp_json_encode($data['config']),
                'created_by' => get_current_user_id(),
                'is_public' => !empty($data['is_public']) ? 1 : 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );

        if (!$inserted) {
            return new \WP_Error('db_error', __('Failed to create report', 'formflow'));
        }

        return $wpdb->insert_id;
    }

    /**
     * Get saved reports
     */
    public function get_saved_reports(?int $user_id = null): array {
        global $wpdb;

        $table = $wpdb->prefix . 'isf_reports';

        $where = ['1=1'];
        $where_values = [];

        if ($user_id) {
            $where[] = '(created_by = %d OR is_public = 1)';
            $where_values[] = $user_id;
        }

        $query = $wpdb->prepare(
            "SELECT r.*, u.display_name as created_by_name
             FROM {$table} r
             LEFT JOIN {$wpdb->users} u ON r.created_by = u.ID
             WHERE " . implode(' AND ', $where) . "
             ORDER BY r.updated_at DESC",
            ...$where_values
        );

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Schedule report for automated delivery
     */
    public function schedule_report(array $data): int|\WP_Error {
        global $wpdb;

        $required = ['report_id', 'frequency', 'recipients'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new \WP_Error('missing_field', sprintf(__('Missing required field: %s', 'formflow'), $field));
            }
        }

        // Validate recipients
        $recipients = array_map('sanitize_email', (array) $data['recipients']);
        $recipients = array_filter($recipients, 'is_email');

        if (empty($recipients)) {
            return new \WP_Error('invalid_recipients', __('No valid email addresses provided', 'formflow'));
        }

        $table = $wpdb->prefix . 'isf_scheduled_reports';

        $inserted = $wpdb->insert(
            $table,
            [
                'report_id' => intval($data['report_id']),
                'frequency' => sanitize_text_field($data['frequency']), // daily, weekly, monthly
                'recipients' => wp_json_encode($recipients),
                'export_format' => sanitize_text_field($data['export_format'] ?? 'pdf'),
                'next_run' => $this->calculate_next_run($data['frequency']),
                'is_active' => 1,
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s']
        );

        if (!$inserted) {
            return new \WP_Error('db_error', __('Failed to schedule report', 'formflow'));
        }

        $schedule_id = $wpdb->insert_id;

        // Schedule the WordPress cron event
        if (!wp_next_scheduled('isf_scheduled_report', [$schedule_id])) {
            wp_schedule_single_event(
                strtotime($this->calculate_next_run($data['frequency'])),
                'isf_scheduled_report',
                [$schedule_id]
            );
        }

        return $schedule_id;
    }

    /**
     * Calculate next run time
     */
    private function calculate_next_run(string $frequency): string {
        return match($frequency) {
            'daily' => date('Y-m-d H:i:s', strtotime('tomorrow 6:00')),
            'weekly' => date('Y-m-d H:i:s', strtotime('next monday 6:00')),
            'monthly' => date('Y-m-d H:i:s', strtotime('first day of next month 6:00')),
            default => date('Y-m-d H:i:s', strtotime('+1 day'))
        };
    }

    /**
     * Run scheduled report
     */
    public function run_scheduled_report(int $schedule_id): void {
        global $wpdb;

        $table = $wpdb->prefix . 'isf_scheduled_reports';
        $reports_table = $wpdb->prefix . 'isf_reports';

        $schedule = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, r.name as report_name, r.type, r.config
             FROM {$table} s
             JOIN {$reports_table} r ON s.report_id = r.id
             WHERE s.id = %d AND s.is_active = 1",
            $schedule_id
        ));

        if (!$schedule) {
            return;
        }

        try {
            // Generate report data
            $config = json_decode($schedule->config, true);
            $report_data = $this->generate_report_data($schedule->type, $config);

            // Export to file
            $file_path = $this->export_to_file(
                $report_data,
                $schedule->export_format,
                $schedule->report_name
            );

            // Send to recipients
            $recipients = json_decode($schedule->recipients, true);
            $this->send_report_email($recipients, $schedule->report_name, $file_path);

            // Update last run and schedule next
            $wpdb->update(
                $table,
                [
                    'last_run' => current_time('mysql'),
                    'next_run' => $this->calculate_next_run($schedule->frequency),
                    'run_count' => $schedule->run_count + 1
                ],
                ['id' => $schedule_id],
                ['%s', '%s', '%d'],
                ['%d']
            );

            // Schedule next run
            wp_schedule_single_event(
                strtotime($this->calculate_next_run($schedule->frequency)),
                'isf_scheduled_report',
                [$schedule_id]
            );

            // Cleanup temp file
            if (file_exists($file_path)) {
                unlink($file_path);
            }

        } catch (\Exception $e) {
            error_log('FormFlow BI: Failed to run scheduled report ' . $schedule_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Generate report data based on type
     */
    public function generate_report_data(string $type, array $config): array {
        return match($type) {
            self::REPORT_TYPE_ENROLLMENT => $this->get_enrollment_analytics($config),
            self::REPORT_TYPE_CONVERSION => $this->get_conversion_funnel($config),
            self::REPORT_TYPE_ATTRIBUTION => $this->get_attribution_analytics($config),
            self::REPORT_TYPE_GEOGRAPHIC => $this->get_geographic_analytics($config),
            self::REPORT_TYPE_PROGRAM => $this->get_program_analytics($config),
            self::REPORT_TYPE_TEMPORAL => $this->get_temporal_analytics($config),
            default => []
        };
    }

    /**
     * Export report to file
     */
    public function export_to_file(array $data, string $format, string $filename): string {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/isf-exports';

        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        $safe_filename = sanitize_file_name($filename . '-' . date('Y-m-d-His'));
        $file_path = $export_dir . '/' . $safe_filename;

        switch ($format) {
            case self::EXPORT_CSV:
                $file_path .= '.csv';
                $this->export_csv($data, $file_path);
                break;

            case self::EXPORT_JSON:
                $file_path .= '.json';
                file_put_contents($file_path, wp_json_encode($data, JSON_PRETTY_PRINT));
                break;

            case self::EXPORT_EXCEL:
                $file_path .= '.xlsx';
                $this->export_excel($data, $file_path);
                break;

            case self::EXPORT_PDF:
            default:
                $file_path .= '.html'; // Simplified - would need PDF library
                $this->export_html($data, $file_path, $filename);
                break;
        }

        return $file_path;
    }

    /**
     * Export to CSV
     */
    private function export_csv(array $data, string $file_path): void {
        $fp = fopen($file_path, 'w');

        // Find the main data array
        $rows = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                $rows = $value;
                break;
            }
        }

        if (!empty($rows)) {
            // Write header
            fputcsv($fp, array_keys($rows[0]));

            // Write data rows
            foreach ($rows as $row) {
                fputcsv($fp, array_values($row));
            }
        }

        fclose($fp);
    }

    /**
     * Export to Excel (simplified - outputs CSV with xlsx extension)
     */
    private function export_excel(array $data, string $file_path): void {
        // For full Excel support, would need PhpSpreadsheet library
        // This is a simplified version that creates CSV
        $this->export_csv($data, str_replace('.xlsx', '.csv', $file_path));
        rename(str_replace('.xlsx', '.csv', $file_path), $file_path);
    }

    /**
     * Export to HTML (for PDF-like output)
     */
    private function export_html(array $data, string $file_path, string $title): void {
        $html = '<!DOCTYPE html><html><head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<title>' . esc_html($title) . '</title>';
        $html .= '<style>';
        $html .= 'body { font-family: Arial, sans-serif; margin: 40px; }';
        $html .= 'h1 { color: #333; }';
        $html .= 'table { border-collapse: collapse; width: 100%; margin: 20px 0; }';
        $html .= 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
        $html .= 'th { background: #f5f5f5; }';
        $html .= '.summary { background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0; }';
        $html .= '</style></head><body>';

        $html .= '<h1>' . esc_html($title) . '</h1>';
        $html .= '<p>Generated: ' . date('F j, Y g:i A') . '</p>';

        // Render summary if present
        if (isset($data['summary'])) {
            $html .= '<div class="summary"><h3>Summary</h3><ul>';
            foreach ($data['summary'] as $key => $value) {
                $html .= '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', $key))) . ':</strong> ';
                $html .= esc_html(is_array($value) ? wp_json_encode($value) : $value) . '</li>';
            }
            $html .= '</ul></div>';
        }

        // Render data tables
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                $html .= '<h3>' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</h3>';
                $html .= '<table><thead><tr>';
                foreach (array_keys($value[0]) as $header) {
                    $html .= '<th>' . esc_html(ucwords(str_replace('_', ' ', $header))) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($value as $row) {
                    $html .= '<tr>';
                    foreach ($row as $cell) {
                        $html .= '<td>' . esc_html(is_array($cell) ? wp_json_encode($cell) : $cell) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }
        }

        $html .= '</body></html>';

        file_put_contents($file_path, $html);
    }

    /**
     * Send report via email
     */
    private function send_report_email(array $recipients, string $report_name, string $file_path): bool {
        $subject = sprintf(__('[FormFlow] Scheduled Report: %s', 'formflow'), $report_name);

        $message = sprintf(
            __("Your scheduled report '%s' is attached.\n\nGenerated: %s", 'formflow'),
            $report_name,
            date('F j, Y g:i A')
        );

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $attachments = [$file_path];

        return wp_mail($recipients, $subject, $message, $headers, $attachments);
    }

    /**
     * AJAX handler for getting data
     */
    public function ajax_get_data(): void {
        check_ajax_referer('isf_bi_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'formflow')]);
        }

        $report_type = sanitize_text_field($_POST['report_type'] ?? '');
        $params = [];

        // Parse common params
        if (!empty($_POST['start_date'])) {
            $params['start_date'] = sanitize_text_field($_POST['start_date']);
        }
        if (!empty($_POST['end_date'])) {
            $params['end_date'] = sanitize_text_field($_POST['end_date']);
        }
        if (!empty($_POST['instance_id'])) {
            $params['instance_id'] = intval($_POST['instance_id']);
        }

        $data = $this->generate_report_data($report_type, $params);

        wp_send_json_success($data);
    }

    /**
     * AJAX handler for export
     */
    public function ajax_export(): void {
        check_ajax_referer('isf_bi_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'formflow')]);
        }

        $report_type = sanitize_text_field($_POST['report_type'] ?? '');
        $export_format = sanitize_text_field($_POST['format'] ?? 'csv');
        $params = json_decode(stripslashes($_POST['params'] ?? '{}'), true);

        $data = $this->generate_report_data($report_type, $params);
        $file_path = $this->export_to_file($data, $export_format, 'formflow-report');

        // Return download URL
        $upload_dir = wp_upload_dir();
        $file_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);

        wp_send_json_success([
            'download_url' => $file_url,
            'filename' => basename($file_path)
        ]);
    }

    /**
     * Get available chart configurations
     */
    public function get_chart_configs(): array {
        return [
            'enrollment_trend' => [
                'type' => self::CHART_LINE,
                'title' => __('Enrollment Trend', 'formflow'),
                'data_source' => self::REPORT_TYPE_ENROLLMENT,
                'x_axis' => 'period',
                'y_axis' => 'total_enrollments',
                'color' => '#0073aa'
            ],
            'conversion_funnel' => [
                'type' => self::CHART_FUNNEL,
                'title' => __('Conversion Funnel', 'formflow'),
                'data_source' => self::REPORT_TYPE_CONVERSION,
                'value_field' => 'sessions',
                'label_field' => 'step'
            ],
            'source_distribution' => [
                'type' => self::CHART_DONUT,
                'title' => __('Traffic Sources', 'formflow'),
                'data_source' => self::REPORT_TYPE_ATTRIBUTION,
                'value_field' => 'enrollments',
                'label_field' => 'source'
            ],
            'geographic_map' => [
                'type' => self::CHART_MAP,
                'title' => __('Geographic Distribution', 'formflow'),
                'data_source' => self::REPORT_TYPE_GEOGRAPHIC,
                'value_field' => 'enrollments',
                'location_field' => 'location'
            ],
            'hourly_heatmap' => [
                'type' => self::CHART_HEATMAP,
                'title' => __('Activity by Hour', 'formflow'),
                'data_source' => self::REPORT_TYPE_TEMPORAL,
                'value_field' => 'enrollments',
                'x_field' => 'hour'
            ],
            'program_comparison' => [
                'type' => self::CHART_BAR,
                'title' => __('Program Performance', 'formflow'),
                'data_source' => self::REPORT_TYPE_PROGRAM,
                'x_axis' => 'program',
                'y_axis' => 'total_enrollments',
                'stacked' => true
            ]
        ];
    }

    /**
     * Create dashboard
     */
    public function create_dashboard(array $data): int|\WP_Error {
        global $wpdb;

        if (empty($data['name'])) {
            return new \WP_Error('missing_name', __('Dashboard name is required', 'formflow'));
        }

        $table = $wpdb->prefix . 'isf_dashboards';

        $inserted = $wpdb->insert(
            $table,
            [
                'name' => sanitize_text_field($data['name']),
                'description' => sanitize_textarea_field($data['description'] ?? ''),
                'layout' => wp_json_encode($data['layout'] ?? []),
                'widgets' => wp_json_encode($data['widgets'] ?? []),
                'is_default' => !empty($data['is_default']) ? 1 : 0,
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );

        if (!$inserted) {
            return new \WP_Error('db_error', __('Failed to create dashboard', 'formflow'));
        }

        return $wpdb->insert_id;
    }

    /**
     * Get dashboards
     */
    public function get_dashboards(?int $user_id = null): array {
        global $wpdb;

        $table = $wpdb->prefix . 'isf_dashboards';

        $where = ['1=1'];
        if ($user_id) {
            $where[] = $wpdb->prepare('created_by = %d', $user_id);
        }

        return $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE " . implode(' AND ', $where) . "
             ORDER BY is_default DESC, name ASC",
            ARRAY_A
        );
    }
}

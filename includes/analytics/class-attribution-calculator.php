<?php
namespace ISF\Analytics;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Attribution Calculator
 *
 * Calculates marketing attribution using various models.
 * Supports first-touch, last-touch, linear, and time-decay attribution.
 */


use ISF\Database\Database;

class AttributionCalculator {

    /**
     * Attribution models
     */
    public const MODEL_FIRST_TOUCH = 'first_touch';
    public const MODEL_LAST_TOUCH = 'last_touch';
    public const MODEL_LINEAR = 'linear';
    public const MODEL_TIME_DECAY = 'time_decay';
    public const MODEL_POSITION_BASED = 'position_based';

    /**
     * Database instance
     */
    private Database $db;

    /**
     * Touch recorder instance
     */
    private TouchRecorder $touch_recorder;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database();
        $this->touch_recorder = new TouchRecorder();
    }

    /**
     * Calculate attribution for completions in a date range
     *
     * @param int $instance_id Form instance ID
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @param string $model Attribution model to use
     * @return array Attribution results
     */
    public function calculate_attribution(
        int $instance_id,
        string $start_date,
        string $end_date,
        string $model = self::MODEL_FIRST_TOUCH
    ): array {
        // Get all completions (form_complete touches) in the date range
        $completions = $this->get_completions($instance_id, $start_date, $end_date);

        $attribution = [
            'by_source' => [],
            'by_medium' => [],
            'by_campaign' => [],
            'total_conversions' => count($completions),
            'attributed_conversions' => 0,
            'unattributed_conversions' => 0,
        ];

        foreach ($completions as $completion) {
            // Get the visitor's journey (all touches leading to this completion)
            $journey = $this->get_visitor_journey($completion['visitor_id'], $completion['created_at']);

            if (empty($journey)) {
                $attribution['unattributed_conversions']++;
                continue;
            }

            // Calculate attribution based on model
            $credits = $this->calculate_credits($journey, $model);

            // Aggregate attribution
            foreach ($credits as $touch_id => $credit) {
                $touch = $this->find_touch_by_id($journey, $touch_id);
                if (!$touch) continue;

                $source = $touch['utm_source'] ?: $touch['referrer_domain'] ?: 'direct';
                $medium = $touch['utm_medium'] ?: 'none';
                $campaign = $touch['utm_campaign'] ?: 'none';

                // Add to source
                if (!isset($attribution['by_source'][$source])) {
                    $attribution['by_source'][$source] = 0;
                }
                $attribution['by_source'][$source] += $credit;

                // Add to medium
                $source_medium = "{$source} / {$medium}";
                if (!isset($attribution['by_medium'][$source_medium])) {
                    $attribution['by_medium'][$source_medium] = 0;
                }
                $attribution['by_medium'][$source_medium] += $credit;

                // Add to campaign (if present)
                if ($campaign !== 'none') {
                    if (!isset($attribution['by_campaign'][$campaign])) {
                        $attribution['by_campaign'][$campaign] = 0;
                    }
                    $attribution['by_campaign'][$campaign] += $credit;
                }
            }

            $attribution['attributed_conversions']++;
        }

        // Sort results
        arsort($attribution['by_source']);
        arsort($attribution['by_medium']);
        arsort($attribution['by_campaign']);

        return $attribution;
    }

    /**
     * Get completions for an instance
     */
    private function get_completions(int $instance_id, string $start_date, string $end_date): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE instance_id = %d
             AND touch_type = 'form_complete'
             AND created_at BETWEEN %s AND %s
             ORDER BY created_at ASC",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);
    }

    /**
     * Get a visitor's journey leading up to a conversion
     */
    public function get_visitor_journey(string $visitor_id, string $conversion_time): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        // Get all touches before the conversion
        // Only include touches that could be attribution sources
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE visitor_id = %s
             AND created_at <= %s
             AND (utm_source IS NOT NULL OR referrer_domain IS NOT NULL OR touch_type = 'page_view')
             ORDER BY created_at ASC",
            $visitor_id,
            $conversion_time
        ), ARRAY_A);
    }

    /**
     * Calculate credits based on attribution model
     *
     * @param array $journey Array of touches in the journey
     * @param string $model Attribution model
     * @return array Map of touch_id => credit
     */
    private function calculate_credits(array $journey, string $model): array {
        if (empty($journey)) {
            return [];
        }

        // Filter to only include touches with attribution data
        $attributable = array_filter($journey, function($touch) {
            return !empty($touch['utm_source']) || !empty($touch['referrer_domain']);
        });

        if (empty($attributable)) {
            // Fall back to first page view
            $first_touch = reset($journey);
            return [$first_touch['id'] => 1.0];
        }

        $attributable = array_values($attributable);
        $count = count($attributable);

        switch ($model) {
            case self::MODEL_FIRST_TOUCH:
                return [$attributable[0]['id'] => 1.0];

            case self::MODEL_LAST_TOUCH:
                return [$attributable[$count - 1]['id'] => 1.0];

            case self::MODEL_LINEAR:
                $credits = [];
                $credit_per_touch = 1.0 / $count;
                foreach ($attributable as $touch) {
                    $credits[$touch['id']] = $credit_per_touch;
                }
                return $credits;

            case self::MODEL_TIME_DECAY:
                return $this->calculate_time_decay_credits($attributable);

            case self::MODEL_POSITION_BASED:
                return $this->calculate_position_based_credits($attributable);

            default:
                return [$attributable[0]['id'] => 1.0];
        }
    }

    /**
     * Calculate time-decay attribution credits
     * More recent touches get more credit
     */
    private function calculate_time_decay_credits(array $touches): array {
        $credits = [];
        $count = count($touches);

        if ($count === 1) {
            return [$touches[0]['id'] => 1.0];
        }

        // Half-life of 7 days
        $half_life_seconds = 7 * DAY_IN_SECONDS;
        $conversion_time = strtotime($touches[$count - 1]['created_at']);

        $total_weight = 0;
        $weights = [];

        foreach ($touches as $touch) {
            $touch_time = strtotime($touch['created_at']);
            $age = $conversion_time - $touch_time;

            // Exponential decay
            $weight = pow(0.5, $age / $half_life_seconds);
            $weights[$touch['id']] = $weight;
            $total_weight += $weight;
        }

        // Normalize to sum to 1
        foreach ($weights as $touch_id => $weight) {
            $credits[$touch_id] = $weight / $total_weight;
        }

        return $credits;
    }

    /**
     * Calculate position-based attribution credits
     * 40% first, 40% last, 20% split among middle
     */
    private function calculate_position_based_credits(array $touches): array {
        $credits = [];
        $count = count($touches);

        if ($count === 1) {
            return [$touches[0]['id'] => 1.0];
        }

        if ($count === 2) {
            return [
                $touches[0]['id'] => 0.5,
                $touches[1]['id'] => 0.5,
            ];
        }

        // First touch: 40%
        $credits[$touches[0]['id']] = 0.4;

        // Last touch: 40%
        $credits[$touches[$count - 1]['id']] = 0.4;

        // Middle touches: 20% split evenly
        $middle_count = $count - 2;
        $middle_credit = 0.2 / $middle_count;

        for ($i = 1; $i < $count - 1; $i++) {
            $credits[$touches[$i]['id']] = $middle_credit;
        }

        return $credits;
    }

    /**
     * Find touch by ID in journey array
     */
    private function find_touch_by_id(array $journey, int $touch_id): ?array {
        foreach ($journey as $touch) {
            if ((int) $touch['id'] === $touch_id) {
                return $touch;
            }
        }
        return null;
    }

    /**
     * Get attribution comparison across models
     */
    public function compare_models(int $instance_id, string $start_date, string $end_date): array {
        $models = [
            self::MODEL_FIRST_TOUCH,
            self::MODEL_LAST_TOUCH,
            self::MODEL_LINEAR,
            self::MODEL_TIME_DECAY,
            self::MODEL_POSITION_BASED,
        ];

        $comparison = [];

        foreach ($models as $model) {
            $comparison[$model] = $this->calculate_attribution($instance_id, $start_date, $end_date, $model);
        }

        return $comparison;
    }

    /**
     * Get channel performance report
     */
    public function get_channel_performance(
        int $instance_id,
        string $start_date,
        string $end_date,
        string $model = self::MODEL_FIRST_TOUCH
    ): array {
        global $wpdb;
        $touches_table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        // Get all touches (for total reach calculation)
        $all_touches = $wpdb->get_results($wpdb->prepare(
            "SELECT
                COALESCE(utm_source, referrer_domain, 'direct') as channel,
                utm_medium as medium,
                COUNT(*) as touches,
                COUNT(DISTINCT visitor_id) as unique_visitors
             FROM {$touches_table}
             WHERE instance_id = %d
             AND created_at BETWEEN %s AND %s
             GROUP BY channel, medium",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        // Get attribution for conversions
        $attribution = $this->calculate_attribution($instance_id, $start_date, $end_date, $model);

        // Build channel performance report
        $report = [];

        foreach ($all_touches as $row) {
            $channel = $row['channel'];
            $medium = $row['medium'] ?: 'none';
            $key = "{$channel} / {$medium}";

            $conversions = $attribution['by_medium'][$key] ?? 0;
            $touches = (int) $row['touches'];
            $visitors = (int) $row['unique_visitors'];

            $report[] = [
                'channel' => $channel,
                'medium' => $medium,
                'touches' => $touches,
                'unique_visitors' => $visitors,
                'conversions' => round($conversions, 2),
                'conversion_rate' => $visitors > 0 ? round(($conversions / $visitors) * 100, 2) : 0,
            ];
        }

        // Sort by conversions descending
        usort($report, fn($a, $b) => $b['conversions'] <=> $a['conversions']);

        return $report;
    }

    /**
     * Get time to conversion analysis
     */
    public function get_time_to_conversion(int $instance_id, string $start_date, string $end_date): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        // Get conversions with their first touch
        $conversions = $wpdb->get_results($wpdb->prepare(
            "SELECT
                t1.visitor_id,
                t1.created_at as conversion_time,
                MIN(t2.created_at) as first_touch_time
             FROM {$table} t1
             JOIN {$table} t2 ON t1.visitor_id = t2.visitor_id AND t2.created_at <= t1.created_at
             WHERE t1.instance_id = %d
             AND t1.touch_type = 'form_complete'
             AND t1.created_at BETWEEN %s AND %s
             GROUP BY t1.visitor_id, t1.created_at",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        $times = [];
        $buckets = [
            'same_session' => 0,      // < 30 minutes
            'same_day' => 0,          // < 24 hours
            'within_week' => 0,       // < 7 days
            'within_month' => 0,      // < 30 days
            'over_month' => 0,        // > 30 days
        ];

        foreach ($conversions as $row) {
            $first = strtotime($row['first_touch_time']);
            $conversion = strtotime($row['conversion_time']);
            $diff_hours = ($conversion - $first) / 3600;

            $times[] = $diff_hours;

            if ($diff_hours < 0.5) {
                $buckets['same_session']++;
            } elseif ($diff_hours < 24) {
                $buckets['same_day']++;
            } elseif ($diff_hours < 168) {
                $buckets['within_week']++;
            } elseif ($diff_hours < 720) {
                $buckets['within_month']++;
            } else {
                $buckets['over_month']++;
            }
        }

        return [
            'total_conversions' => count($conversions),
            'average_hours' => count($times) > 0 ? round(array_sum($times) / count($times), 1) : 0,
            'median_hours' => $this->calculate_median($times),
            'buckets' => $buckets,
        ];
    }

    /**
     * Get touchpoint analysis
     */
    public function get_touchpoint_analysis(int $instance_id, string $start_date, string $end_date): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        // Get touch counts per converting visitor
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                t1.visitor_id,
                COUNT(t2.id) as touch_count
             FROM {$table} t1
             JOIN {$table} t2 ON t1.visitor_id = t2.visitor_id AND t2.created_at <= t1.created_at
             WHERE t1.instance_id = %d
             AND t1.touch_type = 'form_complete'
             AND t1.created_at BETWEEN %s AND %s
             GROUP BY t1.visitor_id",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        $counts = array_column($results, 'touch_count');
        $counts = array_map('intval', $counts);

        $buckets = [
            '1' => 0,
            '2-3' => 0,
            '4-5' => 0,
            '6-10' => 0,
            '11+' => 0,
        ];

        foreach ($counts as $count) {
            if ($count === 1) {
                $buckets['1']++;
            } elseif ($count <= 3) {
                $buckets['2-3']++;
            } elseif ($count <= 5) {
                $buckets['4-5']++;
            } elseif ($count <= 10) {
                $buckets['6-10']++;
            } else {
                $buckets['11+']++;
            }
        }

        return [
            'total_conversions' => count($counts),
            'average_touches' => count($counts) > 0 ? round(array_sum($counts) / count($counts), 1) : 0,
            'median_touches' => $this->calculate_median($counts),
            'max_touches' => count($counts) > 0 ? max($counts) : 0,
            'buckets' => $buckets,
        ];
    }

    /**
     * Calculate median of an array
     */
    private function calculate_median(array $values): float {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }
}

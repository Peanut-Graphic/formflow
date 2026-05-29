<?php
/**
 * Cross-Sell Recommendation Engine
 *
 * Analyzes customer data and enrollment patterns to provide
 * intelligent program recommendations and bundle suggestions.
 *
 * @package FormFlow
 * @since 2.6.0
 */

namespace ISF\Programs;

defined('ABSPATH') || exit;

class CrossSellEngine {

    /**
     * Singleton instance
     */
    private static ?CrossSellEngine $instance = null;

    /**
     * Program manager instance
     */
    private ?ProgramManager $program_manager = null;

    /**
     * Recommendation strategies
     */
    private array $strategies = [];

    /**
     * Get singleton instance
     */
    public static function instance(): CrossSellEngine {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->program_manager = ProgramManager::instance();
        $this->register_default_strategies();
    }

    /**
     * Initialize hooks
     */
    public function init(): void {
        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        register_rest_route('isf/v1', '/cross-sell/analyze', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_analyze'],
            'permission_callback' => ['\ISF\Security', 'rate_limit_public'],
        ]);

        register_rest_route('isf/v1', '/cross-sell/bundles', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_get_bundles'],
            'permission_callback' => ['\ISF\Security', 'rate_limit_public'],
        ]);

        register_rest_route('isf/v1', '/cross-sell/personalized', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_personalized_recommendations'],
            'permission_callback' => ['\ISF\Security', 'rate_limit_public'],
        ]);
    }

    /**
     * Register default recommendation strategies
     */
    private function register_default_strategies(): void {
        // Strategy: Equipment-based recommendations
        $this->strategies['equipment'] = function($customer_data, $available_programs) {
            $recommendations = [];
            $equipment = $customer_data['equipment'] ?? [];

            if (!is_array($equipment)) {
                $equipment = [$equipment];
            }

            // Smart thermostat if has HVAC
            if (array_intersect(['central_ac', 'heat_pump', 'gas_furnace'], $equipment)) {
                foreach ($available_programs as $program) {
                    if (stripos($program['name'], 'thermostat') !== false ||
                        stripos($program['name'], 'smart home') !== false) {
                        $recommendations[] = [
                            'program_id' => $program['id'],
                            'score' => 0.9,
                            'reason' => 'Perfect for your HVAC system',
                            'strategy' => 'equipment',
                        ];
                    }
                }
            }

            // EV program if has EV
            if (!empty($customer_data['has_ev'])) {
                foreach ($available_programs as $program) {
                    if ($program['category'] === 'ev') {
                        $recommendations[] = [
                            'program_id' => $program['id'],
                            'score' => 0.95,
                            'reason' => 'Save on EV charging costs',
                            'strategy' => 'equipment',
                        ];
                    }
                }
            }

            // Solar if has high usage
            if (($customer_data['monthly_usage_kwh'] ?? 0) > 1000) {
                foreach ($available_programs as $program) {
                    if ($program['category'] === 'solar') {
                        $recommendations[] = [
                            'program_id' => $program['id'],
                            'score' => 0.8,
                            'reason' => 'Reduce your high energy usage',
                            'strategy' => 'equipment',
                        ];
                    }
                }
            }

            return $recommendations;
        };

        // Strategy: Bundle complementary programs
        $this->strategies['bundle'] = function($customer_data, $available_programs, $selected_programs = []) {
            $recommendations = [];

            // Find programs that pair well
            $bundles = [
                ['demand_response', 'smart_home'],
                ['demand_response', 'ev'],
                ['energy_efficiency', 'rebate'],
                ['smart_home', 'energy_efficiency'],
            ];

            $selected_categories = [];
            foreach ($selected_programs as $program_id) {
                $program = $this->program_manager->get_program($program_id);
                if ($program) {
                    $selected_categories[] = $program['category'];
                }
            }

            foreach ($bundles as $bundle) {
                $has_one = array_intersect($bundle, $selected_categories);
                if (count($has_one) === 1) {
                    $needed = array_diff($bundle, $selected_categories)[0] ?? null;
                    if ($needed) {
                        foreach ($available_programs as $program) {
                            if ($program['category'] === $needed && !in_array($program['id'], $selected_programs)) {
                                $recommendations[] = [
                                    'program_id' => $program['id'],
                                    'score' => 0.85,
                                    'reason' => 'Pairs well with your selected programs',
                                    'strategy' => 'bundle',
                                    'bundle_discount' => 10, // 10% bundle discount
                                ];
                            }
                        }
                    }
                }
            }

            return $recommendations;
        };

        // Strategy: Incentive maximization
        $this->strategies['incentive'] = function($customer_data, $available_programs, $selected_programs = []) {
            $recommendations = [];

            // Sort by incentive amount descending
            $programs_by_incentive = $available_programs;
            usort($programs_by_incentive, function($a, $b) {
                return ($b['incentive_amount'] ?? 0) - ($a['incentive_amount'] ?? 0);
            });

            // Recommend top 3 highest incentive programs not yet selected
            $count = 0;
            foreach ($programs_by_incentive as $program) {
                if (in_array($program['id'], $selected_programs)) {
                    continue;
                }

                if (($program['incentive_amount'] ?? 0) > 0) {
                    $recommendations[] = [
                        'program_id' => $program['id'],
                        'score' => 0.7 + (0.1 * (3 - $count)),
                        'reason' => sprintf('Earn $%s in incentives', number_format($program['incentive_amount'], 2)),
                        'strategy' => 'incentive',
                    ];

                    $count++;
                    if ($count >= 3) break;
                }
            }

            return $recommendations;
        };

        // Strategy: Popularity-based (based on enrollment data)
        $this->strategies['popular'] = function($customer_data, $available_programs, $selected_programs = []) {
            $recommendations = [];

            // This would query actual enrollment data in production
            // For now, use priority as a proxy for popularity
            $programs_by_popularity = $available_programs;
            usort($programs_by_popularity, function($a, $b) {
                return ($b['priority'] ?? 0) - ($a['priority'] ?? 0);
            });

            $count = 0;
            foreach ($programs_by_popularity as $program) {
                if (in_array($program['id'], $selected_programs)) {
                    continue;
                }

                $recommendations[] = [
                    'program_id' => $program['id'],
                    'score' => 0.6,
                    'reason' => 'Popular among customers like you',
                    'strategy' => 'popular',
                ];

                $count++;
                if ($count >= 2) break;
            }

            return $recommendations;
        };

        // Strategy: Seasonal recommendations
        $this->strategies['seasonal'] = function($customer_data, $available_programs) {
            $recommendations = [];
            $month = (int)date('n');

            // Summer months (May-September) - recommend AC/demand response programs
            if ($month >= 5 && $month <= 9) {
                foreach ($available_programs as $program) {
                    if ($program['category'] === 'demand_response') {
                        $recommendations[] = [
                            'program_id' => $program['id'],
                            'score' => 0.75,
                            'reason' => 'Perfect timing - earn rewards this summer',
                            'strategy' => 'seasonal',
                        ];
                    }
                }
            }

            // Winter months (November-February) - recommend heating efficiency
            if ($month >= 11 || $month <= 2) {
                foreach ($available_programs as $program) {
                    if ($program['category'] === 'energy_efficiency' ||
                        stripos($program['name'], 'heat') !== false) {
                        $recommendations[] = [
                            'program_id' => $program['id'],
                            'score' => 0.75,
                            'reason' => 'Stay warm while saving this winter',
                            'strategy' => 'seasonal',
                        ];
                    }
                }
            }

            return $recommendations;
        };
    }

    /**
     * Register a custom recommendation strategy
     *
     * Custom strategies are wrapped in error handling to prevent
     * a faulty strategy from crashing the entire system.
     *
     * @param string   $name     Unique strategy identifier
     * @param callable $callback Strategy function(array $customer_data, array $available_programs, array $selected_programs): array
     */
    public function register_strategy(string $name, callable $callback): void {
        // Validate strategy name
        if (empty($name) || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $this->log_error('Invalid strategy name: ' . $name);
            return;
        }

        $this->strategies[$name] = $callback;

        /**
         * Fires when a custom cross-sell strategy is registered
         *
         * @param string   $name     Strategy name
         * @param callable $callback Strategy callback
         */
        do_action('isf_cross_sell_strategy_registered', $name, $callback);
    }

    /**
     * Log an error message
     */
    private function log_error(string $message, array $context = []): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[FormFlow CrossSellEngine] ' . $message . ' Context: ' . wp_json_encode($context));
        }

        /**
         * Fires when a cross-sell engine error occurs
         *
         * @param string $message Error message
         * @param array  $context Additional context
         */
        do_action('isf_cross_sell_error', $message, $context);
    }

    /**
     * REST callback: Analyze and recommend
     */
    public function rest_analyze(\WP_REST_Request $request): \WP_REST_Response {
        $customer_data = $request->get_param('customer_data') ?: [];
        $utility = $request->get_param('utility');
        $selected_programs = $request->get_param('selected_programs') ?: [];
        $instance_id = $request->get_param('instance_id');

        $recommendations = $this->analyze($customer_data, $utility, $selected_programs, $instance_id);

        return new \WP_REST_Response([
            'success' => true,
            'recommendations' => $recommendations,
        ], 200);
    }

    /**
     * REST callback: Get bundle suggestions
     */
    public function rest_get_bundles(\WP_REST_Request $request): \WP_REST_Response {
        $customer_data = $request->get_param('customer_data') ?: [];
        $utility = $request->get_param('utility');
        $instance_id = $request->get_param('instance_id');

        $bundles = $this->get_suggested_bundles($customer_data, $utility, $instance_id);

        return new \WP_REST_Response([
            'success' => true,
            'bundles' => $bundles,
        ], 200);
    }

    /**
     * REST callback: Personalized recommendations
     */
    public function rest_personalized_recommendations(\WP_REST_Request $request): \WP_REST_Response {
        $account_number = $request->get_param('account_number');
        $utility = $request->get_param('utility');

        $recommendations = $this->get_personalized_recommendations($account_number, $utility);

        return new \WP_REST_Response([
            'success' => true,
            'recommendations' => $recommendations,
        ], 200);
    }

    /**
     * Analyze customer data and generate recommendations
     */
    public function analyze(array $customer_data, string $utility, array $selected_programs = [], ?int $instance_id = null): array {
        // Get available programs
        $available_programs = $this->program_manager->get_programs($utility, $instance_id);

        // Filter to only eligible programs
        $eligible_programs = [];
        foreach ($available_programs as $program) {
            if (in_array($program['id'], $selected_programs)) {
                continue; // Skip already selected
            }

            $eligibility = $this->program_manager->check_eligibility($program['id'], $customer_data);
            if ($eligibility['eligible']) {
                $eligible_programs[] = $program;
            }
        }

        // Run all strategies with error isolation
        $all_recommendations = [];

        /**
         * Filter which strategies to run for this analysis
         *
         * @param array  $strategy_names List of strategy names to execute
         * @param array  $customer_data  Customer data being analyzed
         * @param string $utility        Utility identifier
         */
        $strategy_names = apply_filters('isf_cross_sell_active_strategies', array_keys($this->strategies), $customer_data, $utility);

        foreach ($strategy_names as $strategy_name) {
            if (!isset($this->strategies[$strategy_name])) {
                $this->log_error("Strategy not found: {$strategy_name}");
                continue;
            }

            $strategy = $this->strategies[$strategy_name];

            try {
                $recs = $strategy($customer_data, $eligible_programs, $selected_programs);

                // Validate strategy output
                if (!is_array($recs)) {
                    $this->log_error("Strategy '{$strategy_name}' returned non-array", [
                        'type' => gettype($recs),
                    ]);
                    continue;
                }

                foreach ($recs as $rec) {
                    // Validate each recommendation has required fields
                    if (!isset($rec['program_id']) || !isset($rec['score'])) {
                        $this->log_error("Strategy '{$strategy_name}' returned invalid recommendation", [
                            'recommendation' => $rec,
                        ]);
                        continue;
                    }
                    $all_recommendations[] = $rec;
                }
            } catch (\Throwable $e) {
                // Log the error but continue with other strategies
                $this->log_error("Strategy '{$strategy_name}' threw exception: " . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);

                /**
                 * Fires when a cross-sell strategy fails
                 *
                 * @param string     $strategy_name The failing strategy name
                 * @param \Throwable $exception     The exception that was thrown
                 * @param array      $customer_data Customer data being analyzed
                 */
                do_action('isf_cross_sell_strategy_failed', $strategy_name, $e, $customer_data);
            }
        }

        // Deduplicate and merge scores
        $merged = [];
        foreach ($all_recommendations as $rec) {
            $id = $rec['program_id'];
            if (!isset($merged[$id])) {
                $merged[$id] = $rec;
                $merged[$id]['strategies'] = [$rec['strategy']];
                $merged[$id]['reasons'] = [$rec['reason']];
            } else {
                // Combine scores (take max)
                $merged[$id]['score'] = max($merged[$id]['score'], $rec['score']);
                $merged[$id]['strategies'][] = $rec['strategy'];
                if (!in_array($rec['reason'], $merged[$id]['reasons'])) {
                    $merged[$id]['reasons'][] = $rec['reason'];
                }
                // Combine bundle discounts
                if (!empty($rec['bundle_discount'])) {
                    $merged[$id]['bundle_discount'] = max(
                        $merged[$id]['bundle_discount'] ?? 0,
                        $rec['bundle_discount']
                    );
                }
            }
        }

        // Sort by score descending
        usort($merged, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Enrich with program details
        $recommendations = [];
        foreach ($merged as $rec) {
            $program = $this->program_manager->get_program($rec['program_id']);
            if ($program) {
                $recommendations[] = [
                    'program' => $program,
                    'score' => round($rec['score'] * 100),
                    'reasons' => $rec['reasons'],
                    'strategies' => $rec['strategies'],
                    'bundle_discount' => $rec['bundle_discount'] ?? 0,
                    'primary_reason' => $rec['reasons'][0] ?? 'Recommended for you',
                ];
            }
        }

        return array_slice($recommendations, 0, 5); // Top 5 recommendations
    }

    /**
     * Get suggested program bundles
     */
    public function get_suggested_bundles(array $customer_data, string $utility, ?int $instance_id = null): array {
        $programs = $this->program_manager->get_programs($utility, $instance_id);

        // Define bundle templates
        $bundle_templates = [
            [
                'name' => 'Maximum Savings Bundle',
                'description' => 'Get the most value with our top savings programs',
                'categories' => ['demand_response', 'smart_home'],
                'min_programs' => 2,
                'discount' => 15,
            ],
            [
                'name' => 'Smart Home Starter',
                'description' => 'Modernize your home with smart energy management',
                'categories' => ['smart_home', 'energy_efficiency'],
                'min_programs' => 2,
                'discount' => 10,
            ],
            [
                'name' => 'EV Owner\'s Package',
                'description' => 'Maximize savings for electric vehicle owners',
                'categories' => ['ev', 'demand_response'],
                'min_programs' => 2,
                'discount' => 12,
            ],
            [
                'name' => 'Complete Energy Bundle',
                'description' => 'Full coverage for maximum rewards',
                'categories' => ['demand_response', 'smart_home', 'energy_efficiency'],
                'min_programs' => 3,
                'discount' => 20,
            ],
        ];

        $bundles = [];

        foreach ($bundle_templates as $template) {
            $bundle_programs = [];
            $total_incentive = 0;

            foreach ($programs as $program) {
                if (in_array($program['category'], $template['categories'])) {
                    // Check eligibility
                    $eligibility = $this->program_manager->check_eligibility($program['id'], $customer_data);
                    if ($eligibility['eligible']) {
                        $bundle_programs[] = $program;
                        $total_incentive += floatval($program['incentive_amount'] ?? 0);
                    }
                }
            }

            // Only include bundle if enough eligible programs
            if (count($bundle_programs) >= $template['min_programs']) {
                $bundles[] = [
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'programs' => $bundle_programs,
                    'program_count' => count($bundle_programs),
                    'total_incentive' => $total_incentive,
                    'bundle_discount' => $template['discount'],
                    'categories' => $template['categories'],
                ];
            }
        }

        // Sort by total incentive descending
        usort($bundles, function($a, $b) {
            return $b['total_incentive'] <=> $a['total_incentive'];
        });

        return $bundles;
    }

    /**
     * Get personalized recommendations based on account history
     */
    public function get_personalized_recommendations(string $account_number, string $utility): array {
        global $wpdb;

        // Get customer's existing enrollments
        $table_enrollments = $wpdb->prefix . 'isf_program_enrollments';
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT program_id FROM {$table_enrollments} WHERE account_number = %s AND status IN ('enrolled', 'pending')",
            $account_number
        ));

        // Get available programs not yet enrolled
        $programs = $this->program_manager->get_programs($utility);
        $available = array_filter($programs, function($p) use ($existing) {
            return !in_array($p['id'], $existing);
        });

        // Build customer profile from existing enrollments
        $customer_profile = [
            'enrolled_categories' => [],
        ];

        foreach ($existing as $program_id) {
            $program = $this->program_manager->get_program($program_id);
            if ($program) {
                $customer_profile['enrolled_categories'][] = $program['category'];
            }
        }

        // Score remaining programs
        $recommendations = [];
        foreach ($available as $program) {
            $score = 0.5; // Base score

            // Boost if similar to enrolled programs
            if (in_array($program['category'], $customer_profile['enrolled_categories'])) {
                $score += 0.2;
            }

            // Boost cross-sell opportunities
            $cross_sell = $program['cross_sell_rules']['recommend_with'] ?? [];
            foreach ($existing as $enrolled_id) {
                $enrolled = $this->program_manager->get_program($enrolled_id);
                if ($enrolled && in_array($enrolled['program_code'], $cross_sell)) {
                    $score += 0.3;
                    break;
                }
            }

            // Boost high-incentive programs
            if (($program['incentive_amount'] ?? 0) > 50) {
                $score += 0.15;
            }

            $recommendations[] = [
                'program' => $program,
                'score' => min($score, 1.0) * 100,
                'reason' => $this->generate_personalized_reason($program, $customer_profile),
            ];
        }

        // Sort by score
        usort($recommendations, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($recommendations, 0, 3);
    }

    /**
     * Generate personalized recommendation reason
     */
    private function generate_personalized_reason(array $program, array $profile): string {
        $reasons = [
            'demand_response' => [
                'Earn rewards during peak energy events',
                'Turn your AC into a money-saving tool',
            ],
            'smart_home' => [
                'Upgrade to smart home energy management',
                'Control your energy use from anywhere',
            ],
            'energy_efficiency' => [
                'Lower your monthly energy bills',
                'Make your home more efficient',
            ],
            'ev' => [
                'Save on your EV charging costs',
                'Optimize when you charge your vehicle',
            ],
            'rebate' => [
                'Get money back on energy upgrades',
                'Save with instant rebates',
            ],
            'solar' => [
                'Generate your own clean energy',
                'Reduce your grid dependence',
            ],
        ];

        $category = $program['category'] ?? 'other';
        $category_reasons = $reasons[$category] ?? ['A great program for your home'];

        // Select based on profile
        if (in_array($category, $profile['enrolled_categories'] ?? [])) {
            return 'Since you love ' . $category . ' programs, try this one!';
        }

        return $category_reasons[array_rand($category_reasons)];
    }

    /**
     * Calculate bundle value
     */
    public function calculate_bundle_value(array $program_ids, int $discount_percent = 0): array {
        $programs = [];
        $total_one_time = 0;
        $total_recurring = 0;

        foreach ($program_ids as $program_id) {
            $program = $this->program_manager->get_program($program_id);
            if (!$program) continue;

            $programs[] = [
                'id' => $program['id'],
                'name' => $program['name'],
                'incentive' => $program['incentive_amount'],
                'type' => $program['incentive_type'],
            ];

            switch ($program['incentive_type']) {
                case 'one_time':
                    $total_one_time += floatval($program['incentive_amount']);
                    break;
                case 'recurring':
                    $total_recurring += floatval($program['incentive_amount']);
                    break;
            }
        }

        $annual_value = $total_one_time + ($total_recurring * 12);

        return [
            'programs' => $programs,
            'program_count' => count($programs),
            'one_time_incentives' => $total_one_time,
            'recurring_incentives' => $total_recurring,
            'annual_value' => $annual_value,
            'bundle_discount' => $discount_percent,
            'savings_message' => $discount_percent > 0
                ? sprintf('Save an extra %d%% when you bundle!', $discount_percent)
                : null,
        ];
    }

    /**
     * Track recommendation interaction for ML/analytics
     */
    public function track_interaction(string $interaction_type, int $program_id, array $context = []): void {
        global $wpdb;

        // This could be expanded to feed into a recommendation ML model
        $table = $wpdb->prefix . 'isf_recommendation_interactions';

        // Only insert if table exists (table creation would be in activator)
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $wpdb->insert($table, [
                'interaction_type' => $interaction_type, // view, click, enroll, dismiss
                'program_id' => $program_id,
                'context' => wp_json_encode($context),
                'created_at' => current_time('mysql'),
            ]);
        }
    }
}

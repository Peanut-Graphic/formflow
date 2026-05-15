<?php
namespace ISF\Analytics;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Completion Receiver
 *
 * Receives completion notifications from external enrollment systems.
 * Supports webhooks, redirect callbacks, and manual imports.
 */


use ISF\Database\Database;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class CompletionReceiver {

    /**
     * API namespace
     */
    private const NAMESPACE = 'isf/v1';

    /**
     * Database instance
     */
    private Database $db;

    /**
     * Handoff tracker instance
     */
    private HandoffTracker $handoff_tracker;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database();
        $this->handoff_tracker = new HandoffTracker();
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        // Webhook endpoint for external system notifications
        register_rest_route(self::NAMESPACE, '/completions/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_webhook'],
            'permission_callback' => [$this, 'verify_webhook_signature'],
        ]);

        // Redirect callback endpoint (for redirect-back completion flow)
        register_rest_route(self::NAMESPACE, '/completions/redirect', [
            'methods' => 'GET',
            'callback' => [$this, 'receive_redirect'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'isf_ref' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'status' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ]);

        // Manual completion recording (admin only)
        register_rest_route(self::NAMESPACE, '/completions', [
            'methods' => 'POST',
            'callback' => [$this, 'record_completion'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'instance_id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
                'account_number' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'email' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'external_id' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'completion_type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'enrollment',
                ],
            ],
        ]);
    }

    /**
     * Receive webhook notification from external system
     */
    public function receive_webhook(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $body = $request->get_json_params();

        if (empty($body)) {
            return new WP_Error(
                'invalid_payload',
                'Request body must be valid JSON',
                ['status' => 400]
            );
        }

        // Extract common fields
        $handoff_token = $body['isf_ref'] ?? $body['handoff_token'] ?? $body['token'] ?? null;
        $account_number = $body['account_number'] ?? $body['accountNumber'] ?? $body['utility_no'] ?? null;
        $email = $body['email'] ?? $body['customer_email'] ?? null;
        $external_id = $body['external_id'] ?? $body['confirmation_number'] ?? $body['id'] ?? null;
        $completion_type = $body['type'] ?? $body['completion_type'] ?? 'enrollment';
        $status = $body['status'] ?? 'completed';

        // Determine instance ID
        $instance_id = $this->determine_instance_id($body, $handoff_token);

        if (!$instance_id) {
            return new WP_Error(
                'no_instance',
                'Could not determine form instance',
                ['status' => 400]
            );
        }

        // Only process successful completions
        if (!in_array($status, ['completed', 'success', 'enrolled'], true)) {
            // Log but don't create completion record
            $this->db->log('info', 'Non-completion webhook received', [
                'status' => $status,
                'external_id' => $external_id,
            ], $instance_id);

            return new WP_REST_Response(['received' => true, 'processed' => false], 200);
        }

        // Record the completion
        $completion_id = $this->store_completion(
            $instance_id,
            'webhook',
            $handoff_token,
            $account_number,
            $email,
            $external_id,
            $completion_type,
            $body
        );

        if (!$completion_id) {
            return new WP_Error(
                'storage_failed',
                'Failed to store completion',
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'completion_id' => $completion_id,
        ], 201);
    }

    /**
     * Receive redirect callback from external system
     */
    public function receive_redirect(WP_REST_Request $request): void {
        $token = $request->get_param('token') ?? $request->get_param('isf_ref');
        $status = $request->get_param('status') ?? 'completed';
        $external_id = $request->get_param('confirmation') ?? $request->get_param('id');

        // Default redirect URL
        $redirect_url = home_url('/');

        if ($token && $status === 'completed') {
            // Get handoff details
            $handoff = $this->handoff_tracker->get_handoff($token);

            if ($handoff) {
                // Record completion
                $this->store_completion(
                    $handoff['instance_id'],
                    'redirect',
                    $token,
                    $request->get_param('account_number'),
                    $request->get_param('email'),
                    $external_id,
                    'enrollment',
                    $request->get_params()
                );

                // Get instance for thank you page
                $instance = $this->db->get_instance($handoff['instance_id']);
                if ($instance && isset($instance['settings'])) {
                    $settings = is_array($instance['settings'])
                        ? $instance['settings']
                        : (json_decode($instance['settings'], true) ?? []);
                    if (!empty($settings['thank_you_url'])) {
                        $redirect_url = $settings['thank_you_url'];
                    }
                }
            }
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Record a manual completion (admin)
     */
    public function record_completion(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $instance_id = (int) $request->get_param('instance_id');
        $account_number = sanitize_text_field($request->get_param('account_number') ?? '');
        $email = sanitize_email($request->get_param('email') ?? '');
        $external_id = sanitize_text_field($request->get_param('external_id') ?? '');
        $completion_type = sanitize_text_field($request->get_param('completion_type'));

        // Validate instance
        $instance = $this->db->get_instance($instance_id);
        if (!$instance) {
            return new WP_Error('invalid_instance', 'Instance not found', ['status' => 404]);
        }

        // Try to find matching handoff
        $handoff_token = null;
        if ($account_number) {
            $handoff = $this->handoff_tracker->find_handoff_by_account($account_number, $instance_id);
            if ($handoff) {
                $handoff_token = $handoff['handoff_token'];
            }
        }

        $completion_id = $this->store_completion(
            $instance_id,
            'import',
            $handoff_token,
            $account_number,
            $email,
            $external_id,
            $completion_type,
            ['manual_entry' => true, 'entered_by' => get_current_user_id()]
        );

        if (!$completion_id) {
            return new WP_Error('storage_failed', 'Failed to store completion', ['status' => 500]);
        }

        return new WP_REST_Response([
            'success' => true,
            'completion_id' => $completion_id,
            'matched_handoff' => $handoff_token !== null,
        ], 201);
    }

    /**
     * Store a completion record
     */
    private function store_completion(
        int $instance_id,
        string $source,
        ?string $handoff_token,
        ?string $account_number,
        ?string $email,
        ?string $external_id,
        string $completion_type,
        array $raw_data
    ): int|false {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_EXTERNAL_COMPLETIONS;

        // Find handoff ID if we have a token
        $handoff_id = null;
        if ($handoff_token) {
            $handoff = $this->handoff_tracker->get_handoff($handoff_token);
            if ($handoff) {
                $handoff_id = $handoff['id'];

                // Mark handoff as completed
                $this->handoff_tracker->mark_completed($handoff_token, [
                    'account_number' => $account_number,
                    'external_id' => $external_id,
                    'source' => $source,
                ]);
            }
        }

        // Insert completion record
        $result = $wpdb->insert(
            $table,
            [
                'instance_id' => $instance_id,
                'source' => $source,
                'handoff_id' => $handoff_id,
                'account_number' => $account_number,
                'customer_email' => $email,
                'external_id' => $external_id,
                'completion_type' => $completion_type,
                'raw_data' => wp_json_encode($raw_data),
                'processed' => 1,
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d']
        );

        if ($result) {
            $completion_id = $wpdb->insert_id;

            // Log the completion
            $this->db->log('info', 'External completion received', [
                'completion_id' => $completion_id,
                'source' => $source,
                'handoff_token' => $handoff_token,
                'matched_handoff' => $handoff_id !== null,
            ], $instance_id);

            // Build completion data for hook
            $completion_data = [
                'completion_id' => $completion_id,
                'instance_id' => $instance_id,
                'source' => $source,
                'handoff_token' => $handoff_token,
                'handoff_id' => $handoff_id,
                'account_number' => $account_number,
                'email' => $email,
                'external_id' => $external_id,
                'completion_type' => $completion_type,
                'raw_data' => $raw_data,
            ];

            // Fire external completion hook for Peanut Suite integration
            do_action(\ISF\Hooks::EXTERNAL_COMPLETION, $completion_data, $source);

            return $completion_id;
        }

        return false;
    }

    /**
     * Determine instance ID from webhook data
     */
    private function determine_instance_id(array $data, ?string $handoff_token): ?int {
        // Check explicit instance_id in payload
        if (!empty($data['instance_id'])) {
            return (int) $data['instance_id'];
        }

        // Try to get from handoff token
        if ($handoff_token) {
            $handoff = $this->handoff_tracker->get_handoff($handoff_token);
            if ($handoff) {
                return (int) $handoff['instance_id'];
            }
        }

        // Try to match by utility code
        if (!empty($data['utility'])) {
            $instance = $this->db->get_instance_by_utility($data['utility']);
            if ($instance) {
                return (int) $instance['id'];
            }
        }

        return null;
    }

    /**
     * Verify webhook signature
     */
    public function verify_webhook_signature(WP_REST_Request $request): bool {
        // Get signature from header
        $signature = $request->get_header('X-ISF-Signature');

        if (!$signature) {
            // Allow if no signature required (development mode)
            $settings = get_option('isf_settings', []);
            return !empty($settings['webhook_signature_optional']);
        }

        // Get webhook secret
        $secret = $this->get_webhook_secret();
        if (!$secret) {
            return false;
        }

        // Get raw body
        $body = $request->get_body();

        // Calculate expected signature
        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Check admin permission
     */
    public function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get webhook secret from settings
     */
    private function get_webhook_secret(): ?string {
        $settings = get_option('isf_settings', []);
        return $settings['completion_webhook_secret'] ?? null;
    }

    /**
     * Get completions for an instance
     */
    public function get_completions(
        int $instance_id,
        string $start_date,
        string $end_date,
        ?string $source = null
    ): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_EXTERNAL_COMPLETIONS;

        $sql = "SELECT * FROM {$table}
                WHERE instance_id = %d
                AND created_at BETWEEN %s AND %s";

        $params = [
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
        ];

        if ($source) {
            $sql .= " AND source = %s";
            $params[] = $source;
        }

        $sql .= " ORDER BY created_at DESC";

        $completions = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        foreach ($completions as &$completion) {
            $completion['raw_data'] = json_decode($completion['raw_data'] ?? '{}', true) ?: [];
        }

        return $completions;
    }

    /**
     * Get completion statistics
     */
    public function get_completion_stats(int $instance_id, string $start_date, string $end_date): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_EXTERNAL_COMPLETIONS;

        $stats = [];

        // Total completions
        $stats['total'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE instance_id = %d
             AND created_at BETWEEN %s AND %s",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // By source
        $source_results = $wpdb->get_results($wpdb->prepare(
            "SELECT source, COUNT(*) as count
             FROM {$table}
             WHERE instance_id = %d
             AND created_at BETWEEN %s AND %s
             GROUP BY source",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        $stats['by_source'] = [];
        foreach ($source_results as $row) {
            $stats['by_source'][$row['source']] = (int) $row['count'];
        }

        // Matched to handoffs
        $stats['matched_to_handoff'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE instance_id = %d
             AND handoff_id IS NOT NULL
             AND created_at BETWEEN %s AND %s",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        $stats['match_rate'] = $stats['total'] > 0
            ? round(($stats['matched_to_handoff'] / $stats['total']) * 100, 2)
            : 0;

        return $stats;
    }

    /**
     * Import completions from CSV data
     *
     * @param int $instance_id Form instance ID
     * @param array $rows CSV rows (each row is associative array)
     * @param array $field_mapping Map of CSV columns to completion fields
     * @return array Import results
     */
    public function import_from_csv(int $instance_id, array $rows, array $field_mapping): array {
        $results = [
            'total' => count($rows),
            'imported' => 0,
            'matched' => 0,
            'errors' => [],
        ];

        foreach ($rows as $index => $row) {
            try {
                // Map fields
                $account_number = $row[$field_mapping['account_number'] ?? ''] ?? null;
                $email = $row[$field_mapping['email'] ?? ''] ?? null;
                $external_id = $row[$field_mapping['external_id'] ?? ''] ?? null;
                $completion_type = $row[$field_mapping['completion_type'] ?? ''] ?? 'enrollment';

                // Try to find matching handoff
                $handoff_token = null;
                if ($account_number) {
                    $handoff = $this->handoff_tracker->find_handoff_by_account($account_number, $instance_id);
                    if ($handoff) {
                        $handoff_token = $handoff['handoff_token'];
                        $results['matched']++;
                    }
                }

                $completion_id = $this->store_completion(
                    $instance_id,
                    'import',
                    $handoff_token,
                    $account_number,
                    $email,
                    $external_id,
                    $completion_type,
                    ['csv_row' => $index + 1, 'original_data' => $row]
                );

                if ($completion_id) {
                    $results['imported']++;
                }
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'row' => $index + 1,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}

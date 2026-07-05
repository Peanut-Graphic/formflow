<?php
namespace ISF\Api;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Handoff Endpoint
 *
 * REST API endpoint for creating and processing handoff redirects.
 * Handles the redirect flow for external enrollment tracking.
 */


use ISF\Analytics\HandoffTracker;
use ISF\Analytics\VisitorTracker;
use ISF\Database\Database;
use ISF\Security;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class HandoffEndpoint {

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
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        // Public: handoff-creation endpoint. The server response is a
        // cryptographic token that gates all downstream operations.
        // The endpoint itself is intentionally public so external
        // referrers can initiate a tracked handoff without a WP session.
        register_rest_route(self::NAMESPACE, '/handoff', [
            'methods' => 'POST',
            'callback' => [$this, 'create_handoff'],
            'permission_callback' => '__return_true',
            'args' => [
                'instance_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => fn($v) => is_numeric($v) && $v > 0,
                ],
                'destination_url' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => fn($v) => filter_var($v, FILTER_VALIDATE_URL),
                ],
                'params' => [
                    'required' => false,
                    'type' => 'object',
                    'default' => [],
                ],
            ],
        ]);

        // Public: handoff-redirect endpoint. The 32-hex token in the
        // URL pattern IS the auth. validate_callback enforces the exact
        // shape so non-token URLs 404 before reaching the callback.
        register_rest_route(self::NAMESPACE, '/handoff/(?P<token>[a-f0-9]{32})', [
            'methods' => 'GET',
            'callback' => [$this, 'process_redirect'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => fn($v) => preg_match('/^[a-f0-9]{32}$/', $v),
                ],
            ],
        ]);

        // Public: handoff-status endpoint. Same token auth as the
        // redirect endpoint above; read-only.
        register_rest_route(self::NAMESPACE, '/handoff/(?P<token>[a-f0-9]{32})/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_status'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);
    }

    /**
     * Create a new tracked handoff
     */
    public function create_handoff(WP_REST_Request $request): WP_REST_Response|WP_Error {
        // Abuse guard: this route is intentionally public (see register_routes),
        // so throttle anonymous writes to isf_handoffs / isf_visitors / isf_touches
        // exactly the way the enrollment handlers do. After the cluster-2 IP fix,
        // check_rate_limit() keys on the trusted client IP.
        if (!Security::check_rate_limit()) {
            return new WP_Error(
                'rate_limited',
                'Too many requests. Please try again in a moment.',
                ['status' => 429]
            );
        }

        $instance_id = (int) $request->get_param('instance_id');

        // Open-redirect guard: only accept a well-formed absolute http(s) URL
        // with a host. esc_url_raw() with an explicit protocol allowlist strips
        // javascript:/data:/vbscript:/etc; is_safe_destination_url() then rejects
        // anything that isn't http/https-with-a-host (protocol-relative,
        // malformed, control-char smuggling, ...).
        $destination_url = esc_url_raw($request->get_param('destination_url'), ['http', 'https']);
        if (!self::is_safe_destination_url($destination_url)) {
            return new WP_Error(
                'invalid_destination',
                'Destination URL must be a well-formed absolute http(s) URL.',
                ['status' => 400]
            );
        }

        $params = $request->get_param('params') ?? [];

        // Validate instance exists
        $instance = $this->db->get_instance($instance_id);
        if (!$instance) {
            return new WP_Error(
                'invalid_instance',
                'Form instance not found',
                ['status' => 404]
            );
        }

        // Initialize tracker
        $visitor_tracker = new VisitorTracker();
        $visitor_tracker->init();

        $this->handoff_tracker = new HandoffTracker($visitor_tracker);

        // Create the handoff
        $handoff = $this->handoff_tracker->create_handoff($instance_id, $destination_url, $params);

        if (isset($handoff['error'])) {
            return new WP_Error(
                'handoff_failed',
                $handoff['error'],
                ['status' => 500]
            );
        }

        // Build the redirect URL through our endpoint
        $redirect_url = rest_url(self::NAMESPACE . '/handoff/' . $handoff['token']);

        return new WP_REST_Response([
            'success' => true,
            'handoff_id' => $handoff['handoff_id'],
            'token' => $handoff['token'],
            'redirect_url' => $redirect_url,
            'direct_url' => $handoff['redirect_url'],
        ], 201);
    }

    /**
     * Process a handoff redirect
     * This endpoint performs the actual redirect to the external system
     */
    public function process_redirect(WP_REST_Request $request): void {
        $token = $request->get_param('token');

        $this->handoff_tracker = new HandoffTracker();

        // Get the destination URL
        $destination = $this->handoff_tracker->process_redirect($token);

        if (!$destination) {
            // Invalid or expired token - redirect to home
            wp_safe_redirect(home_url('/'));
            exit;
        }

        // Open-redirect guard (defense in depth): never redirect to a stored
        // destination that isn't a well-formed absolute http(s) URL — even if a
        // bad value was persisted before this validation existed.
        if (!self::is_safe_destination_url($destination)) {
            $this->db->log('warning', 'Handoff redirect blocked: unsafe destination', [
                'token' => $token,
                'destination' => $destination,
            ]);
            wp_safe_redirect(home_url('/'));
            exit;
        }

        // Get handoff details for additional tracking
        $handoff = $this->handoff_tracker->get_handoff($token);

        // Build final URL with tracking token appended
        $final_url = add_query_arg('isf_ref', $token, $destination);

        // Log the redirect
        $this->db->log('info', 'Handoff redirect processed', [
            'token' => $token,
            'destination' => $destination,
            'instance_id' => $handoff['instance_id'] ?? null,
        ]);

        // Perform the redirect
        // Using wp_redirect instead of wp_safe_redirect since we're going to external domain
        wp_redirect($final_url, 302);
        exit;
    }

    /**
     * Get handoff status
     */
    public function get_status(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $token = $request->get_param('token');

        $this->handoff_tracker = new HandoffTracker();
        $handoff = $this->handoff_tracker->get_handoff($token);

        if (!$handoff) {
            return new WP_Error(
                'not_found',
                'Handoff not found',
                ['status' => 404]
            );
        }

        return new WP_REST_Response([
            'token' => $token,
            'status' => $handoff['status'],
            'created_at' => $handoff['created_at'],
            'completed_at' => $handoff['completed_at'],
            'destination_url' => $handoff['destination_url'],
        ]);
    }

    /**
     * Handle handoff redirect from query parameter
     * Called from template_redirect hook for isf_handoff parameter
     */
    public static function handle_redirect_param(): void {
        if (!isset($_GET['isf_handoff']) || $_GET['isf_handoff'] === '') {
            return;
        }

        $token = sanitize_text_field($_GET['isf_handoff']);

        // Validate token format
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return;
        }

        $handoff_tracker = new HandoffTracker();
        $destination = $handoff_tracker->process_redirect($token);

        if (!$destination) {
            return;
        }

        // Open-redirect guard (defense in depth) — mirror process_redirect().
        if (!self::is_safe_destination_url($destination)) {
            return;
        }

        // Build final URL with tracking token
        $final_url = add_query_arg('isf_ref', $token, $destination);

        // Perform redirect
        wp_redirect($final_url, 302);
        exit;
    }

    /**
     * Validate a handoff destination URL.
     *
     * Blocks open-redirect / phishing / XSS vectors by requiring a well-formed
     * ABSOLUTE http(s) URL that has a host. Rejects javascript:, data:,
     * vbscript:, file:, protocol-relative (//host), malformed URLs, and any
     * value carrying control characters that could smuggle a dangerous scheme
     * past a lenient parser (e.g. "java\nscript:...").
     *
     * Pure / WordPress-free so the invariant can be unit-tested directly.
     */
    public static function is_safe_destination_url(?string $url): bool {
        if (!is_string($url) || $url === '') {
            return false;
        }

        // Reject control characters (incl. embedded NUL / CR / LF / TAB).
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }
}

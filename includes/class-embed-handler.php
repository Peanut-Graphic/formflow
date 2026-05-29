<?php
/**
 * Embed Handler
 *
 * Handles embeddable widget functionality for external websites.
 * Generates embed codes and serves forms via iframe or JavaScript injection.
 *
 * @package FormFlow
 * @since 2.1.0
 */

namespace ISF;

use ISF\Database\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EmbedHandler
 */
class EmbedHandler {

    /**
     * Database instance
     */
    private Database $db;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database();
    }

    /**
     * Initialize embed handler
     */
    public function init(): void {
        // Register REST API endpoints for embed
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Handle iframe embed requests
        add_action('template_redirect', [$this, 'handle_iframe_request']);

        // Add CORS headers for embed requests
        add_action('rest_api_init', [$this, 'add_cors_headers']);

        // Register embed assets
        add_action('wp_enqueue_scripts', [$this, 'register_embed_assets']);
    }

    /**
     * Register REST API routes for embed
     */
    public function register_rest_routes(): void {
        // Public: 16–64 char alphanumeric token in the URL gates access.
        // Embeds are designed to be served on third-party origins where
        // no WP session is available.
        register_rest_route('isf/v1', '/embed/config/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_embed_config'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return preg_match('/^[a-zA-Z0-9]{16,64}$/', $param);
                    },
                ],
            ],
        ]);

        // Public: embed form-submission endpoint. Same trust model as
        // /wp-admin/admin-ajax.php?action=isf_submit_enrollment — the
        // form lives on a third-party site, the auth is the embed token
        // in the request body.
        register_rest_route('isf/v1', '/embed/submit', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_embed_submission'],
            'permission_callback' => '__return_true',
        ]);

        // Public: embed validation pre-flight (called from frontend JS
        // before the submit). No state mutation, no PII written.
        register_rest_route('isf/v1', '/embed/validate', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_embed_validation'],
            'permission_callback' => '__return_true',
        ]);

        // Public: embed schedule-slot pre-flight. Token in payload.
        register_rest_route('isf/v1', '/embed/schedule', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_embed_schedule'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Add CORS headers for embed requests
     */
    public function add_cors_headers(): void {
        // Only for embed endpoints
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/isf/v1/embed') === false) {
            return;
        }

        $allowed_origins = $this->get_allowed_origins();
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, $allowed_origins) || in_array('*', $allowed_origins)) {
            header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Embed-Token');
            header('Access-Control-Allow-Credentials: true');
        }

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(200);
            exit;
        }
    }

    /**
     * Get allowed origins for CORS
     *
     * @return array
     */
    private function get_allowed_origins(): array {
        $settings = get_option('isf_settings', []);
        $origins = $settings['embed_allowed_origins'] ?? '*';

        if ($origins === '*') {
            return ['*'];
        }

        return array_filter(array_map('trim', explode("\n", $origins)));
    }

    /**
     * Handle iframe embed request
     */
    public function handle_iframe_request(): void {
        if (!isset($_GET['isf_embed']) || !isset($_GET['token'])) {
            return;
        }

        $token = sanitize_text_field($_GET['token']);
        $instance = $this->get_instance_by_embed_token($token);

        if (!$instance) {
            wp_die(__('Invalid embed token', 'formflow'), '', ['response' => 403]);
        }

        // Output minimal iframe page
        $this->render_iframe_page($instance);
        exit;
    }

    /**
     * Render iframe embed page
     *
     * @param array $instance
     */
    private function render_iframe_page(array $instance): void {
        $branding = Branding::instance();

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($instance['name']); ?></title>
            <style>
                :root {
                    --isf-primary: <?php echo esc_attr($branding->get('primary_color')); ?>;
                    --isf-secondary: <?php echo esc_attr($branding->get('secondary_color')); ?>;
                }
                * { box-sizing: border-box; }
                body {
                    margin: 0;
                    padding: 20px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                    background: transparent;
                }
                .isf-embed-container {
                    max-width: 100%;
                }
            </style>
            <?php
            // Enqueue form styles
            wp_enqueue_style('isf-forms');
            wp_print_styles('isf-forms');
            ?>
        </head>
        <body>
            <div class="isf-embed-container">
                <?php
                // Render the form
                $public = new Frontend\Frontend();
                echo $public->render_form_shortcode(['instance' => $instance['slug']]);
                ?>
            </div>
            <?php
            // Enqueue form scripts
            wp_enqueue_script('isf-enrollment');
            wp_print_scripts('isf-enrollment');
            ?>
            <script>
                // Notify parent frame of height changes
                (function() {
                    function sendHeight() {
                        var height = document.body.scrollHeight;
                        parent.postMessage({ type: 'isf-resize', height: height }, '*');
                    }

                    // Send initial height
                    sendHeight();

                    // Observe DOM changes
                    var observer = new MutationObserver(sendHeight);
                    observer.observe(document.body, { childList: true, subtree: true });

                    // Send on window resize
                    window.addEventListener('resize', sendHeight);
                })();
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * Get embed config via REST API
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_embed_config(\WP_REST_Request $request): \WP_REST_Response {
        $token = $request->get_param('token');
        $instance = $this->get_instance_by_embed_token($token);

        if (!$instance) {
            return new \WP_REST_Response(['error' => 'Invalid token'], 403);
        }

        $branding = Branding::instance();
        $settings = json_decode($instance['settings'] ?? '{}', true);
        $features = $settings['features'] ?? [];

        $config = [
            'instance_id' => $instance['id'],
            'name' => $instance['name'],
            'form_type' => $instance['form_type'],
            'utility' => $instance['utility'],
            'branding' => [
                'primary_color' => $branding->get('primary_color'),
                'secondary_color' => $branding->get('secondary_color'),
                'logo_url' => $branding->get('logo_url'),
                'form_title' => $branding->get('form_title'),
                'powered_by' => $branding->get('show_powered_by') ? $branding->get('powered_by_text') : null,
            ],
            'features' => [
                'inline_validation' => !empty($features['inline_validation']['enabled']),
                'auto_save' => !empty($features['auto_save']['enabled']),
                'spanish_translation' => !empty($features['spanish_translation']['enabled']),
            ],
            'endpoints' => [
                'submit' => rest_url('isf/v1/embed/submit'),
                'validate' => rest_url('isf/v1/embed/validate'),
                'schedule' => rest_url('isf/v1/embed/schedule'),
            ],
            'nonce' => wp_create_nonce('isf_embed_' . $token),
        ];

        /**
         * Filter embed configuration
         *
         * @param array $config Embed configuration
         * @param array $instance Instance data
         */
        $config = apply_filters('isf_embed_config', $config, $instance);

        return new \WP_REST_Response($config);
    }

    /**
     * Handle embed form submission
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_embed_submission(\WP_REST_Request $request): \WP_REST_Response {
        $token = $request->get_header('X-Embed-Token');
        $instance = $this->get_instance_by_embed_token($token);

        if (!$instance) {
            return new \WP_REST_Response(['error' => 'Invalid token'], 403);
        }

        // Verify nonce
        $nonce = $request->get_header('X-WP-Nonce');
        if (!wp_verify_nonce($nonce, 'isf_embed_' . $token)) {
            return new \WP_REST_Response(['error' => 'Invalid nonce'], 403);
        }

        // Get form data
        $form_data = $request->get_json_params();

        // Process submission through form handler
        $handler = new Forms\FormHandler();
        $result = $handler->process_enrollment($instance['id'], $form_data);

        return new \WP_REST_Response($result);
    }

    /**
     * Handle embed account validation
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_embed_validation(\WP_REST_Request $request): \WP_REST_Response {
        $token = $request->get_header('X-Embed-Token');
        $instance = $this->get_instance_by_embed_token($token);

        if (!$instance) {
            return new \WP_REST_Response(['error' => 'Invalid token'], 403);
        }

        $data = $request->get_json_params();

        // Get connector and validate
        $connector = $this->get_connector_for_instance($instance);
        if (!$connector) {
            return new \WP_REST_Response(['error' => 'Connector not available'], 500);
        }

        $config = $this->get_connector_config($instance);
        $result = $connector->validate_account($data, $config);

        return new \WP_REST_Response($result->toArray());
    }

    /**
     * Handle embed schedule request
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_embed_schedule(\WP_REST_Request $request): \WP_REST_Response {
        $token = $request->get_header('X-Embed-Token');
        $instance = $this->get_instance_by_embed_token($token);

        if (!$instance) {
            return new \WP_REST_Response(['error' => 'Invalid token'], 403);
        }

        $data = $request->get_json_params();

        // Get connector and fetch slots
        $connector = $this->get_connector_for_instance($instance);
        if (!$connector) {
            return new \WP_REST_Response(['error' => 'Connector not available'], 500);
        }

        $config = $this->get_connector_config($instance);
        $result = $connector->get_schedule_slots($data, $config);

        return new \WP_REST_Response($result->toArray());
    }

    /**
     * Get instance by embed token
     *
     * @param string $token
     * @return array|null
     */
    private function get_instance_by_embed_token(string $token): ?array {
        global $wpdb;

        $table = $wpdb->prefix . ISF_TABLE_INSTANCES;

        $instance = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE embed_token = %s AND is_active = 1",
                $token
            ),
            ARRAY_A
        );

        return $instance ?: null;
    }

    /**
     * Get connector for instance
     *
     * @param array $instance
     * @return Api\ApiConnectorInterface|null
     */
    private function get_connector_for_instance(array $instance): ?Api\ApiConnectorInterface {
        $settings = json_decode($instance['settings'] ?? '{}', true);
        $connector_id = $settings['connector'] ?? 'intellisource';

        return Api\ConnectorRegistry::instance()->get($connector_id);
    }

    /**
     * Get connector configuration from instance
     *
     * @param array $instance
     * @return array
     */
    private function get_connector_config(array $instance): array {
        $encryption = new Encryption();

        return [
            'api_endpoint' => $instance['api_endpoint'] ?? '',
            'api_password' => $encryption->decrypt($instance['api_password'] ?? ''),
            'test_mode' => (bool) ($instance['test_mode'] ?? false),
        ];
    }

    /**
     * Generate embed token for instance
     *
     * @param int $instance_id
     * @return string
     */
    public function generate_embed_token(int $instance_id): string {
        $token = bin2hex(random_bytes(24));

        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_INSTANCES;

        $wpdb->update(
            $table,
            ['embed_token' => $token],
            ['id' => $instance_id],
            ['%s'],
            ['%d']
        );

        return $token;
    }

    /**
     * Get embed code for instance
     *
     * @param int $instance_id
     * @param string $type 'iframe' or 'script'
     * @return array
     */
    public function get_embed_code(int $instance_id, string $type = 'script'): array {
        $instance = $this->db->get_instance($instance_id);

        if (!$instance) {
            return ['error' => 'Instance not found'];
        }

        // Generate token if not exists
        $token = $instance['embed_token'] ?? '';
        if (empty($token)) {
            $token = $this->generate_embed_token($instance_id);
        }

        $embed_url = add_query_arg([
            'isf_embed' => '1',
            'token' => $token,
        ], home_url('/'));

        $script_url = ISF_PLUGIN_URL . 'public/assets/js/embed.js';

        if ($type === 'iframe') {
            $code = sprintf(
                '<iframe src="%s" width="100%%" height="800" frameborder="0" style="border: none; max-width: 100%%;"></iframe>',
                esc_url($embed_url)
            );
        } else {
            $code = sprintf(
                '<div id="isf-form-%s" data-isf-token="%s"></div>' . "\n" .
                '<script src="%s" async></script>',
                esc_attr($instance_id),
                esc_attr($token),
                esc_url($script_url)
            );
        }

        return [
            'code' => $code,
            'token' => $token,
            'iframe_url' => $embed_url,
            'script_url' => $script_url,
        ];
    }

    /**
     * Register embed assets
     */
    public function register_embed_assets(): void {
        wp_register_script(
            'isf-embed',
            ISF_PLUGIN_URL . 'public/assets/js/embed.js',
            [],
            ISF_VERSION,
            true
        );
    }
}

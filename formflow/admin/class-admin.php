<?php
namespace ISF\Admin;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Admin Controller
 *
 * Handles the WordPress admin interface for managing forms.
 */


use ISF\Database\Database;
use ISF\Security;
use ISF\Api\ApiClient;

class Admin {

    private Database $db;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database();

        // Register dashboard widget
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
    }

    /**
     * Add dashboard widget for system health
     */
    public function add_dashboard_widget(): void {
        wp_add_dashboard_widget(
            'isf_health_widget',
            __('FormFlow - System Health', 'formflow'),
            [$this, 'render_health_widget']
        );
    }

    /**
     * Render the dashboard health widget
     */
    public function render_health_widget(): void {
        require_once ISF_PLUGIN_DIR . 'includes/class-diagnostics.php';

        $diagnostics = new \ISF\Diagnostics();
        $status = $diagnostics->quick_health_check();

        $icon_class = $status['overall'] === 'healthy' ? 'yes-alt' : ($status['overall'] === 'warning' ? 'warning' : 'dismiss');
        $status_color = $status['overall'] === 'healthy' ? '#46b450' : ($status['overall'] === 'warning' ? '#ffb900' : '#dc3232');
        $status_text = $status['overall'] === 'healthy' ? __('All Systems Operational', 'formflow') :
                       ($status['overall'] === 'warning' ? __('Some Issues Detected', 'formflow') :
                       __('Critical Issues Found', 'formflow'));

        ?>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
            <span class="dashicons dashicons-<?php echo esc_attr($icon_class); ?>" style="color: <?php echo esc_attr($status_color); ?>; font-size: 32px; width: 32px; height: 32px;"></span>
            <div>
                <strong style="font-size: 14px;"><?php echo esc_html($status_text); ?></strong>
                <?php if (!empty($status['issues'])): ?>
                    <p style="margin: 0; color: #666; font-size: 12px;"><?php echo esc_html(implode(', ', $status['issues'])); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 15px;">
            <div style="text-align: center; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                <span class="dashicons dashicons-<?php echo $status['checks']['database'] ? 'yes' : 'no'; ?>" style="color: <?php echo $status['checks']['database'] ? '#46b450' : '#dc3232'; ?>;"></span>
                <div style="font-size: 12px; margin-top: 5px;"><?php esc_html_e('Database', 'formflow'); ?></div>
            </div>
            <div style="text-align: center; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                <span class="dashicons dashicons-<?php echo $status['checks']['encryption'] ? 'yes' : 'no'; ?>" style="color: <?php echo $status['checks']['encryption'] ? '#46b450' : '#dc3232'; ?>;"></span>
                <div style="font-size: 12px; margin-top: 5px;"><?php esc_html_e('Encryption', 'formflow'); ?></div>
            </div>
            <div style="text-align: center; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                <span class="dashicons dashicons-<?php echo $status['checks']['cron'] ? 'yes' : 'no'; ?>" style="color: <?php echo $status['checks']['cron'] ? '#46b450' : '#dc3232'; ?>;"></span>
                <div style="font-size: 12px; margin-top: 5px;"><?php esc_html_e('Cron Jobs', 'formflow'); ?></div>
            </div>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid #eee;">
            <span style="color: #666; font-size: 12px;">
                <?php echo esc_html(sprintf(__('%d active instance(s)', 'formflow'), $status['instance_count'] ?? 0)); ?>
            </span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=isf-diagnostics')); ?>" class="button button-small">
                <?php esc_html_e('Run Full Diagnostics', 'formflow'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Register admin menu
     *
     * Consolidated menu structure (6 items instead of 11):
     * 1. Dashboard - Overview and quick stats
     * 2. Forms - Add/edit form instances (hidden, accessed via Dashboard)
     * 3. Data - Submissions, Analytics, Activity Logs (tabbed)
     * 4. Scheduling - Appointment availability
     * 5. Automation - Webhooks, Reports (tabbed)
     * 6. Tools - Settings, Diagnostics, Compliance (tabbed)
     */
    public function add_admin_menu(): void {
        // Main menu
        add_menu_page(
            __('FormFlow', 'formflow'),
            __('FF Forms', 'formflow'),
            'manage_options',
            'isf-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-forms',
            30
        );

        // Dashboard (same as main)
        add_submenu_page(
            'isf-dashboard',
            __('Dashboard', 'formflow'),
            __('Dashboard', 'formflow'),
            'manage_options',
            'isf-dashboard',
            [$this, 'render_dashboard']
        );

        // Add/Edit Form Instance (hidden from menu, accessed via Dashboard)
        add_submenu_page(
            null, // Hidden from menu
            __('Form Editor', 'formflow'),
            __('Form Editor', 'formflow'),
            'manage_options',
            'isf-instance-editor',
            [$this, 'render_instance_editor']
        );

        // Data (Submissions + Analytics + Logs - tabbed)
        add_submenu_page(
            'isf-dashboard',
            __('Data & Analytics', 'formflow'),
            __('Data', 'formflow'),
            'manage_options',
            'isf-data',
            [$this, 'render_data']
        );

        // Scheduling Availability
        add_submenu_page(
            'isf-dashboard',
            __('Schedule Availability', 'formflow'),
            __('Scheduling', 'formflow'),
            'manage_options',
            'isf-scheduling',
            [$this, 'render_scheduling']
        );

        // Test Page (hidden from menu, accessed via Dashboard quick actions)
        add_submenu_page(
            null, // Hidden from menu
            __('Form Tester', 'formflow'),
            __('Test', 'formflow'),
            'manage_options',
            'isf-test',
            [$this, 'render_test']
        );

        // Automation (Webhooks + Reports - tabbed)
        add_submenu_page(
            'isf-dashboard',
            __('Automation', 'formflow'),
            __('Automation', 'formflow'),
            'manage_options',
            'isf-automation',
            [$this, 'render_automation']
        );

        // Tools (Settings + Diagnostics + Compliance - tabbed)
        add_submenu_page(
            'isf-dashboard',
            __('Tools & Settings', 'formflow'),
            __('Tools', 'formflow'),
            'manage_options',
            'isf-tools',
            [$this, 'render_tools']
        );

        // Attribution - Marketing analytics and conversion tracking
        add_submenu_page(
            'isf-dashboard',
            __('Attribution', 'formflow'),
            __('Attribution', 'formflow'),
            'manage_options',
            'isf-attribution',
            [$this, 'render_attribution']
        );

        // Import Completions (hidden, accessed via Attribution page)
        add_submenu_page(
            null,
            __('Import Completions', 'formflow'),
            __('Import Completions', 'formflow'),
            'manage_options',
            'isf-import-completions',
            [$this, 'render_import_completions']
        );

        // Analytics Settings (hidden, accessed via Tools or Attribution page)
        add_submenu_page(
            null,
            __('Analytics Settings', 'formflow'),
            __('Analytics Settings', 'formflow'),
            'manage_options',
            'isf-analytics-settings',
            [$this, 'render_analytics_settings']
        );

        // Visual Form Builder (hidden, accessed via instance editor or dashboard)
        add_submenu_page(
            null,
            __('Form Builder', 'formflow'),
            __('Form Builder', 'formflow'),
            'manage_options',
            'isf-form-builder',
            [$this, 'render_form_builder']
        );

        // Legacy redirects - keep old URLs working
        add_submenu_page(null, '', '', 'manage_options', 'isf-logs', [$this, 'redirect_to_data']);
        add_submenu_page(null, '', '', 'manage_options', 'isf-analytics', [$this, 'redirect_to_data']);
        add_submenu_page(null, '', '', 'manage_options', 'isf-webhooks', [$this, 'redirect_to_automation']);
        add_submenu_page(null, '', '', 'manage_options', 'isf-reports', [$this, 'redirect_to_automation']);
        add_submenu_page(null, '', '', 'manage_options', 'isf-compliance', [$this, 'redirect_to_tools']);
        add_submenu_page(null, '', '', 'manage_options', 'isf-diagnostics', [$this, 'redirect_to_tools']);
        add_submenu_page(null, '', '', 'manage_options', 'isf-settings', [$this, 'redirect_to_tools']);
    }

    /**
     * Redirect legacy URLs to new consolidated pages
     */
    public function redirect_to_data(): void {
        $tab = 'submissions';
        $page = sanitize_text_field($_GET['page'] ?? '');
        if ($page === 'isf-analytics') {
            $tab = 'analytics';
        } elseif ($page === 'isf-logs') {
            $view = sanitize_text_field($_GET['view'] ?? 'submissions');
            $tab = $view === 'logs' ? 'activity' : $view;
        }
        wp_safe_redirect(admin_url('admin.php?page=isf-data&tab=' . $tab));
        exit;
    }

    public function redirect_to_automation(): void {
        $tab = sanitize_text_field($_GET['page'] ?? '') === 'isf-reports' ? 'reports' : 'webhooks';
        wp_safe_redirect(admin_url('admin.php?page=isf-automation&tab=' . $tab));
        exit;
    }

    public function redirect_to_tools(): void {
        $page = sanitize_text_field($_GET['page'] ?? '');
        $tab = 'settings';
        if ($page === 'isf-compliance') {
            $tab = 'compliance';
        } elseif ($page === 'isf-diagnostics') {
            $tab = 'diagnostics';
        }
        wp_safe_redirect(admin_url('admin.php?page=isf-tools&tab=' . $tab));
        exit;
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_styles(string $hook): void {
        if (!$this->is_plugin_page($hook)) {
            return;
        }

        wp_enqueue_style(
            'isf-admin',
            ISF_PLUGIN_URL . 'admin/assets/css/admin.css',
            [],
            ISF_VERSION
        );

        // Form Builder specific styles
        if ($this->is_form_builder_page($hook)) {
            wp_enqueue_style(
                'isf-form-builder',
                ISF_PLUGIN_URL . 'admin/assets/css/form-builder.css',
                ['isf-admin'],
                ISF_VERSION
            );
        }
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts(string $hook): void {
        if (!$this->is_plugin_page($hook)) {
            return;
        }

        // Enqueue jQuery UI Sortable for drag-and-drop functionality
        wp_enqueue_script('jquery-ui-sortable');

        wp_enqueue_script(
            'isf-admin',
            ISF_PLUGIN_URL . 'admin/assets/js/admin.js',
            ['jquery', 'jquery-ui-sortable'],
            ISF_VERSION,
            true
        );

        wp_localize_script('isf-admin', 'isf_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('isf_admin_nonce'),
            'strings' => [
                'confirm_delete' => __('Are you sure you want to delete this form? This cannot be undone.', 'formflow'),
                'saving' => __('Saving...', 'formflow'),
                'saved' => __('Saved!', 'formflow'),
                'error' => __('An error occurred. Please try again.', 'formflow'),
                'testing_api' => __('Testing connection...', 'formflow'),
                'api_success' => __('Connection successful!', 'formflow'),
                'api_failed' => __('Connection failed. Please check your settings.', 'formflow')
            ]
        ]);

        // Form Builder specific scripts
        if ($this->is_form_builder_page($hook)) {
            wp_enqueue_script('jquery-ui-draggable');
            wp_enqueue_script('jquery-ui-droppable');

            wp_enqueue_script(
                'isf-form-builder',
                ISF_PLUGIN_URL . 'admin/assets/js/form-builder.js',
                ['jquery', 'jquery-ui-sortable', 'jquery-ui-draggable', 'jquery-ui-droppable'],
                ISF_VERSION,
                true
            );
        }
    }

    /**
     * Check if current page is a plugin admin page
     */
    private function is_plugin_page(string $hook): bool {
        $plugin_pages = [
            'toplevel_page_isf-dashboard',
            'is-forms_page_isf-instance-editor',
            'is-forms_page_isf-data',
            'is-forms_page_isf-scheduling',
            'is-forms_page_isf-test',
            'is-forms_page_isf-automation',
            'is-forms_page_isf-tools',
            'is-forms_page_isf-form-builder',
            'is-forms_page_isf-attribution',
            // Legacy pages (redirects)
            'is-forms_page_isf-logs',
            'is-forms_page_isf-analytics',
            'is-forms_page_isf-webhooks',
            'is-forms_page_isf-reports',
            'is-forms_page_isf-compliance',
            'is-forms_page_isf-diagnostics',
            'is-forms_page_isf-settings'
        ];

        return in_array($hook, $plugin_pages);
    }

    /**
     * Check if current page is the form builder page
     */
    private function is_form_builder_page(string $hook): bool {
        return strpos($hook, 'isf-form-builder') !== false;
    }

    /**
     * Render the dashboard page
     */
    public function render_dashboard(): void {
        $instances = $this->db->get_instances();
        $stats = $this->db->get_statistics();

        // Get per-instance stats for quick overview
        $instance_stats = [];
        foreach ($instances as $instance) {
            $instance_stats[$instance['id']] = $this->db->get_statistics($instance['id']);
        }

        // Get recent submissions (last 10)
        $recent_submissions = $this->db->get_submissions([], 10, 0);

        // Get today's stats
        $today_start = date('Y-m-d 00:00:00');
        $today_stats = [
            'total' => $this->db->get_submission_count(['date_from' => $today_start]),
            'completed' => $this->db->get_submission_count(['date_from' => $today_start, 'status' => 'completed']),
        ];

        // Get cached API health status
        $api_health = $this->get_cached_api_health();

        include ISF_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Render the instance editor page
     */
    public function render_instance_editor(): void {
        $instance_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $instance = $instance_id ? $this->db->get_instance($instance_id) : null;

        // Utility presets
        $utilities = $this->get_utility_presets();

        include ISF_PLUGIN_DIR . 'admin/views/instance-editor.php';
    }

    /**
     * Render the logs page
     */
    public function render_logs(): void {
        $instances = $this->db->get_instances();

        // Get filter parameters
        $filters = [
            'instance_id' => isset($_GET['instance_id']) ? (int)$_GET['instance_id'] : 0,
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'type' => isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : ''
        ];

        // Pagination
        $page = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        // Get data based on view type
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'submissions';

        // Debug view doesn't need data - it fetches its own
        $items = [];
        $total_items = 0;
        $total_pages = 0;

        if ($view === 'logs') {
            $items = $this->db->get_logs($filters, $per_page, $offset);
            $total_items = count($this->db->get_logs($filters, 10000, 0));
            $total_pages = ceil($total_items / $per_page);
        } elseif ($view !== 'debug') {
            $items = $this->db->get_submissions($filters, $per_page, $offset);
            $total_items = $this->db->get_submission_count($filters);
            $total_pages = ceil($total_items / $per_page);
        }

        include ISF_PLUGIN_DIR . 'admin/views/logs.php';
    }

    /**
     * Render the analytics page
     */
    public function render_analytics(): void {
        $instances = $this->db->get_instances();

        // Get filter parameters
        $instance_id = isset($_GET['instance_id']) ? (int)$_GET['instance_id'] : 0;
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');
        $show_test = isset($_GET['show_test']) ? (bool)$_GET['show_test'] : false;
        $exclude_test = !$show_test;

        // Get test data counts
        $test_counts = $this->db->get_test_data_counts($instance_id ?: null);

        // Get analytics data (pass exclude_test flag)
        $summary = $this->db->get_analytics_summary($instance_id ?: null, $date_from, $date_to, $exclude_test);
        $funnel = $this->db->get_funnel_analytics($instance_id ?: null, $date_from, $date_to, $exclude_test);
        $timing = $this->db->get_step_timing_analytics($instance_id ?: null, $date_from, $date_to, $exclude_test);
        $dropoff = $this->db->get_dropoff_analysis($instance_id ?: null, $date_from, $date_to, $exclude_test);
        $devices = $this->db->get_device_analytics($instance_id ?: null, $date_from, $date_to, $exclude_test);
        $daily = $this->db->get_daily_analytics($instance_id ?: null, 30, $exclude_test);

        include ISF_PLUGIN_DIR . 'admin/views/analytics.php';
    }

    /**
     * Render the combined Data page (Submissions + Analytics + Activity)
     */
    public function render_data(): void {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'submissions';
        $instances = $this->db->get_instances();

        // Get filter parameters (shared across tabs)
        $filters = [
            'instance_id' => isset($_GET['instance_id']) ? (int)$_GET['instance_id'] : 0,
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'type' => isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : ''
        ];

        // Pagination
        $page = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        // Initialize data arrays
        $items = [];
        $total_items = 0;
        $total_pages = 0;

        // Tab-specific data
        if ($tab === 'submissions') {
            $items = $this->db->get_submissions($filters, $per_page, $offset);
            $total_items = $this->db->get_submission_count($filters);
            $total_pages = ceil($total_items / $per_page);
        } elseif ($tab === 'analytics') {
            $date_from = $filters['date_from'] ?: date('Y-m-d', strtotime('-30 days'));
            $date_to = $filters['date_to'] ?: date('Y-m-d');
            $show_test = isset($_GET['show_test']) ? (bool)$_GET['show_test'] : false;
            $exclude_test = !$show_test;
            $instance_id = $filters['instance_id'];

            $test_counts = $this->db->get_test_data_counts($instance_id ?: null);
            $summary = $this->db->get_analytics_summary($instance_id ?: null, $date_from, $date_to, $exclude_test);
            $funnel = $this->db->get_funnel_analytics($instance_id ?: null, $date_from, $date_to, $exclude_test);
            $timing = $this->db->get_step_timing_analytics($instance_id ?: null, $date_from, $date_to, $exclude_test);
            $dropoff = $this->db->get_dropoff_analysis($instance_id ?: null, $date_from, $date_to, $exclude_test);
            $devices = $this->db->get_device_analytics($instance_id ?: null, $date_from, $date_to, $exclude_test);
            $daily = $this->db->get_daily_analytics($instance_id ?: null, 30, $exclude_test);
        } elseif ($tab === 'activity') {
            $items = $this->db->get_logs($filters, $per_page, $offset);
            $total_items = count($this->db->get_logs($filters, 10000, 0));
            $total_pages = ceil($total_items / $per_page);
        }

        include ISF_PLUGIN_DIR . 'admin/views/data.php';
    }

    /**
     * Render the combined Automation page (Webhooks + Reports)
     */
    public function render_automation(): void {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'webhooks';
        $instances = $this->db->get_instances();

        // Tab-specific data
        if ($tab === 'webhooks') {
            require_once ISF_PLUGIN_DIR . 'includes/class-webhook-handler.php';
            $webhooks = $this->db->get_webhooks();
        } elseif ($tab === 'reports') {
            $scheduled_reports = $this->db->get_scheduled_reports();
        }

        include ISF_PLUGIN_DIR . 'admin/views/automation.php';
    }

    /**
     * Render the combined Tools page (Settings + Diagnostics + Compliance + License)
     */
    public function render_tools(): void {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'license';

        // Validate tab
        $valid_tabs = ['license', 'settings', 'diagnostics', 'compliance', 'accessibility'];
        if (!in_array($tab, $valid_tabs, true)) {
            $tab = 'license';
        }

        // Handle IP whitelist updates
        if (isset($_POST['formflow_whitelist_action']) && check_admin_referer('formflow_whitelist_action')) {
            $this->handle_whitelist_action();
        }

        // Tab-specific data
        if ($tab === 'license') {
            // License tab handles its own form processing
        } elseif ($tab === 'settings') {
            $settings = get_option('isf_settings', []);
        } elseif ($tab === 'diagnostics') {
            require_once ISF_PLUGIN_DIR . 'includes/class-diagnostics.php';
            $instances = $this->db->get_instances();
        } elseif ($tab === 'compliance') {
            $settings = get_option('isf_settings', []);
            $compliance_tab = isset($_GET['compliance_tab']) ? sanitize_text_field($_GET['compliance_tab']) : 'gdpr';
        } elseif ($tab === 'accessibility') {
            require_once ISF_PLUGIN_DIR . 'includes/class-accessibility.php';
        }

        include ISF_PLUGIN_DIR . 'admin/views/tools.php';
    }

    /**
     * Handle license activation/deactivation
     */
    private function handle_license_action(): void {
        $action = sanitize_text_field($_POST['formflow_license_action'] ?? '');
        $license = \ISF\LicenseManager::instance();

        if ($action === 'activate') {
            $key = sanitize_text_field($_POST['license_key'] ?? '');
            $result = $license->activate_license($key);

            if ($result['success']) {
                add_settings_error('isf_settings', 'license_activated', $result['message'], 'success');
            } else {
                add_settings_error('isf_settings', 'license_error', $result['message'], 'error');
            }
        } elseif ($action === 'deactivate') {
            $result = $license->deactivate_license();
            add_settings_error('isf_settings', 'license_deactivated', $result['message'], 'info');
        }
    }

    /**
     * Handle IP whitelist updates
     */
    private function handle_whitelist_action(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_text_field($_POST['formflow_whitelist_action'] ?? '');
        $license = \ISF\LicenseManager::instance();

        if ($action === 'add_current') {
            // Add current IP to whitelist
            $current_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            if (strpos($current_ip, ',') !== false) {
                $current_ip = trim(explode(',', $current_ip)[0]);
            }

            if (!empty($current_ip) && filter_var($current_ip, FILTER_VALIDATE_IP)) {
                $license->add_whitelisted_ip($current_ip);
                add_settings_error('isf_settings', 'ip_added', __('Your IP address has been whitelisted.', 'formflow'), 'success');
            } else {
                add_settings_error('isf_settings', 'ip_error', __('Could not detect your IP address.', 'formflow'), 'error');
            }
        } elseif ($action === 'update') {
            // Update full whitelist from textarea
            $whitelist_text = sanitize_textarea_field($_POST['whitelist_ips'] ?? '');
            $ips = array_filter(array_map('trim', explode("\n", $whitelist_text)));

            // Validate each IP
            $valid_ips = [];
            foreach ($ips as $ip) {
                // Allow exact IPs, CIDR notation, and wildcards
                if (filter_var($ip, FILTER_VALIDATE_IP) ||
                    preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/\d{1,2}$/', $ip) ||
                    preg_match('/^\d{1,3}\.[\d\*]{1,3}\.[\d\*]{1,3}\.[\d\*]{1,3}$/', $ip)) {
                    $valid_ips[] = $ip;
                }
            }

            $license->set_whitelisted_ips($valid_ips);
            add_settings_error('isf_settings', 'whitelist_updated', __('IP whitelist updated.', 'formflow'), 'success');
        }
    }

    /**
     * Render the test page
     */
    public function render_test(): void {
        $instances = $this->db->get_instances() ?: [];
        $instance_id = isset($_GET['instance_id']) ? (int)$_GET['instance_id'] : 0;
        $instance = $instance_id ? $this->db->get_instance($instance_id) : null;

        include ISF_PLUGIN_DIR . 'admin/views/test.php';
    }

    /**
     * Render the scheduling availability page
     */
    public function render_scheduling(): void {
        $instances = $this->db->get_instances() ?: [];

        // Get selected instance
        $instance_id = isset($_GET['instance_id']) ? (int)$_GET['instance_id'] : 0;

        // Don't auto-select an instance - require user to choose
        $instance = $instance_id ? $this->db->get_instance($instance_id) : null;
        $schedule_data = null;
        $promo_codes = [];
        $start_date = $this->calculate_start_date();

        if ($instance) {
            // Get schedule slots
            $schedule_data = $this->fetch_schedule_for_admin($instance);
            // Get promo codes
            $promo_codes = $this->fetch_promo_codes_for_admin($instance);
        }

        include ISF_PLUGIN_DIR . 'admin/views/scheduling.php';
    }

    /**
     * Fetch schedule data for admin view
     */
    private function fetch_schedule_for_admin(array $instance): ?array {
        try {
            $demo_mode = $instance['settings']['demo_mode'] ?? false;

            if ($demo_mode) {
                $api = new \ISF\Api\MockApiClient($instance['id']);
            } else {
                $api = new ApiClient(
                    $instance['api_endpoint'],
                    $instance['api_password'],
                    $instance['test_mode'],
                    $instance['id']
                );
            }

            // Get slots starting from today + 3 business days
            $start_date = $this->calculate_start_date();
            $result = $api->get_schedule_slots('ADMIN-VIEW', $start_date, []);

            // Use get_slots_for_display for properly formatted data
            return [
                'slots' => $result->get_slots_for_display(1),
                'fsr_no' => $result->get_fsr_no(),
                'has_slots' => $result->has_slots()
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Fetch promo codes for admin view
     */
    private function fetch_promo_codes_for_admin(array $instance): array {
        try {
            $demo_mode = $instance['settings']['demo_mode'] ?? false;

            if ($demo_mode) {
                $api = new \ISF\Api\MockApiClient($instance['id']);
            } else {
                $api = new ApiClient(
                    $instance['api_endpoint'],
                    $instance['api_password'],
                    $instance['test_mode'],
                    $instance['id']
                );
            }

            return $api->get_promo_codes();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Calculate scheduling start date (3+ business days out)
     */
    private function calculate_start_date(): string {
        $days_to_add = 3;
        $day_of_week = (int)date('w');

        // Adjust for weekends
        if ($day_of_week === 3) { // Wednesday
            $days_to_add = 5;
        } elseif ($day_of_week === 4) { // Thursday
            $days_to_add = 5;
        } elseif ($day_of_week === 5) { // Friday
            $days_to_add = 5;
        } elseif ($day_of_week === 6) { // Saturday
            $days_to_add = 4;
        }

        return date('m/d/Y', strtotime("+{$days_to_add} days"));
    }

    /**
     * Render the settings page
     */
    public function render_settings(): void {
        // Handle form submission
        if (isset($_POST['isf_save_settings']) && check_admin_referer('isf_settings_nonce')) {
            $this->save_settings();
        }

        $settings = get_option('isf_settings', []);

        include ISF_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Render the webhooks page
     */
    public function render_webhooks(): void {
        require_once ISF_PLUGIN_DIR . 'includes/class-webhook-handler.php';

        $instances = $this->db->get_instances();
        $webhooks = $this->db->get_webhooks();

        include ISF_PLUGIN_DIR . 'admin/views/webhooks.php';
    }

    /**
     * Render the diagnostics page
     */
    public function render_diagnostics(): void {
        $instances = $this->db->get_instances();

        include ISF_PLUGIN_DIR . 'admin/views/diagnostics.php';
    }

    /**
     * Save plugin settings
     */
    private function save_settings(): void {
        $old_settings = get_option('isf_settings', []);

        $settings = [
            'log_retention_days' => (int)($_POST['log_retention_days'] ?? 90),
            'session_timeout_minutes' => (int)($_POST['session_timeout_minutes'] ?? 30),
            'rate_limit_requests' => (int)($_POST['rate_limit_requests'] ?? 120),
            'rate_limit_window' => (int)($_POST['rate_limit_window'] ?? 60),
            'disable_rate_limit' => isset($_POST['disable_rate_limit']) ? true : false,
            'cleanup_abandoned_hours' => (int)($_POST['cleanup_abandoned_hours'] ?? 24),
            'google_places_api_key' => sanitize_text_field($_POST['google_places_api_key'] ?? ''),
        ];

        // Preserve retention policy settings
        if (isset($old_settings['retention_submissions_days'])) {
            $settings['retention_submissions_days'] = $old_settings['retention_submissions_days'];
            $settings['retention_analytics_days'] = $old_settings['retention_analytics_days'] ?? 180;
            $settings['retention_audit_log_days'] = $old_settings['retention_audit_log_days'] ?? 365;
            $settings['retention_api_usage_days'] = $old_settings['retention_api_usage_days'] ?? 90;
            $settings['retention_enabled'] = $old_settings['retention_enabled'] ?? false;
            $settings['anonymize_instead_of_delete'] = $old_settings['anonymize_instead_of_delete'] ?? true;
        }

        update_option('isf_settings', $settings);

        // Log the settings change
        $this->db->log_audit(
            'settings_update',
            'settings',
            null,
            'Global Settings',
            [
                'changed_settings' => array_keys(array_diff_assoc($settings, $old_settings)),
            ]
        );

        add_settings_error(
            'isf_settings',
            'settings_updated',
            __('Settings saved successfully.', 'formflow'),
            'success'
        );
    }

    /**
     * Get utility preset configurations
     */
    public function get_utility_presets(): array {
        return [
            'delmarva_de' => [
                'name' => 'Delmarva Power - Delaware',
                'api_endpoint' => 'https://ph.powerportal.com/phiIntelliSOURCE/api',
                'support_email_from' => 'support_delmarvaewr@powerportal.com',
                'support_email_to' => 'customercare@comverge.com,comverge@rdimarketing.com'
            ],
            'delmarva_md' => [
                'name' => 'Delmarva Power - Maryland',
                'api_endpoint' => 'https://ph.powerportal.com/phiIntelliSOURCE/api',
                'support_email_from' => 'support_delmarvaewr@powerportal.com',
                'support_email_to' => 'customercare@comverge.com,comverge@rdimarketing.com'
            ],
            'pepco_md' => [
                'name' => 'Pepco - Maryland',
                'api_endpoint' => 'https://ph.powerportal.com/phiIntelliSOURCE/api',
                'support_email_from' => 'support_pepcoewr@powerportal.com',
                'support_email_to' => 'customercare@comverge.com,comverge@rdimarketing.com'
            ],
            'pepco_dc' => [
                'name' => 'Pepco - District of Columbia',
                'api_endpoint' => 'https://ph.powerportal.com/phiIntelliSOURCE/api',
                'support_email_from' => 'support_pepcoewr@powerportal.com',
                'support_email_to' => 'customercare@comverge.com,comverge@rdimarketing.com'
            ],
            'custom' => [
                'name' => 'Custom Configuration',
                'api_endpoint' => '',
                'support_email_from' => '',
                'support_email_to' => ''
            ]
        ];
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * Save a form instance via AJAX
     */
    public function ajax_save_instance(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

        // Get existing instance settings if updating
        $existing_settings = [];
        if ($id) {
            $existing = $this->db->get_instance($id);
            $existing_settings = $existing['settings'] ?? [];
        }

        // Parse settings from JSON if provided
        $new_settings = [];
        if (!empty($_POST['settings'])) {
            $decoded = json_decode(stripslashes($_POST['settings']), true);
            if (is_array($decoded)) {
                $new_settings = $decoded;
            }
        }

        // Sanitize content settings
        if (!empty($new_settings['content'])) {
            $new_settings['content'] = array_map('sanitize_textarea_field', $new_settings['content']);
        }

        // Sanitize other settings
        if (isset($new_settings['default_state'])) {
            $new_settings['default_state'] = sanitize_text_field($new_settings['default_state']);
        }
        if (isset($new_settings['support_phone'])) {
            $new_settings['support_phone'] = sanitize_text_field($new_settings['support_phone']);
        }

        // Merge with existing settings and add demo_mode
        $settings = array_merge($existing_settings, $new_settings, [
            'demo_mode' => !empty($_POST['demo_mode']) && $_POST['demo_mode'] !== '0'
        ]);

        $data = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'slug' => sanitize_title($_POST['slug'] ?? ''),
            'utility' => sanitize_text_field($_POST['utility'] ?? ''),
            'form_type' => sanitize_text_field($_POST['form_type'] ?? 'enrollment'),
            'api_endpoint' => esc_url_raw($_POST['api_endpoint'] ?? ''),
            'api_password' => $_POST['api_password'] ?? '',
            'support_email_from' => sanitize_email($_POST['support_email_from'] ?? ''),
            'support_email_to' => sanitize_textarea_field($_POST['support_email_to'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'test_mode' => isset($_POST['test_mode']) ? 1 : 0,
            'settings' => $settings
        ];

        // Validate required fields (API endpoint not required in demo mode)
        $demo_mode = $data['settings']['demo_mode'] ?? false;
        if (empty($data['name']) || empty($data['slug'])) {
            wp_send_json_error([
                'message' => __('Please fill in Name and Slug fields.', 'formflow')
            ]);
            return;
        }

        // API endpoint required unless demo mode is enabled
        if (!$demo_mode && empty($data['api_endpoint'])) {
            wp_send_json_error([
                'message' => __('API Endpoint is required unless Demo Mode is enabled.', 'formflow')
            ]);
            return;
        }

        // Set a placeholder endpoint for demo mode if not provided
        if ($demo_mode && empty($data['api_endpoint'])) {
            $data['api_endpoint'] = 'https://demo.example.com/api';
        }

        // Check for duplicate slug
        $existing = $this->db->get_instance_by_slug($data['slug']);
        if ($existing && $existing['id'] != $id) {
            wp_send_json_error([
                'message' => __('A form with this slug already exists.', 'formflow')
            ]);
            return;
        }

        if ($id) {
            // Update existing
            $success = $this->db->update_instance($id, $data);
            $message = __('Form updated successfully.', 'formflow');
        } else {
            // Create new
            $id = $this->db->create_instance($data);
            $success = $id !== false;
            $message = __('Form created successfully.', 'formflow');
        }

        if ($success) {
            // Log the action
            $this->db->log_audit(
                $id && isset($_POST['id']) && $_POST['id'] ? 'instance_update' : 'instance_create',
                'instance',
                $id,
                $data['name'],
                [
                    'slug' => $data['slug'],
                    'utility' => $data['utility'],
                    'form_type' => $data['form_type'],
                    'is_active' => $data['is_active'],
                    'test_mode' => $data['test_mode'],
                ]
            );

            wp_send_json_success([
                'message' => $message,
                'id' => $id
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to save form. Please try again.', 'formflow')
            ]);
        }
    }

    /**
     * Delete a form instance via AJAX
     */
    public function ajax_delete_instance(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

        if (!$id) {
            wp_send_json_error([
                'message' => __('Invalid form ID.', 'formflow')
            ]);
            return;
        }

        // Get instance info before deletion for audit log
        $instance = $this->db->get_instance($id);
        $instance_name = $instance ? $instance['name'] : 'Unknown';

        $success = $this->db->delete_instance($id);

        if ($success) {
            // Log the deletion
            $this->db->log_audit(
                'instance_delete',
                'instance',
                $id,
                $instance_name,
                [
                    'slug' => $instance['slug'] ?? '',
                    'utility' => $instance['utility'] ?? '',
                ]
            );

            wp_send_json_success([
                'message' => __('Form deleted successfully.', 'formflow')
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to delete form.', 'formflow')
            ]);
        }
    }

    /**
     * Test API connection via AJAX
     */
    public function ajax_test_api(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $endpoint = esc_url_raw($_POST['api_endpoint'] ?? '');
        $password = $_POST['api_password'] ?? '';

        if (empty($endpoint) || empty($password)) {
            wp_send_json_error([
                'message' => __('API endpoint and password are required.', 'formflow')
            ]);
            return;
        }

        try {
            $client = new ApiClient($endpoint, $password, true);
            $success = $client->test_connection();

            if ($success) {
                wp_send_json_success([
                    'message' => __('API connection successful!', 'formflow')
                ]);
            } else {
                wp_send_json_error([
                    'message' => __('API connection failed. Please check your credentials.', 'formflow')
                ]);
            }
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => sprintf(
                    __('API error: %s', 'formflow'),
                    $e->getMessage()
                )
            ]);
        }
    }

    /**
     * Test form functionality via AJAX
     */
    public function ajax_test_form(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $instance_id = (int)($_POST['instance_id'] ?? 0);
        $test_type = sanitize_text_field($_POST['test_type'] ?? '');
        $test_data = $_POST['test_data'] ?? [];

        if (!$instance_id) {
            wp_send_json_error(['message' => 'No instance selected.']);
            return;
        }

        $instance = $this->db->get_instance($instance_id);
        if (!$instance) {
            wp_send_json_error(['message' => 'Instance not found.']);
            return;
        }

        try {
            $demo_mode = $instance['settings']['demo_mode'] ?? false;

            if ($demo_mode) {
                $api = new \ISF\Api\MockApiClient($instance['id']);
            } else {
                $api = new ApiClient(
                    $instance['api_endpoint'],
                    $instance['api_password'],
                    $instance['test_mode'],
                    $instance['id']
                );
            }

            $result = [];

            switch ($test_type) {
                case 'validate_account':
                    $account = sanitize_text_field($test_data['account_number'] ?? '');
                    $zip = sanitize_text_field($test_data['zip_code'] ?? '');

                    $validation = $api->validate_account($account, $zip);
                    $result = [
                        'success' => $validation->is_valid(),
                        'data' => $validation->to_array(),
                        'raw_response' => 'ValidationResult object processed successfully'
                    ];
                    break;

                case 'get_schedule':
                    $account = sanitize_text_field($test_data['account_number'] ?? 'TEST');
                    $start_date = date('m/d/Y', strtotime('+3 days'));

                    $schedule = $api->get_schedule_slots($account, $start_date, []);
                    $result = [
                        'success' => $schedule->has_slots(),
                        'data' => [
                            'fsr_no' => $schedule->get_fsr_no(),
                            'slot_count' => count($schedule->get_slots()),
                            'slots_preview' => array_slice($schedule->get_slots_for_display(1), 0, 3)
                        ],
                        'raw_response' => 'SchedulingResult object processed successfully'
                    ];
                    break;

                case 'get_promo_codes':
                    $codes = $api->get_promo_codes();
                    $result = [
                        'success' => !empty($codes),
                        'data' => $codes,
                        'raw_response' => 'Promo codes fetched'
                    ];
                    break;

                case 'connection':
                    $connected = $api->test_connection();
                    $result = [
                        'success' => $connected,
                        'data' => ['connected' => $connected],
                        'raw_response' => $connected ? 'Connection successful' : 'Connection failed'
                    ];
                    break;

                default:
                    wp_send_json_error(['message' => 'Unknown test type.']);
                    return;
            }

            wp_send_json_success([
                'test_type' => $test_type,
                'demo_mode' => $demo_mode,
                'result' => $result
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'trace' => WP_DEBUG ? $e->getTraceAsString() : null
            ]);
        }
    }

    /**
     * Get logs via AJAX
     */
    public function ajax_get_logs(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $filters = [
            'instance_id' => isset($_POST['instance_id']) ? (int)$_POST['instance_id'] : 0,
            'type' => sanitize_text_field($_POST['type'] ?? ''),
            'search' => sanitize_text_field($_POST['search'] ?? '')
        ];

        $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        $logs = $this->db->get_logs($filters, $per_page, $offset);

        wp_send_json_success([
            'logs' => $logs,
            'page' => $page,
            'per_page' => $per_page
        ]);
    }

    /**
     * Mark submissions as test data via AJAX
     */
    public function ajax_mark_test_data(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $ids = $_POST['submission_ids'] ?? [];
        $is_test = (bool)($_POST['is_test'] ?? true);

        if (empty($ids)) {
            wp_send_json_error(['message' => __('No submissions selected.', 'formflow')]);
            return;
        }

        $ids = array_map('intval', (array)$ids);
        $updated = $this->db->mark_submissions_as_test($ids, $is_test);

        wp_send_json_success([
            'message' => sprintf(
                _n(
                    '%d submission marked as %s.',
                    '%d submissions marked as %s.',
                    $updated,
                    'formflow'
                ),
                $updated,
                $is_test ? __('test data', 'formflow') : __('production data', 'formflow')
            ),
            'updated' => $updated
        ]);
    }

    /**
     * Delete test data via AJAX
     */
    public function ajax_delete_test_data(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $instance_id = isset($_POST['instance_id']) ? (int)$_POST['instance_id'] : null;
        $instance_id = $instance_id ?: null;

        $result = $this->db->delete_test_data($instance_id);

        if ($result['submissions'] > 0 || $result['analytics'] > 0) {
            wp_send_json_success([
                'message' => sprintf(
                    __('Deleted %d test submissions and %d analytics records.', 'formflow'),
                    $result['submissions'],
                    $result['analytics']
                ),
                'deleted' => $result
            ]);
        } else {
            wp_send_json_success([
                'message' => __('No test data found to delete.', 'formflow'),
                'deleted' => $result
            ]);
        }
    }

    /**
     * Get test data counts via AJAX
     */
    public function ajax_get_test_counts(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $instance_id = isset($_POST['instance_id']) ? (int)$_POST['instance_id'] : null;
        $instance_id = $instance_id ?: null;

        $counts = $this->db->get_test_data_counts($instance_id);

        wp_send_json_success($counts);
    }

    /**
     * Clear all analytics data via AJAX
     */
    public function ajax_clear_analytics(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $instance_id = isset($_POST['instance_id']) ? (int)$_POST['instance_id'] : null;
        $instance_id = $instance_id ?: null;

        $result = $this->db->clear_analytics($instance_id);

        wp_send_json_success([
            'message' => sprintf(
                __('Cleared %d analytics records.', 'formflow'),
                $result
            ),
            'deleted' => $result
        ]);
    }

    /**
     * Check API health for all instances or a specific one via AJAX
     */
    public function ajax_check_api_health(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $instance_id = isset($_POST['instance_id']) ? (int)$_POST['instance_id'] : null;

        $results = [];

        if ($instance_id) {
            // Check single instance
            $instance = $this->db->get_instance($instance_id);
            if ($instance) {
                $results[$instance_id] = $this->check_instance_health($instance);
            }
        } else {
            // Check all active instances
            $instances = $this->db->get_instances(true); // active only
            foreach ($instances as $instance) {
                // Skip demo mode instances - they don't have real API connections
                if ($instance['settings']['demo_mode'] ?? false) {
                    $results[$instance['id']] = [
                        'status' => 'demo',
                        'message' => __('Demo mode - no API connection', 'formflow'),
                        'checked_at' => current_time('mysql'),
                    ];
                    continue;
                }

                $results[$instance['id']] = $this->check_instance_health($instance);
            }
        }

        // Store results in transient for caching
        set_transient('isf_api_health', $results, 5 * MINUTE_IN_SECONDS);

        wp_send_json_success([
            'results' => $results,
            'checked_at' => current_time('mysql'),
        ]);
    }

    /**
     * Check health for a single instance
     *
     * @param array $instance The instance data
     * @return array Health check result
     */
    private function check_instance_health(array $instance): array {
        // Check if demo mode
        if ($instance['settings']['demo_mode'] ?? false) {
            return [
                'status' => 'demo',
                'message' => __('Demo mode', 'formflow'),
                'checked_at' => current_time('mysql'),
            ];
        }

        // Check if API credentials are configured
        if (empty($instance['api_endpoint']) || empty($instance['api_password'])) {
            return [
                'status' => 'unconfigured',
                'message' => __('API not configured', 'formflow'),
                'checked_at' => current_time('mysql'),
            ];
        }

        try {
            $client = new ApiClient(
                $instance['api_endpoint'],
                $instance['api_password'],
                $instance['test_mode'],
                $instance['id']
            );

            $health = $client->health_check();
            $health['instance_name'] = $instance['name'];

            return $health;

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'checked_at' => current_time('mysql'),
                'instance_name' => $instance['name'],
            ];
        }
    }

    /**
     * Get cached API health status
     *
     * @return array|false Cached health results or false if not cached
     */
    public function get_cached_api_health(): array|false {
        return get_transient('isf_api_health');
    }

    // =========================================================================
    // Webhook AJAX Handlers
    // =========================================================================

    /**
     * Get a single webhook via AJAX
     */
    public function ajax_get_webhook(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $webhook_id = (int)($_POST['webhook_id'] ?? 0);
        if (!$webhook_id) {
            wp_send_json_error(['message' => __('Invalid webhook ID.', 'formflow')]);
            return;
        }

        $webhooks = $this->db->get_webhooks();
        $webhook = null;
        foreach ($webhooks as $wh) {
            if ($wh['id'] == $webhook_id) {
                $webhook = $wh;
                break;
            }
        }

        if (!$webhook) {
            wp_send_json_error(['message' => __('Webhook not found.', 'formflow')]);
            return;
        }

        wp_send_json_success(['webhook' => $webhook]);
    }

    /**
     * Save (create or update) a webhook via AJAX
     */
    public function ajax_save_webhook(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $webhook_id = (int)($_POST['webhook_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        $url = esc_url_raw($_POST['url'] ?? '');
        $instance_id = (int)($_POST['instance_id'] ?? 0) ?: null;
        $events = $_POST['events'] ?? [];
        $secret = sanitize_text_field($_POST['secret'] ?? '');
        $is_active = (bool)($_POST['is_active'] ?? false);

        // Validate required fields
        if (empty($name) || empty($url)) {
            wp_send_json_error(['message' => __('Name and URL are required.', 'formflow')]);
            return;
        }

        if (empty($events)) {
            wp_send_json_error(['message' => __('Please select at least one event.', 'formflow')]);
            return;
        }

        // Sanitize events
        $valid_events = array_keys(\ISF\WebhookHandler::get_available_events());
        $events = array_intersect((array)$events, $valid_events);

        if (empty($events)) {
            wp_send_json_error(['message' => __('Please select valid events.', 'formflow')]);
            return;
        }

        $data = [
            'name' => $name,
            'url' => $url,
            'instance_id' => $instance_id,
            'events' => $events,
            'secret' => $secret,
            'is_active' => $is_active,
        ];

        if ($webhook_id) {
            // Update existing webhook
            $success = $this->db->update_webhook($webhook_id, $data);
            $message = __('Webhook updated successfully.', 'formflow');
            $audit_action = 'webhook_update';
        } else {
            // Create new webhook
            $success = $this->db->create_webhook($data);
            $message = __('Webhook created successfully.', 'formflow');
            $audit_action = 'webhook_create';
        }

        if ($success) {
            // Log the action
            $this->db->log_audit(
                $audit_action,
                'webhook',
                $webhook_id ?: $success,
                $name,
                ['url' => $url, 'events' => $events, 'is_active' => $is_active]
            );

            wp_send_json_success(['message' => $message]);
        } else {
            wp_send_json_error(['message' => __('Failed to save webhook.', 'formflow')]);
        }
    }

    /**
     * Delete a webhook via AJAX
     */
    public function ajax_delete_webhook(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $webhook_id = (int)($_POST['webhook_id'] ?? 0);
        if (!$webhook_id) {
            wp_send_json_error(['message' => __('Invalid webhook ID.', 'formflow')]);
            return;
        }

        // Get webhook info before deletion for audit log
        $webhooks = $this->db->get_webhooks();
        $webhook_name = 'Unknown';
        foreach ($webhooks as $wh) {
            if ($wh['id'] == $webhook_id) {
                $webhook_name = $wh['name'];
                break;
            }
        }

        if ($this->db->delete_webhook($webhook_id)) {
            // Log the deletion
            $this->db->log_audit(
                'webhook_delete',
                'webhook',
                $webhook_id,
                $webhook_name,
                []
            );

            wp_send_json_success(['message' => __('Webhook deleted successfully.', 'formflow')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete webhook.', 'formflow')]);
        }
    }

    /**
     * Test a webhook via AJAX
     */
    public function ajax_test_webhook(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $webhook_id = (int)($_POST['webhook_id'] ?? 0);
        if (!$webhook_id) {
            wp_send_json_error(['message' => __('Invalid webhook ID.', 'formflow')]);
            return;
        }

        // Get the webhook
        $webhooks = $this->db->get_webhooks();
        $webhook = null;
        foreach ($webhooks as $wh) {
            if ($wh['id'] == $webhook_id) {
                $webhook = $wh;
                break;
            }
        }

        if (!$webhook) {
            wp_send_json_error(['message' => __('Webhook not found.', 'formflow')]);
            return;
        }

        // Send test payload
        require_once ISF_PLUGIN_DIR . 'includes/class-webhook-handler.php';

        $test_data = [
            'submission_id' => 0,
            'instance_id' => $webhook['instance_id'] ?? 0,
            'form_data' => [
                'account_number' => '1234567890',
                'customer_name' => 'Test User',
                'device_type' => 'thermostat',
            ],
            'test' => true,
        ];

        $payload = [
            'event' => 'test',
            'timestamp' => current_time('c'),
            'data' => $test_data,
        ];

        $json_payload = json_encode($payload);

        $headers = [
            'Content-Type' => 'application/json',
            'X-ISF-Event' => 'test',
            'X-ISF-Timestamp' => time(),
        ];

        if (!empty($webhook['secret'])) {
            $signature = hash_hmac('sha256', $json_payload, $webhook['secret']);
            $headers['X-ISF-Signature'] = $signature;
        }

        $response = wp_remote_post($webhook['url'], [
            'headers' => $headers,
            'body' => $json_payload,
            'timeout' => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => $response->get_error_message(),
            ]);
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        wp_send_json_success([
            'status_code' => $status_code,
            'success' => $status_code >= 200 && $status_code < 300,
        ]);
    }

    // =========================================================================
    // Submission Details & Export AJAX Handlers
    // =========================================================================

    /**
     * Get submission details via AJAX
     */
    public function ajax_get_submission_details(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $submission_id = (int)($_POST['submission_id'] ?? 0);
        if (!$submission_id) {
            wp_send_json_error(['message' => __('Invalid submission ID.', 'formflow')]);
            return;
        }

        $submission = $this->db->get_submission($submission_id);
        if (!$submission) {
            wp_send_json_error(['message' => __('Submission not found.', 'formflow')]);
            return;
        }

        // Get instance name
        $instance = $this->db->get_instance($submission['instance_id']);
        $submission['instance_name'] = $instance ? $instance['name'] : 'Unknown';

        // Decode form data
        $form_data = $submission['form_data'] ?? [];

        wp_send_json_success([
            'submission' => $submission,
            'form_data' => $form_data,
        ]);
    }

    /**
     * Export submissions to CSV via AJAX
     */
    public function ajax_export_submissions_csv(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $filters = [
            'instance_id' => (int)($_POST['instance_id'] ?? 0) ?: null,
            'status' => sanitize_text_field($_POST['status'] ?? ''),
            'search' => sanitize_text_field($_POST['search'] ?? ''),
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
        ];

        // Get all submissions (no limit for export)
        $submissions = $this->db->get_submissions_for_export($filters);

        if (empty($submissions)) {
            wp_send_json_error(['message' => __('No submissions to export.', 'formflow')]);
            return;
        }

        // Build CSV content
        $csv_lines = [];

        // Header row
        $csv_lines[] = [
            'ID',
            'Form Instance',
            'Account Number',
            'Customer Name',
            'Email',
            'Phone',
            'Street',
            'City',
            'State',
            'ZIP',
            'Device Type',
            'Promo Code',
            'Confirmation Number',
            'Schedule Date',
            'Schedule Time',
            'Status',
            'Step',
            'IP Address',
            'Created',
            'Completed',
        ];

        foreach ($submissions as $sub) {
            $fd = $sub['form_data'] ?? [];

            $csv_lines[] = [
                $sub['id'],
                $sub['instance_name'] ?? '',
                $sub['account_number'] ?? '',
                trim(($fd['first_name'] ?? '') . ' ' . ($fd['last_name'] ?? '')),
                $fd['email'] ?? '',
                $fd['phone'] ?? '',
                $fd['street'] ?? '',
                $fd['city'] ?? '',
                $fd['state'] ?? '',
                $fd['zip'] ?? '',
                $sub['device_type'] ?? '',
                $fd['promo_code'] ?? '',
                $fd['confirmation_number'] ?? '',
                $fd['schedule_date'] ?? '',
                $fd['schedule_time_display'] ?? ($fd['schedule_time'] ?? ''),
                $sub['status'],
                $sub['step'],
                $sub['ip_address'] ?? '',
                $sub['created_at'],
                $sub['completed_at'] ?? '',
            ];
        }

        // Convert to CSV string
        $csv_output = '';
        foreach ($csv_lines as $line) {
            $escaped = array_map(function($field) {
                // Escape double quotes and wrap in quotes if needed
                $field = str_replace('"', '""', $field ?? '');
                if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                    return '"' . $field . '"';
                }
                return $field;
            }, $line);
            $csv_output .= implode(',', $escaped) . "\n";
        }

        $filename = 'isf-submissions-' . date('Y-m-d-His') . '.csv';

        wp_send_json_success([
            'csv' => $csv_output,
            'filename' => $filename,
            'count' => count($submissions),
        ]);
    }

    // =========================================================================
    // Instance Duplication AJAX Handler
    // =========================================================================

    /**
     * Duplicate a form instance via AJAX
     */
    public function ajax_duplicate_instance(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

        if (!$id) {
            wp_send_json_error(['message' => __('Invalid form ID.', 'formflow')]);
            return;
        }

        $instance = $this->db->get_instance($id);
        if (!$instance) {
            wp_send_json_error(['message' => __('Form not found.', 'formflow')]);
            return;
        }

        // Generate new slug
        $base_slug = $instance['slug'] . '-copy';
        $new_slug = $base_slug;
        $counter = 1;

        // Find unique slug
        while ($this->db->get_instance_by_slug($new_slug)) {
            $counter++;
            $new_slug = $base_slug . '-' . $counter;
        }

        // Prepare new instance data
        $new_data = [
            'name' => $instance['name'] . ' (Copy)',
            'slug' => $new_slug,
            'utility' => $instance['utility'],
            'form_type' => $instance['form_type'],
            'api_endpoint' => $instance['api_endpoint'],
            'api_password' => $instance['api_password'],
            'support_email_from' => $instance['support_email_from'],
            'support_email_to' => $instance['support_email_to'],
            'is_active' => 0, // Start inactive
            'test_mode' => $instance['test_mode'],
            'settings' => $instance['settings'],
        ];

        $new_id = $this->db->create_instance($new_data);

        if ($new_id) {
            wp_send_json_success([
                'message' => __('Form duplicated successfully.', 'formflow'),
                'new_id' => $new_id,
                'new_slug' => $new_slug,
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to duplicate form.', 'formflow')]);
        }
    }

    /**
     * Save instance display order via AJAX
     */
    public function ajax_save_instance_order(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $order = $_POST['order'] ?? [];

        if (empty($order) || !is_array($order)) {
            wp_send_json_error(['message' => __('Invalid order data.', 'formflow')]);
            return;
        }

        $order = array_map('intval', $order);

        // Update display_order for each instance
        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';
        $success = true;

        foreach ($order as $position => $instance_id) {
            $result = $wpdb->update(
                $table,
                ['display_order' => $position],
                ['id' => $instance_id],
                ['%d'],
                ['%d']
            );

            if ($result === false) {
                $success = false;
            }
        }

        if ($success) {
            wp_send_json_success(['message' => __('Order saved.', 'formflow')]);
        } else {
            wp_send_json_error(['message' => __('Failed to save order.', 'formflow')]);
        }
    }

    // =========================================================================
    // Bulk Actions AJAX Handlers
    // =========================================================================

    /**
     * Process bulk actions on submissions via AJAX
     */
    public function ajax_bulk_submissions_action(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $ids = $_POST['submission_ids'] ?? [];

        if (empty($ids)) {
            wp_send_json_error(['message' => __('No submissions selected.', 'formflow')]);
            return;
        }

        $ids = array_map('intval', (array)$ids);

        switch ($action) {
            case 'mark_test':
                $updated = $this->db->mark_submissions_as_test($ids, true);
                wp_send_json_success([
                    'message' => sprintf(__('%d submission(s) marked as test data.', 'formflow'), $updated),
                    'updated' => $updated,
                ]);
                break;

            case 'mark_production':
                $updated = $this->db->mark_submissions_as_test($ids, false);
                wp_send_json_success([
                    'message' => sprintf(__('%d submission(s) marked as production data.', 'formflow'), $updated),
                    'updated' => $updated,
                ]);
                break;

            case 'delete':
                $deleted = $this->db->delete_submissions($ids);

                // Log the bulk deletion
                $this->db->log_audit(
                    'submission_bulk_delete',
                    'submission',
                    null,
                    sprintf('%d submissions', $deleted),
                    ['submission_ids' => $ids, 'deleted_count' => $deleted]
                );

                wp_send_json_success([
                    'message' => sprintf(__('%d submission(s) deleted.', 'formflow'), $deleted),
                    'deleted' => $deleted,
                ]);
                break;

            default:
                wp_send_json_error(['message' => __('Invalid action.', 'formflow')]);
        }
    }

    /**
     * Process bulk actions on logs via AJAX
     */
    public function ajax_bulk_logs_action(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $ids = $_POST['log_ids'] ?? [];

        if (empty($ids)) {
            wp_send_json_error(['message' => __('No logs selected.', 'formflow')]);
            return;
        }

        $ids = array_map('intval', (array)$ids);

        switch ($action) {
            case 'delete':
                $deleted = $this->db->delete_logs($ids);
                wp_send_json_success([
                    'message' => sprintf(__('%d log(s) deleted.', 'formflow'), $deleted),
                    'deleted' => $deleted,
                ]);
                break;

            default:
                wp_send_json_error(['message' => __('Invalid action.', 'formflow')]);
        }
    }

    // =========================================================================
    // API Usage AJAX Handlers
    // =========================================================================

    /**
     * Get API usage statistics via AJAX
     */
    public function ajax_get_api_usage(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $instance_id = isset($_POST['instance_id']) ? (int)$_POST['instance_id'] : null;
        $period = sanitize_text_field($_POST['period'] ?? 'day');

        $instance_id = $instance_id ?: null;

        $stats = $this->db->get_api_usage_stats($instance_id, $period);

        // Get rate limit status for each active instance
        $rate_limits = [];
        if ($instance_id) {
            $rate_limits[$instance_id] = $this->db->get_rate_limit_status($instance_id);
        } else {
            $instances = $this->db->get_instances(true);
            foreach ($instances as $instance) {
                // Skip demo mode instances
                if ($instance['settings']['demo_mode'] ?? false) {
                    continue;
                }
                $rate_limits[$instance['id']] = $this->db->get_rate_limit_status($instance['id']);
                $rate_limits[$instance['id']]['name'] = $instance['name'];
            }
        }

        wp_send_json_success([
            'stats' => $stats,
            'rate_limits' => $rate_limits,
        ]);
    }

    // =========================================================================
    // Reports Page & AJAX Handlers
    // =========================================================================

    /**
     * Render the reports page
     */
    public function render_reports(): void {
        $instances = $this->db->get_instances();
        $scheduled_reports = $this->db->get_scheduled_reports();

        include ISF_PLUGIN_DIR . 'admin/views/reports.php';
    }

    /**
     * Save (create or update) a scheduled report via AJAX
     */
    public function ajax_save_scheduled_report(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $report_id = (int)($_POST['report_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'weekly');
        $recipients = sanitize_textarea_field($_POST['recipients'] ?? '');
        $instance_id = (int)($_POST['instance_id'] ?? 0) ?: null;
        $is_active = (bool)($_POST['is_active'] ?? false);

        // Parse recipients (comma or newline separated emails)
        $recipients_array = array_filter(array_map('trim', preg_split('/[,\n]+/', $recipients)));
        $recipients_array = array_filter($recipients_array, 'is_email');

        if (empty($name)) {
            wp_send_json_error(['message' => __('Report name is required.', 'formflow')]);
            return;
        }

        if (empty($recipients_array)) {
            wp_send_json_error(['message' => __('At least one valid email recipient is required.', 'formflow')]);
            return;
        }

        if (!in_array($frequency, ['daily', 'weekly', 'monthly'])) {
            wp_send_json_error(['message' => __('Invalid frequency.', 'formflow')]);
            return;
        }

        // Build settings (could include report sections, format preferences, etc.)
        $settings = [
            'include_summary' => true,
            'include_submissions' => true,
            'include_analytics' => true,
        ];

        $data = [
            'name' => $name,
            'frequency' => $frequency,
            'recipients' => $recipients_array,
            'instance_id' => $instance_id,
            'settings' => $settings,
            'is_active' => $is_active,
        ];

        if ($report_id) {
            $success = $this->db->update_scheduled_report($report_id, $data);
            $message = __('Scheduled report updated successfully.', 'formflow');
            $audit_action = 'scheduled_report_update';
        } else {
            $success = $this->db->create_scheduled_report($data);
            $message = __('Scheduled report created successfully.', 'formflow');
            $audit_action = 'scheduled_report_create';
        }

        if ($success) {
            // Log the action
            $this->db->log_audit(
                $audit_action,
                'scheduled_report',
                $report_id ?: $success,
                $name,
                ['frequency' => $frequency, 'recipients_count' => count($recipients_array)]
            );

            wp_send_json_success(['message' => $message]);
        } else {
            wp_send_json_error(['message' => __('Failed to save scheduled report.', 'formflow')]);
        }
    }

    /**
     * Get a single scheduled report via AJAX
     */
    public function ajax_get_scheduled_report(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $report_id = (int)($_POST['report_id'] ?? 0);
        if (!$report_id) {
            wp_send_json_error(['message' => __('Invalid report ID.', 'formflow')]);
            return;
        }

        $report = $this->db->get_scheduled_report($report_id);
        if (!$report) {
            wp_send_json_error(['message' => __('Report not found.', 'formflow')]);
            return;
        }

        // Convert recipients array to string for form
        if (is_array($report['recipients'])) {
            $report['recipients_text'] = implode(', ', $report['recipients']);
        } else {
            $report['recipients_text'] = $report['recipients'];
        }

        wp_send_json_success(['report' => $report]);
    }

    /**
     * Delete a scheduled report via AJAX
     */
    public function ajax_delete_scheduled_report(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $report_id = (int)($_POST['report_id'] ?? 0);
        if (!$report_id) {
            wp_send_json_error(['message' => __('Invalid report ID.', 'formflow')]);
            return;
        }

        // Get report info for audit log
        $report = $this->db->get_scheduled_report($report_id);
        $report_name = $report ? $report['name'] : 'Unknown';

        if ($this->db->delete_scheduled_report($report_id)) {
            // Log the deletion
            $this->db->log_audit(
                'scheduled_report_delete',
                'scheduled_report',
                $report_id,
                $report_name,
                []
            );

            wp_send_json_success(['message' => __('Scheduled report deleted successfully.', 'formflow')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete scheduled report.', 'formflow')]);
        }
    }

    /**
     * Send a scheduled report immediately via AJAX
     */
    public function ajax_send_report_now(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $report_id = (int)($_POST['report_id'] ?? 0);
        if (!$report_id) {
            wp_send_json_error(['message' => __('Invalid report ID.', 'formflow')]);
            return;
        }

        $report = $this->db->get_scheduled_report($report_id);
        if (!$report) {
            wp_send_json_error(['message' => __('Report not found.', 'formflow')]);
            return;
        }

        require_once ISF_PLUGIN_DIR . 'includes/class-report-generator.php';

        try {
            $generator = new \ISF\ReportGenerator($this->db);
            $result = $generator->send_scheduled_report($report);

            if ($result) {
                // Update last sent timestamp
                $this->db->update_report_sent($report_id);

                wp_send_json_success([
                    'message' => sprintf(
                        __('Report sent successfully to %d recipient(s).', 'formflow'),
                        count($report['recipients'])
                    ),
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to send report email.', 'formflow')]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Generate a custom report via AJAX
     */
    public function ajax_generate_custom_report(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $instance_id = (int)($_POST['instance_id'] ?? 0) ?: null;
        $output_format = sanitize_text_field($_POST['output_format'] ?? 'html');
        $sections = $_POST['sections'] ?? ['summary', 'submissions', 'analytics'];

        if (empty($date_from) || empty($date_to)) {
            wp_send_json_error(['message' => __('Date range is required.', 'formflow')]);
            return;
        }

        require_once ISF_PLUGIN_DIR . 'includes/class-report-generator.php';

        try {
            $generator = new \ISF\ReportGenerator($this->db);
            $report_data = $generator->generate_report($date_from, $date_to, $instance_id, $sections);

            if ($output_format === 'csv') {
                // Generate CSV
                $csv = $generator->to_csv($report_data);
                wp_send_json_success([
                    'format' => 'csv',
                    'csv' => $csv,
                    'filename' => 'isf-report-' . date('Y-m-d') . '.csv',
                ]);
            } elseif ($output_format === 'email') {
                // Send via email
                $recipients = sanitize_textarea_field($_POST['email_recipients'] ?? '');
                $recipients_array = array_filter(array_map('trim', preg_split('/[,\n]+/', $recipients)));
                $recipients_array = array_filter($recipients_array, 'is_email');

                if (empty($recipients_array)) {
                    wp_send_json_error(['message' => __('Valid email recipients are required.', 'formflow')]);
                    return;
                }

                $result = $generator->send_report_email($report_data, $recipients_array);
                if ($result) {
                    wp_send_json_success([
                        'format' => 'email',
                        'message' => sprintf(
                            __('Report sent to %d recipient(s).', 'formflow'),
                            count($recipients_array)
                        ),
                    ]);
                } else {
                    wp_send_json_error(['message' => __('Failed to send report email.', 'formflow')]);
                }
            } else {
                // Return HTML
                $html = $generator->to_html($report_data);
                wp_send_json_success([
                    'format' => 'html',
                    'html' => $html,
                ]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Export analytics data to CSV via AJAX
     */
    public function ajax_export_analytics_csv(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $instance_id = (int)($_POST['instance_id'] ?? 0) ?: null;

        if (empty($date_from) || empty($date_to)) {
            wp_send_json_error(['message' => __('Date range is required.', 'formflow')]);
            return;
        }

        $analytics = $this->db->get_analytics_for_export($date_from, $date_to, $instance_id);

        if (empty($analytics)) {
            wp_send_json_error(['message' => __('No analytics data found for the selected range.', 'formflow')]);
            return;
        }

        // Build CSV
        $csv_lines = [];

        // Header
        $csv_lines[] = [
            'Date',
            'Form Instance',
            'Step',
            'Step Name',
            'Action',
            'Session ID',
            'Time on Step (sec)',
            'Device Type',
            'Browser',
            'Is Mobile',
            'Is Test',
        ];

        foreach ($analytics as $row) {
            $csv_lines[] = [
                $row['created_at'],
                $row['instance_name'] ?? '',
                $row['step'],
                $row['step_name'] ?? '',
                $row['action'],
                $row['session_id'],
                $row['time_on_step'],
                $row['device_type'] ?? '',
                $row['browser'] ?? '',
                $row['is_mobile'] ? 'Yes' : 'No',
                $row['is_test'] ? 'Yes' : 'No',
            ];
        }

        // Convert to CSV string
        $csv_output = '';
        foreach ($csv_lines as $line) {
            $escaped = array_map(function($field) {
                $field = str_replace('"', '""', $field ?? '');
                if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                    return '"' . $field . '"';
                }
                return $field;
            }, $line);
            $csv_output .= implode(',', $escaped) . "\n";
        }

        wp_send_json_success([
            'csv' => $csv_output,
            'filename' => 'isf-analytics-' . date('Y-m-d-His') . '.csv',
            'count' => count($analytics),
        ]);
    }

    // =========================================================================
    // Compliance Page & AJAX Handlers (GDPR, Audit Log, Data Retention)
    // =========================================================================

    /**
     * Render the compliance page
     */
    public function render_compliance(): void {
        // Handle retention settings form submission
        if (isset($_POST['isf_save_retention']) && check_admin_referer('isf_retention_nonce')) {
            $this->save_retention_settings();
        }

        $instances = $this->db->get_instances();
        $settings = get_option('isf_settings', []);

        include ISF_PLUGIN_DIR . 'admin/views/compliance.php';
    }

    /**
     * Save retention policy settings from form POST
     */
    private function save_retention_settings(): void {
        $current_settings = get_option('isf_settings', []);

        $retention_settings = [
            'retention_enabled' => isset($_POST['retention_enabled']),
            'anonymize_instead_of_delete' => isset($_POST['anonymize_instead_of_delete']),
            'retention_submissions_days' => (int)($_POST['retention_submissions_days'] ?? 365),
            'retention_analytics_days' => (int)($_POST['retention_analytics_days'] ?? 180),
            'retention_audit_log_days' => (int)($_POST['retention_audit_log_days'] ?? 365),
            'retention_api_usage_days' => (int)($_POST['retention_api_usage_days'] ?? 90),
            'log_retention_days' => (int)($_POST['log_retention_days'] ?? 90),
        ];

        $new_settings = array_merge($current_settings, $retention_settings);
        update_option('isf_settings', $new_settings);

        // Log the settings change
        $this->db->log_audit(
            'retention_settings_update',
            'settings',
            null,
            'Data Retention Policy',
            [
                'retention_enabled' => $retention_settings['retention_enabled'],
                'anonymize_instead_of_delete' => $retention_settings['anonymize_instead_of_delete'],
            ]
        );

        add_settings_error(
            'isf_retention_settings',
            'settings_updated',
            __('Retention policy saved successfully.', 'formflow'),
            'success'
        );
    }

    /**
     * Search for user data (GDPR) via AJAX
     */
    public function ajax_gdpr_search(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $email = sanitize_email($_POST['email'] ?? '');
        $account_number = sanitize_text_field($_POST['account_number'] ?? '');

        if (empty($email) && empty($account_number)) {
            wp_send_json_error(['message' => __('Please provide an email address or account number.', 'formflow')]);
            return;
        }

        $submissions = $this->db->find_submissions_for_gdpr($email, $account_number);

        // Log this search for audit purposes
        $this->db->log_audit(
            'gdpr_search',
            'submission',
            null,
            $email ?: $account_number,
            [
                'email' => $email,
                'account_number' => $account_number,
                'results_count' => count($submissions),
            ]
        );

        wp_send_json_success([
            'submissions' => $submissions,
            'count' => count($submissions),
        ]);
    }

    /**
     * Export user data (GDPR) via AJAX
     */
    public function ajax_gdpr_export(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $submission_ids = $_POST['submission_ids'] ?? [];
        $email = sanitize_email($_POST['email'] ?? '');

        if (empty($submission_ids)) {
            wp_send_json_error(['message' => __('No submissions selected for export.', 'formflow')]);
            return;
        }

        $submission_ids = array_map('intval', (array)$submission_ids);
        $export_data = [];

        foreach ($submission_ids as $id) {
            $submission = $this->db->get_submission($id);
            if ($submission) {
                // Get instance info
                $instance = $this->db->get_instance($submission['instance_id']);

                $export_data[] = [
                    'submission_id' => $id,
                    'form_instance' => $instance ? $instance['name'] : 'Unknown',
                    'account_number' => $submission['account_number'],
                    'customer_name' => $submission['customer_name'],
                    'device_type' => $submission['device_type'],
                    'status' => $submission['status'],
                    'created_at' => $submission['created_at'],
                    'completed_at' => $submission['completed_at'],
                    'form_data' => $submission['form_data'],
                ];
            }
        }

        // Create GDPR request record
        $request_id = $this->db->create_gdpr_request([
            'request_type' => 'export',
            'email' => $email,
            'status' => 'completed',
            'request_data' => ['submission_ids' => $submission_ids],
            'result_data' => ['exported_count' => count($export_data)],
        ]);

        // Log the export
        $this->db->log_audit(
            'gdpr_export',
            'gdpr_request',
            $request_id,
            $email,
            [
                'submission_ids' => $submission_ids,
                'exported_count' => count($export_data),
            ]
        );

        wp_send_json_success([
            'export_data' => $export_data,
            'request_id' => $request_id,
            'filename' => 'gdpr-export-' . date('Y-m-d-His') . '.json',
        ]);
    }

    /**
     * Anonymize user data (GDPR) via AJAX
     */
    public function ajax_gdpr_anonymize(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $submission_ids = $_POST['submission_ids'] ?? [];
        $email = sanitize_email($_POST['email'] ?? '');
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (empty($submission_ids)) {
            wp_send_json_error(['message' => __('No submissions selected for anonymization.', 'formflow')]);
            return;
        }

        $submission_ids = array_map('intval', (array)$submission_ids);
        $anonymized = 0;
        $failed = 0;

        foreach ($submission_ids as $id) {
            if ($this->db->anonymize_submission($id)) {
                $anonymized++;
            } else {
                $failed++;
            }
        }

        // Create GDPR request record
        $request_id = $this->db->create_gdpr_request([
            'request_type' => 'erasure',
            'email' => $email,
            'status' => 'completed',
            'request_data' => [
                'submission_ids' => $submission_ids,
                'action' => 'anonymize',
                'reason' => $reason,
            ],
            'result_data' => [
                'anonymized' => $anonymized,
                'failed' => $failed,
            ],
            'notes' => $reason,
        ]);

        // Log the anonymization
        $this->db->log_audit(
            'gdpr_anonymize',
            'gdpr_request',
            $request_id,
            $email,
            [
                'submission_ids' => $submission_ids,
                'anonymized' => $anonymized,
                'failed' => $failed,
                'reason' => $reason,
            ]
        );

        wp_send_json_success([
            'message' => sprintf(
                __('Successfully anonymized %d submission(s). %d failed.', 'formflow'),
                $anonymized,
                $failed
            ),
            'anonymized' => $anonymized,
            'failed' => $failed,
            'request_id' => $request_id,
        ]);
    }

    /**
     * Permanently delete user data (GDPR) via AJAX
     */
    public function ajax_gdpr_delete(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $submission_ids = $_POST['submission_ids'] ?? [];
        $email = sanitize_email($_POST['email'] ?? '');
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (empty($submission_ids)) {
            wp_send_json_error(['message' => __('No submissions selected for deletion.', 'formflow')]);
            return;
        }

        $submission_ids = array_map('intval', (array)$submission_ids);
        $deleted = 0;
        $failed = 0;

        foreach ($submission_ids as $id) {
            if ($this->db->permanently_delete_submission($id)) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        // Create GDPR request record
        $request_id = $this->db->create_gdpr_request([
            'request_type' => 'erasure',
            'email' => $email,
            'status' => 'completed',
            'request_data' => [
                'submission_ids' => $submission_ids,
                'action' => 'delete',
                'reason' => $reason,
            ],
            'result_data' => [
                'deleted' => $deleted,
                'failed' => $failed,
            ],
            'notes' => $reason,
        ]);

        // Log the deletion
        $this->db->log_audit(
            'gdpr_delete',
            'gdpr_request',
            $request_id,
            $email,
            [
                'submission_ids' => $submission_ids,
                'deleted' => $deleted,
                'failed' => $failed,
                'reason' => $reason,
            ]
        );

        wp_send_json_success([
            'message' => sprintf(
                __('Permanently deleted %d submission(s). %d failed.', 'formflow'),
                $deleted,
                $failed
            ),
            'deleted' => $deleted,
            'failed' => $failed,
            'request_id' => $request_id,
        ]);
    }

    /**
     * Get audit log entries via AJAX
     */
    public function ajax_get_audit_log(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $filters = [
            'action' => sanitize_text_field($_POST['action'] ?? ''),
            'user_id' => (int)($_POST['user_id'] ?? 0) ?: null,
            'object_type' => sanitize_text_field($_POST['object_type'] ?? ''),
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
        ];

        $page = max(1, (int)($_POST['page'] ?? 1));
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        $logs = $this->db->get_audit_log($filters, $per_page, $offset);
        $total = $this->db->get_audit_log_count($filters);

        wp_send_json_success([
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $per_page),
        ]);
    }

    /**
     * Get GDPR request history via AJAX
     */
    public function ajax_get_gdpr_requests(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $page = max(1, (int)($_POST['page'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        $requests = $this->db->get_gdpr_requests([], $per_page, $offset);
        $total = $this->db->get_gdpr_requests_count();

        wp_send_json_success([
            'requests' => $requests,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $per_page),
        ]);
    }

    /**
     * Preview data retention policy results via AJAX
     */
    public function ajax_preview_retention(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $settings = [
            'retention_submissions_days' => (int)($_POST['retention_submissions_days'] ?? 365),
            'retention_analytics_days' => (int)($_POST['retention_analytics_days'] ?? 180),
            'retention_audit_log_days' => (int)($_POST['retention_audit_log_days'] ?? 365),
            'retention_api_usage_days' => (int)($_POST['retention_api_usage_days'] ?? 90),
            'anonymize_instead_of_delete' => (bool)($_POST['anonymize_instead_of_delete'] ?? true),
        ];

        $stats = $this->db->get_retention_stats($settings);

        wp_send_json_success([
            'stats' => $stats,
            'settings' => $settings,
        ]);
    }

    /**
     * Apply data retention policy via AJAX
     */
    public function ajax_run_retention(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $settings = [
            'retention_submissions_days' => (int)($_POST['retention_submissions_days'] ?? 365),
            'retention_analytics_days' => (int)($_POST['retention_analytics_days'] ?? 180),
            'retention_audit_log_days' => (int)($_POST['retention_audit_log_days'] ?? 365),
            'retention_api_usage_days' => (int)($_POST['retention_api_usage_days'] ?? 90),
            'anonymize_instead_of_delete' => (bool)($_POST['anonymize_instead_of_delete'] ?? true),
        ];

        $result = $this->db->apply_retention_policy($settings);

        // Log the retention policy execution
        $this->db->log_audit(
            'retention_policy_run',
            'system',
            null,
            'Manual execution',
            [
                'settings' => $settings,
                'result' => $result,
            ]
        );

        wp_send_json_success([
            'results' => $result,
            'message' => __('Data retention policy applied successfully.', 'formflow'),
        ]);
    }

    /**
     * Save retention settings via AJAX
     */
    public function ajax_save_retention_settings(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $current_settings = get_option('isf_settings', []);

        $retention_settings = [
            'retention_submissions_days' => (int)($_POST['retention_submissions_days'] ?? 365),
            'retention_analytics_days' => (int)($_POST['retention_analytics_days'] ?? 180),
            'retention_audit_log_days' => (int)($_POST['retention_audit_log_days'] ?? 365),
            'retention_api_usage_days' => (int)($_POST['retention_api_usage_days'] ?? 90),
            'retention_enabled' => (bool)($_POST['retention_enabled'] ?? false),
            'anonymize_instead_of_delete' => (bool)($_POST['anonymize_instead_of_delete'] ?? true),
        ];

        $new_settings = array_merge($current_settings, $retention_settings);
        update_option('isf_settings', $new_settings);

        // Log the settings change
        $this->db->log_audit(
            'retention_settings_update',
            'settings',
            null,
            'Data Retention Policy',
            [
                'old_settings' => array_intersect_key($current_settings, $retention_settings),
                'new_settings' => $retention_settings,
            ]
        );

        wp_send_json_success([
            'message' => __('Retention settings saved successfully.', 'formflow'),
        ]);
    }

    // =========================================================================
    // Diagnostics AJAX Handlers
    // =========================================================================

    /**
     * Run full diagnostics via AJAX
     */
    public function ajax_run_diagnostics(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $instance_id = isset($_POST['instance_id']) ? (int)$_POST['instance_id'] : null;
        $instance_id = $instance_id ?: null;

        require_once ISF_PLUGIN_DIR . 'includes/class-diagnostics.php';

        try {
            $diagnostics = new \ISF\Diagnostics();
            $results = $diagnostics->run_all_tests($instance_id);

            // Log the diagnostics run
            $this->db->log_audit(
                'diagnostics_run',
                'system',
                null,
                'Full diagnostics',
                [
                    'instance_id' => $instance_id,
                    'passed' => $results['summary']['passed'],
                    'failed' => $results['summary']['failed'],
                    'warnings' => $results['summary']['warnings'],
                ]
            );

            wp_send_json_success($results);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run quick health check via AJAX
     */
    public function ajax_quick_health_check(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        require_once ISF_PLUGIN_DIR . 'includes/class-diagnostics.php';

        try {
            $diagnostics = new \ISF\Diagnostics();
            $status = $diagnostics->quick_health_check();

            wp_send_json_success($status);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Test SMS configuration via AJAX
     */
    public function ajax_test_sms(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $test_number = sanitize_text_field($_POST['test_number'] ?? '');
        $config = [
            'account_sid' => sanitize_text_field($_POST['account_sid'] ?? ''),
            'auth_token' => sanitize_text_field($_POST['auth_token'] ?? ''),
            'from_number' => sanitize_text_field($_POST['from_number'] ?? ''),
        ];

        require_once ISF_PLUGIN_DIR . 'includes/class-sms-handler.php';

        $result = \ISF\SmsHandler::test_configuration($config, $test_number);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Test team webhook configuration via AJAX
     */
    public function ajax_test_team_webhook(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $webhook_url = esc_url_raw($_POST['webhook_url'] ?? '');
        $provider = sanitize_text_field($_POST['provider'] ?? 'slack');

        require_once ISF_PLUGIN_DIR . 'includes/class-team-notifications.php';

        $result = \ISF\TeamNotifications::test_webhook($webhook_url, $provider);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Send test digest email via AJAX
     */
    public function ajax_test_digest(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $instance_id = (int)($_POST['instance_id'] ?? 0);
        $recipient = sanitize_email($_POST['recipient'] ?? '');

        require_once ISF_PLUGIN_DIR . 'includes/class-email-digest.php';

        $result = \ISF\EmailDigest::send_test($instance_id, $recipient);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    // =========================================================================
    // Attribution Page - Marketing Analytics & Conversion Tracking
    // =========================================================================

    /**
     * Render the attribution analytics page
     */
    public function render_attribution(): void {
        $instances = $this->db->get_instances();

        // Get filter parameters
        $instance_id = isset($_GET['instance_id']) ? (int)$_GET['instance_id'] : 0;
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');
        $attribution_model = isset($_GET['model']) ? sanitize_text_field($_GET['model']) : 'first_touch';

        // Validate attribution model
        $valid_models = ['first_touch', 'last_touch', 'linear', 'time_decay', 'position_based'];
        if (!in_array($attribution_model, $valid_models)) {
            $attribution_model = 'first_touch';
        }

        include ISF_PLUGIN_DIR . 'admin/views/attribution-report.php';
    }

    /**
     * Render the import completions page
     */
    public function render_import_completions(): void {
        $instances = $this->db->get_instances();

        // Get import history
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-completion-importer.php';
        $importer = new \ISF\Analytics\CompletionImporter();
        $import_history = $importer->get_import_history(10);

        include ISF_PLUGIN_DIR . 'admin/views/import-completions.php';
    }

    /**
     * Render the analytics settings page
     */
    public function render_analytics_settings(): void {
        $instances = $this->db->get_instances();
        $settings = get_option('isf_settings', []);

        include ISF_PLUGIN_DIR . 'admin/views/analytics-settings.php';
    }

    // =========================================================================
    // Visual Form Builder Page
    // =========================================================================

    /**
     * Render the visual form builder page
     */
    public function render_form_builder(): void {
        include ISF_PLUGIN_DIR . 'admin/views/form-builder.php';
    }

    /**
     * AJAX handler: Save form builder schema
     */
    public function ajax_builder_save(): void {
        check_ajax_referer('isf_builder_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'formflow')]);
        }

        $instance_id = isset($_POST['instance_id']) ? absint($_POST['instance_id']) : 0;
        $schema_json = isset($_POST['schema']) ? wp_unslash($_POST['schema']) : '';

        if (!$instance_id) {
            wp_send_json_error(['message' => __('Invalid instance ID.', 'formflow')]);
        }

        $schema = json_decode($schema_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid schema format.', 'formflow')]);
        }

        // Validate schema using FormBuilder class
        $builder = new \ISF\Builder\FormBuilder();
        $validation = $builder->validate_schema($schema);

        if (!$validation['valid']) {
            wp_send_json_error([
                'message' => __('Schema validation failed.', 'formflow'),
                'errors' => $validation['errors']
            ]);
        }

        // Get current instance settings
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_INSTANCES;
        $instance = $wpdb->get_row($wpdb->prepare("SELECT settings FROM {$table} WHERE id = %d", $instance_id));

        if (!$instance) {
            wp_send_json_error(['message' => __('Instance not found.', 'formflow')]);
        }

        // Update settings with new schema
        $settings = json_decode($instance->settings, true) ?: [];
        $settings['form_schema'] = $schema;

        $updated = $wpdb->update(
            $table,
            ['settings' => wp_json_encode($settings)],
            ['id' => $instance_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => __('Failed to save schema.', 'formflow')]);
        }

        wp_send_json_success(['message' => __('Form saved successfully.', 'formflow')]);
    }

    /**
     * AJAX handler: Preview form builder schema
     */
    public function ajax_builder_preview(): void {
        check_ajax_referer('isf_builder_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'formflow')]);
        }

        $schema_json = isset($_POST['schema']) ? wp_unslash($_POST['schema']) : '';

        $schema = json_decode($schema_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid schema format.', 'formflow')]);
        }

        // Render preview HTML using FormRenderer
        $renderer = new \ISF\Builder\FormRenderer();
        $html = $renderer->render($schema, [], []);

        wp_send_json_success(['html' => $html]);
    }
}

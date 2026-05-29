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

// Load admin traits.
require_once __DIR__ . '/traits/trait-webhooks.php';
require_once __DIR__ . '/traits/trait-submissions.php';
require_once __DIR__ . '/traits/trait-reports.php';
require_once __DIR__ . '/traits/trait-compliance.php';
require_once __DIR__ . '/traits/trait-tools.php';

class Admin {

    use Admin_Webhooks;
    use Admin_Submissions;
    use Admin_Reports;
    use Admin_Compliance;
    use Admin_Tools;

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

        // Form Editor (redesigned) scoped styles
        $screen = get_current_screen();
        if (strpos($screen->id ?? '', 'isf-form') !== false) {
            wp_enqueue_style(
                'isf-form-editor',
                ISF_PLUGIN_URL . 'admin/assets/css/form-editor.css',
                [],
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

        // Form Editor (redesigned) scoped JS — save-on-blur + mode switcher
        $screen = get_current_screen();
        if (strpos($screen->id ?? '', 'isf-form') !== false) {
            wp_enqueue_script(
                'isf-form-editor',
                ISF_PLUGIN_URL . 'admin/assets/js/form-editor.js',
                ['jquery'],
                ISF_VERSION,
                true
            );
            wp_localize_script('isf-form-editor', 'formflowEditor', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('isf_admin_nonce'),
                'strings'  => [
                    'saving' => __('Saving…', 'formflow'),
                    'saved'  => __('Saved', 'formflow'),
                    'error'  => __('Save failed', 'formflow'),
                ],
            ]);
        }
    }

    /**
     * Check if current page is a plugin admin page.
     *
     * Every FormFlow admin page slug starts with `isf-` and every page
     * hook contains either the slug verbatim or the top-level
     * `isf-dashboard` token. The previous implementation hard-coded the
     * full hook strings with `is-forms_` prefix — but the menu label
     * "FF Forms" actually slugifies to `ff-forms`, so 15 of 16 entries
     * never matched. That's why bulk actions, deletes, and CSV export
     * silently failed on every subpage (no JS enqueued).
     *
     * Match anything containing `isf-` or via `$_GET['page']`, which is
     * immune to menu-label changes.
     */
    private function is_plugin_page(string $hook): bool {
        if (strpos($hook, 'isf-') !== false) {
            return true;
        }
        $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
        return $page !== '' && strpos($page, 'isf-') === 0;
    }

    /**
     * Check if current page is the form builder page
     */
    private function is_form_builder_page(string $hook): bool {
        // Matches the legacy standalone form-builder page AND the new
        // editor's "Form fields" task (page slug isf-form), so the
        // form-builder JS/CSS load wherever the field-list UI is mounted.
        return strpos($hook, 'isf-form-builder') !== false
            || strpos($hook, 'isf-form') !== false;
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
        $valid_tabs = ['license', 'settings', 'diagnostics', 'compliance', 'accessibility', 'new-editor'];
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

        // Client-mode write gate — strip dev-only fields before processing
        $_POST = \ISF\FormEditor\FieldGate::strip_blocked_fields(
            $_POST,
            \ISF\FormEditor\ModeResolver::effective_mode()
        );

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

        // Get existing instance settings if updating
        $existing_settings = [];
        if ($id) {
            $existing = $this->db->get_instance($id);
            $existing_settings = $existing['settings'] ?? [];
        }

        // Parse settings — legacy editor sends JSON string, new form-editor
        // sends PHP nested array via settings[a][b] form notation. Handle both.
        $new_settings = [];
        if (!empty($_POST['settings'])) {
            $raw = $_POST['settings'];
            if (is_array($raw)) {
                // Already nested by PHP's form parser (new form-editor.js)
                $new_settings = $raw;
            } else {
                $decoded = json_decode(stripslashes((string) $raw), true);
                if (is_array($decoded)) {
                    $new_settings = $decoded;
                }
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

        // Destinations (2.9.0+): posted as a separate destinations_json
        // string by the destinations pod's inline JS. Server-side:
        //   1. decode the JSON
        //   2. for each destination, encrypt sensitive config fields
        //      (preserving stored value when admin left field blank)
        //   3. replace settings['destinations'] wholesale
        if (isset($_POST['destinations_json']) && class_exists('\\ISF\\Destinations\\DestinationRegistry')) {
            $posted = json_decode(stripslashes((string) $_POST['destinations_json']), true);
            if (is_array($posted)) {
                $existing_destinations = $existing_settings['destinations'] ?? [];
                $registry = \ISF\Destinations\DestinationRegistry::instance();
                $clean = [];
                foreach ($posted as $idx => $dest) {
                    if (!is_array($dest) || empty($dest['type'])) {
                        continue;
                    }
                    $type = sanitize_text_field((string) $dest['type']);
                    $name = sanitize_text_field((string) ($dest['name'] ?? $type));
                    $is_active = !empty($dest['is_active']);
                    $new_config = is_array($dest['config'] ?? null) ? $dest['config'] : [];
                    $existing_config = $existing_destinations[$idx]['config'] ?? [];

                    $destination = $registry->get($type);
                    if ($destination instanceof \ISF\Destinations\BaseDestination) {
                        $new_config = $destination->encrypt_sensitive_fields($new_config, $existing_config);
                    } else {
                        // Unknown destination type — preserve existing config untouched.
                        $new_config = $existing_config;
                    }

                    $clean[] = [
                        'type'      => $type,
                        'name'      => $name,
                        'is_active' => (bool) $is_active,
                        'config'    => $new_config,
                    ];
                }
                $settings['destinations'] = $clean;
            }
        }

        // Build $data from $_POST. For partial updates (per-field save-on-blur
        // from the new form-editor), only include columns the caller actually
        // sent — missing columns retain their current DB value.
        $is_update = $id > 0 && !empty($existing);

        $col_resolvers = [
            'name'               => fn($v) => sanitize_text_field((string) $v),
            'slug'               => fn($v) => sanitize_title((string) $v),
            'utility'            => fn($v) => sanitize_text_field((string) $v),
            'form_type'          => fn($v) => sanitize_text_field((string) $v),
            'api_endpoint'       => fn($v) => esc_url_raw((string) $v),
            'api_password'       => fn($v) => (string) $v,
            'support_email_from' => fn($v) => sanitize_email((string) $v),
            'support_email_to'   => fn($v) => sanitize_textarea_field((string) $v),
        ];

        $data = [];
        foreach ($col_resolvers as $col => $resolver) {
            if (array_key_exists($col, $_POST)) {
                $data[$col] = $resolver($_POST[$col]);
            } elseif (!$is_update) {
                // Create path: missing optional fields get sensible defaults
                $defaults = [
                    'name' => '', 'slug' => '', 'utility' => '',
                    'form_type' => 'enrollment', 'api_endpoint' => '',
                    'api_password' => '', 'support_email_from' => '',
                    'support_email_to' => '',
                ];
                $data[$col] = $defaults[$col];
            }
            // Update path: column simply omitted; existing DB value preserved
        }

        // Boolean columns — POST absence on update means "no change", not "off"
        foreach (['is_active', 'test_mode'] as $bool_col) {
            if (array_key_exists($bool_col, $_POST)) {
                $data[$bool_col] = $_POST[$bool_col] ? 1 : 0;
            } elseif (!$is_update) {
                // Create path: default both to 0 unless explicitly set
                $data[$bool_col] = 0;
            }
            // Update path: omitted means keep current value
        }

        // Settings always sent on save (even empty array means "no change"
        // per the merged shape above)
        $data['settings'] = $settings;

        // Validate required fields ONLY on the create path. Updates may post
        // any subset of fields; the existing DB row is the source of truth.
        if (!$is_update) {
            $demo_mode = $data['settings']['demo_mode'] ?? false;
            if (empty($data['name']) || empty($data['slug'])) {
                wp_send_json_error([
                    'message' => __('Please fill in Name and Slug fields.', 'formflow')
                ]);
                return;
            }
            if (!$demo_mode && empty($data['api_endpoint'] ?? '')) {
                wp_send_json_error([
                    'message' => __('API Endpoint is required unless Demo Mode is enabled.', 'formflow')
                ]);
                return;
            }
            if ($demo_mode && empty($data['api_endpoint'] ?? '')) {
                $data['api_endpoint'] = 'https://demo.example.com/api';
            }
        }

        // Check for duplicate slug — only when slug is actually being set/changed
        if (isset($data['slug']) && $data['slug'] !== '') {
            $existing_by_slug = $this->db->get_instance_by_slug($data['slug']);
            if ($existing_by_slug && $existing_by_slug['id'] != $id) {
                wp_send_json_error([
                    'message' => __('A form with this slug already exists.', 'formflow')
                ]);
                return;
            }
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
            // Audit log — for partial updates, fall back to the existing row's
            // values for any column $data didn't include.
            $logged = $data + ($existing ?: []);
            $this->db->log_audit(
                $id && isset($_POST['id']) && $_POST['id'] ? 'instance_update' : 'instance_create',
                'instance',
                $id,
                $logged['name'] ?? '',
                [
                    'slug'      => $logged['slug'] ?? '',
                    'utility'   => $logged['utility'] ?? '',
                    'form_type' => $logged['form_type'] ?? '',
                    'is_active' => $logged['is_active'] ?? null,
                    'test_mode' => $logged['test_mode'] ?? null,
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
    /**
     * Test a destination's connection from the admin "Test Connection" button.
     *
     * Accepts a single posted destination slot (type/name/is_active/config)
     * and calls $destination->test_connection() with the supplied config.
     * Sensitive fields posted blank are merged with the existing stored
     * config (preserve-on-empty), then decrypted in-memory for the test
     * call. Nothing is persisted by this handler.
     */
    public function ajax_test_destination(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }
        if (!class_exists('\\ISF\\Destinations\\DestinationRegistry')) {
            wp_send_json_error(['message' => __('Destinations subsystem unavailable.', 'formflow')]);
            return;
        }

        $raw = isset($_POST['destination']) ? stripslashes((string) $_POST['destination']) : '';
        $posted = json_decode($raw, true);
        if (!is_array($posted) || empty($posted['type'])) {
            wp_send_json_error(['message' => __('Invalid destination payload.', 'formflow')]);
            return;
        }

        $type = sanitize_text_field((string) $posted['type']);
        $destination = \ISF\Destinations\DestinationRegistry::instance()->get($type);
        if (!$destination) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: destination type id */
                    __('Destination type "%s" is not registered.', 'formflow'),
                    $type
                ),
            ]);
            return;
        }

        $posted_config = is_array($posted['config'] ?? null) ? $posted['config'] : [];

        // Preserve-on-empty: merge in the stored destination's encrypted
        // values for any sensitive field the admin left blank, so Test
        // Connection works against the existing creds.
        $instance_id = isset($_POST['instance_id']) ? (int) $_POST['instance_id'] : 0;
        if ($instance_id > 0 && $destination instanceof \ISF\Destinations\BaseDestination) {
            $existing_instance = $this->db->get_instance($instance_id);
            $existing_destinations = $existing_instance['settings']['destinations'] ?? [];
            $existing_config = [];
            $name = (string) ($posted['name'] ?? $type);
            foreach ($existing_destinations as $d) {
                if (($d['type'] ?? '') === $type && ($d['name'] ?? '') === $name) {
                    $existing_config = $d['config'] ?? [];
                    break;
                }
            }
            $posted_config = $destination->encrypt_sensitive_fields($posted_config, $existing_config);
            $posted_config = $destination->decrypt_sensitive_fields($posted_config);
        }

        // Validate before testing — surface obvious misconfig faster.
        $errors = $destination->validate_config($posted_config);
        if (!empty($errors)) {
            wp_send_json_error([
                'message' => implode(' / ', array_slice($errors, 0, 3)),
            ]);
            return;
        }

        try {
            $result = $destination->test_connection($posted_config);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage() ?: __('Test failed.', 'formflow')]);
            return;
        }

        if (!empty($result['success'])) {
            wp_send_json_success([
                'message' => $result['message'] ?? __('Connection OK.', 'formflow'),
                'code'    => $result['code'] ?? 'ok',
            ]);
            return;
        }

        wp_send_json_error([
            'message' => $result['message'] ?? __('Test failed.', 'formflow'),
            'code'    => $result['code'] ?? 'unknown',
        ]);
    }

    public function ajax_test_api(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $endpoint = esc_url_raw($_POST['api_endpoint'] ?? '');
        $password = sanitize_text_field(wp_unslash((string) ($_POST['api_password'] ?? '')));

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

    // API Usage AJAX Handlers
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

}

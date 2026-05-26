<?php
/**
 * Plugin Name: FormFlow
 * Plugin URI: https://formflow.dev
 * Description: Secure API-integrated enrollment and scheduling forms for utility demand response programs
 * Version: 2.8.7
 * Author: Peanut Graphic
 * Author URI: https://peanutgraphic.com
 * Text Domain: formflow
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('ISF_VERSION', '2.8.7');
define('ISF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ISF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ISF_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('ISF_PLUGIN_FILE', __FILE__);
define('ISF_CONNECTORS_DIR', ISF_PLUGIN_DIR . 'connectors/');

// Database table names (without prefix)
define('ISF_TABLE_INSTANCES', 'isf_instances');
define('ISF_TABLE_SUBMISSIONS', 'isf_submissions');
define('ISF_TABLE_LOGS', 'isf_logs');
define('ISF_TABLE_ANALYTICS', 'isf_analytics');
define('ISF_TABLE_VISITORS', 'isf_visitors');
define('ISF_TABLE_TOUCHES', 'isf_touches');
define('ISF_TABLE_HANDOFFS', 'isf_handoffs');
define('ISF_TABLE_EXTERNAL_COMPLETIONS', 'isf_external_completions');

// Autoloader
spl_autoload_register(function ($class) {
    // Check if the class is in our namespace
    $prefix = 'ISF\\';
    $base_dir = ISF_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Class map for special cases (acronyms, unusual naming)
    $class_map = [
        'ISF\\ABTesting' => 'class-ab-testing.php',
        'ISF\\UTMTracker' => 'class-utm-tracker.php',
        'ISF\\PWAHandler' => 'class-pwa-handler.php',
        'ISF\\CRMIntegration' => 'class-crm-integration.php',
        'ISF\\SMSHandler' => 'class-sms-handler.php',
        'ISF\\Analytics\\GTMHelper' => 'analytics/class-gtm-helper.php',
        'ISF\\ML\\FormPrediction' => 'ml/class-form-prediction.php',
        'ISF\\ML\\FormPredictionApi' => 'ml/class-form-prediction-api.php',
    ];

    // Check class map first
    if (isset($class_map[$class])) {
        $file = $base_dir . $class_map[$class];
        if (file_exists($file)) {
            require $file;
            return;
        }
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Map namespace to directory structure
    $path_parts = explode('\\', $relative_class);
    $class_name = array_pop($path_parts);

    // Convert class name to file name (CamelCase to kebab-case)
    $file_name = 'class-' . strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $class_name)) . '.php';

    // Build the file path
    if (!empty($path_parts)) {
        $sub_dir = strtolower(implode('/', $path_parts)) . '/';
        $file = $base_dir . $sub_dir . $file_name;
    } else {
        $file = $base_dir . $file_name;
    }

    if (file_exists($file)) {
        require $file;
        return;
    }

    // Also check connectors directory for connector classes
    if (count($path_parts) >= 2 && $path_parts[0] === 'Connectors') {
        $connector_name = strtolower($path_parts[1]);
        $connector_file = ISF_CONNECTORS_DIR . $connector_name . '/' . $file_name;
        if (file_exists($connector_file)) {
            require $connector_file;
            return;
        }
    }

    // Check for interface files
    $interface_name = 'interface-' . strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $class_name)) . '.php';
    if (!empty($path_parts)) {
        $interface_file = $base_dir . $sub_dir . $interface_name;
    } else {
        $interface_file = $base_dir . $interface_name;
    }
    if (file_exists($interface_file)) {
        require $interface_file;
    }
});

/**
 * Activation hook
 */
function isf_activate() {
    require_once ISF_PLUGIN_DIR . 'includes/class-activator.php';
    ISF\Activator::activate();
}
register_activation_hook(__FILE__, 'isf_activate');

/**
 * Deactivation hook
 */
function isf_deactivate() {
    require_once ISF_PLUGIN_DIR . 'includes/class-deactivator.php';
    ISF\Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'isf_deactivate');

/**
 * Initialize plugin
 */
function isf_init() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.0', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('FormFlow requires PHP 8.0 or higher.', 'formflow');
            echo '</p></div>';
        });
        return;
    }

    // Composer autoloader — required for phpseclib3 (SFTP destination) and
    // any future vendored runtime dependencies. Guarded so a dev clone
    // without `composer install --no-dev` doesn't fatal; instead surfaces
    // a clear admin notice.
    if (file_exists(ISF_PLUGIN_DIR . 'vendor/autoload.php')) {
        require_once ISF_PLUGIN_DIR . 'vendor/autoload.php';
    } else {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>FormFlow:</strong> ';
            echo esc_html__('vendor/ is missing. Run `composer install --no-dev` in the plugin directory, or reinstall from a packaged release zip.', 'formflow');
            echo '</p></div>';
        });
        return;
    }

    // Load core classes
    require_once ISF_PLUGIN_DIR . 'includes/api/interface-api-connector.php';
    require_once ISF_PLUGIN_DIR . 'includes/api/class-connector-registry.php';
    require_once ISF_PLUGIN_DIR . 'includes/destinations/interface-destination.php';
    require_once ISF_PLUGIN_DIR . 'includes/destinations/class-delivery-result.php';
    require_once ISF_PLUGIN_DIR . 'includes/destinations/class-base-destination.php';
    require_once ISF_PLUGIN_DIR . 'includes/destinations/class-destination-registry.php';
    require_once ISF_PLUGIN_DIR . 'includes/destinations/class-delivery-log.php';
    require_once ISF_PLUGIN_DIR . 'includes/class-branding.php';
    require_once ISF_PLUGIN_DIR . 'includes/class-cache-manager.php';
    require_once ISF_PLUGIN_DIR . 'includes/class-queue-manager.php';
    require_once ISF_PLUGIN_DIR . 'includes/class-embed-handler.php';
    require_once ISF_PLUGIN_DIR . 'includes/class-hooks.php';
    require_once ISF_PLUGIN_DIR . 'includes/class-license-manager.php';
    require_once ISF_PLUGIN_DIR . 'includes/class-security-hardening.php';

    // Initialize singletons
    ISF\Api\ConnectorRegistry::instance();
    ISF\Destinations\DestinationRegistry::instance();
    ISF\CacheManager::instance();
    ISF\LicenseManager::instance();

    // Initialize security hardening
    $security = ISF\SecurityHardening::instance();
    $security->init();

    // Initialize plugin updater (for automatic updates)
    require_once ISF_PLUGIN_DIR . 'includes/class-updater.php';
    $updater = new ISF\Updater();
    $updater->init();

    // Display update messages
    add_action('admin_notices', ['ISF\Updater', 'display_update_message']);

    // Initialize accessibility features (ADA/WCAG compliance)
    require_once ISF_PLUGIN_DIR . 'includes/class-accessibility.php';
    $accessibility = ISF\Accessibility::instance();
    $accessibility->init();

    // Initialize queue manager
    $queue = new ISF\QueueManager();
    $queue->init();

    // Initialize embed handler
    $embed = new ISF\EmbedHandler();
    $embed->init();

    // Initialize tester-bridge harness (dormant unless TESTER_MODE is true)
    require_once ISF_PLUGIN_DIR . 'includes/class-tester-bridge.php';
    (new ISF\TesterBridge())->init();

    // Load bundled connectors + destinations (both share the same
    // glob — any connectors/*/loader.php is required, regardless of
    // whether it registers a Connector or a Destination).
    isf_load_bundled_connectors();

    // Explicitly fire destination registration. The registry's
    // plugins_loaded@5 self-hook is unreliable when the singleton
    // is first instantiated inside plugins_loaded@10 (priority 5
    // is already past). Calling init_destinations() here ensures
    // every loaded loader.php has its add_action() callbacks fired.
    ISF\Destinations\DestinationRegistry::instance()->init_destinations();

    // Load plugin
    require_once ISF_PLUGIN_DIR . 'includes/class-plugin.php';
    $plugin = new ISF\Plugin();
    $plugin->run();
}
add_action('plugins_loaded', 'isf_init');

/**
 * Load bundled connectors from the connectors directory
 */
function isf_load_bundled_connectors() {
    $connectors_dir = ISF_CONNECTORS_DIR;

    if (!is_dir($connectors_dir)) {
        return;
    }

    // Scan for connector directories
    $directories = glob($connectors_dir . '*', GLOB_ONLYDIR);

    foreach ($directories as $dir) {
        $loader = $dir . '/loader.php';
        if (file_exists($loader)) {
            require_once $loader;
        }
    }

    /**
     * Action: After bundled connectors are loaded
     *
     * External plugins can hook here to register additional connectors.
     */
    do_action('isf_connectors_loaded');
}

/**
 * Get plugin instance (for external access)
 */
function isf() {
    static $instance = null;
    if ($instance === null) {
        require_once ISF_PLUGIN_DIR . 'includes/class-plugin.php';
        $instance = new ISF\Plugin();
    }
    return $instance;
}

/**
 * Get connector registry instance
 *
 * @return ISF\Api\ConnectorRegistry
 */
function isf_connectors() {
    return ISF\Api\ConnectorRegistry::instance();
}

/**
 * Get a specific connector by ID
 *
 * @param string $id Connector ID
 * @return ISF\Api\ApiConnectorInterface|null
 */
function isf_get_connector(string $id) {
    return isf_connectors()->get($id);
}

/**
 * Get branding instance
 *
 * @return ISF\Branding
 */
function isf_branding() {
    return ISF\Branding::instance();
}

/**
 * Get a branding setting
 *
 * @param string $key Setting key
 * @param mixed $default Default value
 * @return mixed
 */
function isf_brand(string $key, $default = null) {
    return isf_branding()->get($key, $default);
}

/**
 * Get plugin name (from branding or default)
 *
 * @return string
 */
function isf_plugin_name() {
    return isf_branding()->get_plugin_name();
}

/**
 * Get cache manager instance
 *
 * @return ISF\CacheManager
 */
function isf_cache() {
    return ISF\CacheManager::instance();
}

/**
 * Queue manager instance holder
 */
global $isf_queue_manager;

/**
 * Get queue manager instance
 *
 * @return ISF\QueueManager
 */
function isf_queue() {
    global $isf_queue_manager;
    if ($isf_queue_manager === null) {
        $isf_queue_manager = new ISF\QueueManager();
    }
    return $isf_queue_manager;
}

/**
 * Embed handler instance holder
 */
global $isf_embed_handler;

/**
 * Get embed handler instance
 *
 * @return ISF\EmbedHandler
 */
function isf_embed() {
    global $isf_embed_handler;
    if ($isf_embed_handler === null) {
        $isf_embed_handler = new ISF\EmbedHandler();
    }
    return $isf_embed_handler;
}

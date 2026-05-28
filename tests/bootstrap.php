<?php
/**
 * PHPUnit Bootstrap File
 *
 * Sets up the testing environment for FormFlow plugin.
 */

// Composer autoloader
$autoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    die("Please run 'composer install' before running tests.\n");
}
require_once $autoloader;

// Load Brain Monkey for mocking WordPress functions
require_once dirname(__DIR__) . '/vendor/brain/monkey/inc/patchwork-loader.php';

// Define plugin constants for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('ISF_PLUGIN_DIR')) {
    define('ISF_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!defined('ISF_PLUGIN_URL')) {
    define('ISF_PLUGIN_URL', 'http://example.com/wp-content/plugins/intellisource-forms/');
}

if (!defined('ISF_VERSION')) {
    define('ISF_VERSION', '2.3.0');
}

// Define table constants
if (!defined('ISF_TABLE_INSTANCES')) {
    define('ISF_TABLE_INSTANCES', 'isf_instances');
}
if (!defined('ISF_TABLE_SUBMISSIONS')) {
    define('ISF_TABLE_SUBMISSIONS', 'isf_submissions');
}
if (!defined('ISF_TABLE_LOGS')) {
    define('ISF_TABLE_LOGS', 'isf_logs');
}
if (!defined('ISF_TABLE_ANALYTICS')) {
    define('ISF_TABLE_ANALYTICS', 'isf_analytics');
}
if (!defined('ISF_TABLE_VISITORS')) {
    define('ISF_TABLE_VISITORS', 'isf_visitors');
}
if (!defined('ISF_TABLE_TOUCHES')) {
    define('ISF_TABLE_TOUCHES', 'isf_touches');
}
if (!defined('ISF_TABLE_HANDOFFS')) {
    define('ISF_TABLE_HANDOFFS', 'isf_handoffs');
}
if (!defined('ISF_TABLE_EXTERNAL_COMPLETIONS')) {
    define('ISF_TABLE_EXTERNAL_COMPLETIONS', 'isf_external_completions');
}

// WordPress constants
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', '');
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string|false {
        return json_encode($data, $options, $depth);
    }
}

// Register the plugin's runtime autoloader so tests can resolve
// kebab-case `class-*.php` files (the plugin doesn't follow pure PSR-4).
spl_autoload_register(function ($class) {
    $prefix = 'ISF\\';
    $base_dir = ISF_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $path_parts = explode('\\', $relative_class);
    $class_name = array_pop($path_parts);

    $file_name = 'class-' . strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $class_name)) . '.php';

    if (!empty($path_parts)) {
        $kebab_parts = array_map(function ($part) {
            return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $part));
        }, $path_parts);
        $sub_dir = implode('/', $kebab_parts) . '/';
        $file = $base_dir . $sub_dir . $file_name;
    } else {
        $file = $base_dir . $file_name;
    }

    if (file_exists($file)) {
        require_once $file;
    }
});

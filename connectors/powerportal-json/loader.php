<?php
/**
 * PowerPortal JSON Connector Loader
 *
 * Registers the shared, utility-agnostic PowerPortal JSON connector.
 * formflow.php globs connectors/*\/loader.php, so this registers itself.
 *
 * @package FormFlow
 * @subpackage Connectors
 */

namespace ISF\Connectors\PowerportalJson;

if (!defined('ABSPATH')) {
    exit;
}

define('ISF_POWERPORTAL_JSON_PATH', __DIR__);

function load_connector(): void {
    require_once ISF_POWERPORTAL_JSON_PATH . '/class-powerportal-json-connector.php';
}

/**
 * @param \ISF\Api\ConnectorRegistry $registry
 */
function register_connector($registry): void {
    load_connector();
    $registry->register(new PowerportalJsonConnector());
}

add_action('isf_register_connectors', __NAMESPACE__ . '\\register_connector');

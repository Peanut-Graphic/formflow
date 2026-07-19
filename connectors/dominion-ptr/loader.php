<?php
/**
 * Dominion PTR Connector Loader
 *
 * Registers the Dominion Peak Time Rebates connector with the core plugin.
 * formflow.php globs connectors/*\/loader.php, so dropping this directory in
 * is all the registration this connector needs.
 *
 * @package FormFlow
 * @subpackage Connectors
 */

namespace ISF\Connectors\DominionPtr;

if (!defined('ABSPATH')) {
    exit;
}

define('ISF_DOMINION_PTR_PATH', __DIR__);

function load_connector(): void {
    require_once ISF_DOMINION_PTR_PATH . '/class-dominion-ptr-connector.php';
    require_once ISF_DOMINION_PTR_PATH . '/class-dominion-ptr-seeder.php';
}

/**
 * @param \ISF\Api\ConnectorRegistry $registry
 */
function register_connector($registry): void {
    load_connector();
    $registry->register(new DominionPtrConnector());
}

add_action('isf_register_connectors', __NAMESPACE__ . '\\register_connector');

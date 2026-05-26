<?php
/**
 * SFTP Destination Loader
 *
 * Registers the SFTP destination with the core plugin's destination
 * registry. Picked up automatically by isf_load_bundled_connectors()
 * which globs connectors/*\/loader.php.
 *
 * SFTP is a Destination, not a Connector — it delivers finished
 * submissions asynchronously rather than driving a synchronous
 * enrollment API flow. See:
 *   docs/superpowers/plans/2026-05-26-destinations-subsystem.md
 *
 * @package FormFlow
 * @subpackage Destinations
 * @since 2.9.0
 */

namespace ISF\Destinations\Sftp;

if (!defined('ABSPATH')) {
    exit;
}

define('ISF_SFTP_DEST_PATH', __DIR__);

function load_destination(): void {
    require_once ISF_SFTP_DEST_PATH . '/class-sftp-formatter.php';
    require_once ISF_SFTP_DEST_PATH . '/class-sftp-destination.php';
}

function register_destination($registry): void {
    load_destination();
    $registry->register(new SftpDestination());
}

add_action('isf_register_destinations', __NAMESPACE__ . '\\register_destination');

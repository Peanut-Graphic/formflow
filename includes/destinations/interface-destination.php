<?php
/**
 * Destination Interface
 *
 * Defines the contract that all submission destinations must implement.
 *
 * A Destination is an async, fire-and-forget delivery channel for finished
 * submissions (e.g. SFTP, S3, email-attachment, webhook). It is distinct
 * from an ApiConnector, which is a synchronous enrollment/scheduling API
 * called during the user-facing form flow.
 *
 * See docs/superpowers/plans/2026-05-26-destinations-subsystem.md
 *
 * @package FormFlow
 * @since 2.9.0
 */

namespace ISF\Destinations;

if (!defined('ABSPATH')) {
    exit;
}

interface DestinationInterface {

    /**
     * Unique destination identifier (e.g. 'sftp', 's3', 'email').
     */
    public function get_id(): string;

    /**
     * Human-readable destination name shown in the admin UI.
     */
    public function get_name(): string;

    /**
     * One-sentence description of what this destination does.
     */
    public function get_description(): string;

    /**
     * Semantic version of the destination implementation.
     */
    public function get_version(): string;

    /**
     * Field definitions for the admin per-instance config UI.
     *
     * Returns an array keyed by field name. Each value is a definition:
     *   [
     *     'label'       => 'Host',
     *     'type'        => 'text' | 'number' | 'password' | 'textarea' | 'select' | 'checkbox',
     *     'required'    => true|false,
     *     'description' => 'Helper text shown under the field',
     *     'default'     => '',
     *     'options'     => [...]  // for select
     *     'sensitive'   => true|false,  // if true, value is encrypted at rest
     *                                   // and never re-emitted to the DOM
     *     'show_if'     => ['auth_mode' => 'password'],  // conditional visibility
     *   ]
     */
    public function get_config_fields(): array;

    /**
     * Validate admin-submitted config values.
     *
     * @param array $config The configuration values.
     * @return array Empty array if valid, otherwise array of error strings.
     */
    public function validate_config(array $config): array;

    /**
     * Live connection test from the admin "Test Connection" button.
     *
     * Should perform a real handshake against the destination (e.g. SFTP
     * login + ls of remote path) without delivering any payload.
     *
     * @return array {
     *   @var bool   $success
     *   @var string $message  Readable status or error.
     *   @var string $code     Machine-readable code ('auth_failed', 'path_not_found', etc.).
     * }
     */
    public function test_connection(array $config): array;

    /**
     * Deliver a submission to the destination.
     *
     * Called synchronously by the Action Scheduler worker. The worker
     * handles retry; this method should NOT retry internally.
     *
     * @param array $submission The submission payload as a flat assoc array
     *                          (field name => string value, plus metadata
     *                          keys: submission_id, submitted_at, instance_slug).
     * @param array $config     Destination config (decrypted; sensitive fields
     *                          are already plaintext when this is called).
     * @return DeliveryResult
     */
    public function deliver(array $submission, array $config): DeliveryResult;

    /**
     * Optional features this destination supports.
     *
     * Standard keys:
     *   - 'retry'              : delivery worker should retry on failure
     *   - 'purge_after_export' : honor per-instance purge-after-export setting
     *   - 'batch'              : supports batching multiple submissions per call
     *   - 'test_connection'    : test_connection() is meaningful (vs no-op)
     *
     * @return array<string>
     */
    public function get_supported_features(): array;

    /**
     * Check whether this destination supports a specific feature.
     */
    public function supports(string $feature): bool;
}

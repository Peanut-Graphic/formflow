<?php
/**
 * Base Destination
 *
 * Optional abstract base class for destinations. Provides shared helpers
 * for encrypted credential storage, config-field defaults, and redacted
 * logging. Destinations may extend this or implement DestinationInterface
 * directly.
 *
 * @package FormFlow
 * @since 2.9.0
 */

namespace ISF\Destinations;

use ISF\Encryption;

if (!defined('ABSPATH')) {
    exit;
}

abstract class BaseDestination implements DestinationInterface {

    /**
     * Cached Encryption instance for this request.
     */
    private ?Encryption $encryption = null;

    /**
     * Subclasses must implement these — they're the destination's identity.
     */
    abstract public function get_id(): string;
    abstract public function get_name(): string;
    abstract public function get_description(): string;
    abstract public function get_version(): string;
    abstract public function get_config_fields(): array;
    abstract public function deliver(array $submission, array $config): DeliveryResult;

    /**
     * Default validate_config: ensure all required fields are non-empty.
     * Subclasses should override for destination-specific rules.
     */
    public function validate_config(array $config): array {
        $errors = [];

        foreach ($this->get_config_fields() as $name => $def) {
            if (empty($def['required'])) {
                continue;
            }
            if (!$this->is_field_visible($name, $def, $config)) {
                continue;
            }
            $value = $config[$name] ?? '';
            if ($value === '' || $value === null) {
                $errors[] = sprintf(
                    /* translators: %s: human-readable field label */
                    __('%s is required.', 'formflow'),
                    $def['label'] ?? $name
                );
            }
        }

        return $errors;
    }

    /**
     * Default test_connection: returns a "not implemented" failure.
     * Subclasses should override.
     */
    public function test_connection(array $config): array {
        return [
            'success' => false,
            'message' => __('This destination does not support connection testing.', 'formflow'),
            'code' => 'not_supported',
        ];
    }

    /**
     * Default supported features: retry + purge_after_export.
     */
    public function get_supported_features(): array {
        return ['retry', 'purge_after_export'];
    }

    public function supports(string $feature): bool {
        return in_array($feature, $this->get_supported_features(), true);
    }

    // ----- Credential helpers -----

    /**
     * Encrypt a sensitive config value for storage.
     *
     * Returns base64-encoded ciphertext. Empty input → empty output.
     */
    protected function encrypt_value(string $plaintext): string {
        if ($plaintext === '') {
            return '';
        }
        return $this->get_encryption()->encrypt($plaintext);
    }

    /**
     * Decrypt a stored sensitive config value.
     */
    protected function decrypt_value(string $ciphertext): string {
        if ($ciphertext === '') {
            return '';
        }
        return $this->get_encryption()->decrypt($ciphertext);
    }

    /**
     * Walk a config array and encrypt every field marked sensitive in
     * get_config_fields(). Returns a new array safe to persist.
     *
     * Behavior on update: if a sensitive field's value is empty AND the
     * destination already has a stored value, the stored value is preserved
     * (so the admin can leave the password field blank to keep it unchanged).
     *
     * @param array $config         New config from form submission.
     * @param array $existing_config Previously stored config (for preserve-on-empty).
     */
    public function encrypt_sensitive_fields(array $config, array $existing_config = []): array {
        foreach ($this->get_config_fields() as $name => $def) {
            if (empty($def['sensitive'])) {
                continue;
            }
            $value = $config[$name] ?? '';

            if ($value === '' && !empty($existing_config[$name])) {
                $config[$name] = $existing_config[$name];
                continue;
            }

            $config[$name] = $this->encrypt_value((string) $value);
        }
        return $config;
    }

    /**
     * Decrypt every sensitive field in a stored config for use at runtime.
     * Always call this before passing config to deliver() / test_connection().
     */
    public function decrypt_sensitive_fields(array $config): array {
        foreach ($this->get_config_fields() as $name => $def) {
            if (empty($def['sensitive'])) {
                continue;
            }
            $value = $config[$name] ?? '';
            $config[$name] = $this->decrypt_value((string) $value);
        }
        return $config;
    }

    /**
     * Build a redacted copy of config for logging (sensitive values masked).
     */
    public function redact_for_log(array $config): array {
        foreach ($this->get_config_fields() as $name => $def) {
            if (empty($def['sensitive'])) {
                continue;
            }
            if (!empty($config[$name])) {
                $config[$name] = '[REDACTED]';
            }
        }
        return $config;
    }

    // ----- Field visibility (show_if) -----

    /**
     * Whether a field is visible given the current config (honors show_if).
     */
    protected function is_field_visible(string $name, array $def, array $config): bool {
        if (empty($def['show_if']) || !is_array($def['show_if'])) {
            return true;
        }
        foreach ($def['show_if'] as $other_name => $expected) {
            $actual = $config[$other_name] ?? null;
            if (is_array($expected)) {
                if (!in_array($actual, $expected, true)) {
                    return false;
                }
            } else {
                if ($actual !== $expected) {
                    return false;
                }
            }
        }
        return true;
    }

    private function get_encryption(): Encryption {
        if ($this->encryption === null) {
            $this->encryption = new Encryption();
        }
        return $this->encryption;
    }
}

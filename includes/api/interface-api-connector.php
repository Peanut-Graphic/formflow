<?php
/**
 * API Connector Interface
 *
 * Defines the contract that all API connectors must implement.
 * This allows the core plugin to work with any external API system.
 *
 * @package FormFlow
 * @since 2.0.0
 */

namespace ISF\Api;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface ApiConnectorInterface
 *
 * All third-party API connectors must implement this interface.
 */
interface ApiConnectorInterface {

    /**
     * Get connector identifier
     *
     * @return string Unique connector ID (e.g., 'intellisource', 'salesforce')
     */
    public function get_id(): string;

    /**
     * Get connector display name
     *
     * @return string Human-readable connector name
     */
    public function get_name(): string;

    /**
     * Get connector description
     *
     * @return string Brief description of what this connector does
     */
    public function get_description(): string;

    /**
     * Get connector version
     *
     * @return string Semantic version (e.g., '1.0.0')
     */
    public function get_version(): string;

    /**
     * Get required configuration fields
     *
     * Returns an array of field definitions for the admin settings panel.
     *
     * @return array Configuration field definitions
     * Example:
     * [
     *     'api_endpoint' => [
     *         'label' => 'API Endpoint',
     *         'type' => 'url',
     *         'required' => true,
     *         'description' => 'The base URL for API calls'
     *     ],
     *     'api_key' => [
     *         'label' => 'API Key',
     *         'type' => 'password',
     *         'required' => true,
     *         'description' => 'Your API authentication key'
     *     ]
     * ]
     */
    public function get_config_fields(): array;

    /**
     * Validate configuration
     *
     * @param array $config The configuration values to validate
     * @return array Empty array if valid, or array of error messages
     */
    public function validate_config(array $config): array;

    /**
     * Test API connection
     *
     * @param array $config The connector configuration
     * @return array Result with 'success' (bool) and 'message' (string)
     */
    public function test_connection(array $config): array;

    /**
     * Validate an account/customer
     *
     * @param array $data Validation data (account_number, zip, etc.)
     * @param array $config Connector configuration
     * @return AccountValidationResult The validation result
     */
    public function validate_account(array $data, array $config): AccountValidationResult;

    /**
     * Submit enrollment/registration
     *
     * @param array $form_data The complete form data
     * @param array $config Connector configuration
     * @return EnrollmentResult The submission result
     */
    public function submit_enrollment(array $form_data, array $config): EnrollmentResult;

    /**
     * Get available appointment slots
     *
     * @param array $data Scheduling query data
     * @param array $config Connector configuration
     * @return SchedulingResult The available slots
     */
    public function get_schedule_slots(array $data, array $config): SchedulingResult;

    /**
     * Book an appointment
     *
     * @param array $data Booking data (date, time, etc.)
     * @param array $config Connector configuration
     * @return BookingResult The booking result
     */
    public function book_appointment(array $data, array $config): BookingResult;

    /**
     * Map form fields to API format
     *
     * @param array $form_data Internal form field names and values
     * @param string $type The operation type ('enrollment', 'scheduling', etc.)
     * @return array Mapped data ready for API submission
     */
    public function map_fields(array $form_data, string $type = 'enrollment'): array;

    /**
     * Get supported features
     *
     * Returns which optional features this connector supports.
     *
     * @return array Array of supported feature keys
     * Example: ['account_validation', 'scheduling', 'promo_codes']
     */
    public function get_supported_features(): array;

    /**
     * Check if a feature is supported
     *
     * @param string $feature The feature key
     * @return bool True if supported
     */
    public function supports(string $feature): bool;

    /**
     * Get preset configurations (optional)
     *
     * For connectors that serve multiple clients/accounts, return preset configurations.
     *
     * @return array Array of presets keyed by identifier
     * Example:
     * [
     *     'delmarva_de' => [
     *         'name' => 'Delmarva Power - Delaware',
     *         'api_endpoint' => 'https://...',
     *         'settings' => [...]
     *     ]
     * ]
     */
    public function get_presets(): array;
}

/**
 * Base class for account validation results
 */
class AccountValidationResult {
    public bool $is_valid = false;
    public string $error_code = '';
    public string $error_message = '';
    public array $customer_data = [];
    public array $raw_response = [];

    public function __construct(array $data = []) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function is_valid(): bool {
        return $this->is_valid;
    }

    public function get_error_code(): string {
        return $this->error_code;
    }

    public function get_error_message(): string {
        return $this->error_message;
    }

    public function get_customer_data(): array {
        return $this->customer_data;
    }

    public function toArray(): array {
        return [
            'is_valid' => $this->is_valid,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'customer_data' => $this->customer_data,
        ];
    }
}

/**
 * Base class for enrollment submission results
 */
class EnrollmentResult {
    public bool $success = false;
    public string $confirmation_number = '';
    public string $error_code = '';
    public string $error_message = '';
    public array $data = [];
    public array $raw_response = [];

    public function __construct(array $data = []) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function is_successful(): bool {
        return $this->success;
    }

    public function get_confirmation_number(): string {
        return $this->confirmation_number;
    }

    /**
     * Accessors for the error pair.
     *
     * Additive: the properties were already public and populated, but had no
     * getters here while FormFlow Lite's identical DTO did. Connectors ported
     * from Lite (dominion-ptr) call these, so the gap was a real portability
     * break rather than a style difference.
     */
    public function get_error_code(): string {
        return $this->error_code;
    }

    public function get_error_message(): string {
        return $this->error_message;
    }

    public function toArray(): array {
        return [
            'success' => $this->success,
            'confirmation_number' => $this->confirmation_number,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'data' => $this->data,
        ];
    }
}

/**
 * Base class for booking results
 */
class BookingResult {
    public bool $success = false;
    public string $confirmation_number = '';
    public string $appointment_date = '';
    public string $appointment_time = '';
    public string $error_code = '';
    public string $error_message = '';
    public array $raw_response = [];

    public function __construct(array $data = []) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function is_successful(): bool {
        return $this->success;
    }

    /** Additive accessors — see the note on EnrollmentResult. */
    public function get_error_code(): string {
        return $this->error_code;
    }

    public function get_error_message(): string {
        return $this->error_message;
    }

    public function toArray(): array {
        return [
            'success' => $this->success,
            'confirmation_number' => $this->confirmation_number,
            'appointment_date' => $this->appointment_date,
            'appointment_time' => $this->appointment_time,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
        ];
    }
}

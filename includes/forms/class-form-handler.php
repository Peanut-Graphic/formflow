<?php
/**
 * Form Handler
 *
 * Handles form processing and validation.
 *
 * @package FormFlow
 */

namespace ISF\Forms;

use ISF\Database\Database;
use ISF\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Form Handler class
 */
class FormHandler
{
    /**
     * Database instance
     *
     * @var Database
     */
    private Database $database;

    /**
     * Security instance
     *
     * @var Security
     */
    private Security $security;

    /**
     * Validation errors
     *
     * @var array
     */
    private array $errors = [];

    /**
     * Constructor
     *
     * @param Database $database Database instance.
     * @param Security $security Security instance.
     */
    public function __construct(Database $database, Security $security)
    {
        $this->database = $database;
        $this->security = $security;
    }

    /**
     * Validate step 1 data (Program Selection)
     *
     * When $require_wifi is true, the instance has opted into the WiFi
     * eligibility gate: a Web-Programmable Thermostat cannot be installed in a
     * home without WiFi, so that combination is rejected here. The check must
     * live server-side because the client-side gate is bypassable, and the
     * purpose of the feature is preventing installs that cannot succeed.
     *
     * The Outdoor Switch has no WiFi requirement, so the answer is never
     * required for it and its absence never blocks enrollment.
     *
     * @param array $data         Form data.
     * @param bool  $require_wifi Whether this instance enforces the WiFi gate.
     * @return bool True if valid.
     */
    public function validateStep1(array $data, bool $require_wifi = false): bool
    {
        $this->errors = [];

        if (empty($data['has_ac'])) {
            $this->errors['has_ac'] = __('You must have a Central Air Conditioner or Heat Pump to participate.', 'formflow');
        }

        if (empty($data['device_type']) || !in_array($data['device_type'], ['thermostat', 'dcu'], true)) {
            $this->errors['device_type'] = __('Please select a device type.', 'formflow');
        }

        // Fails closed: only an explicit "yes" clears the gate.
        if ($require_wifi
            && ($data['device_type'] ?? '') === 'thermostat'
            && ($data['has_wifi'] ?? '') !== 'yes'
        ) {
            $this->errors['has_wifi'] = __('Home WiFi is required for the Web-Programmable Thermostat. Please choose the Outdoor Switch instead.', 'formflow');
        }

        return empty($this->errors);
    }

    /**
     * Validate step 2 data (Account Validation)
     *
     * @param array $data Form data.
     * @return bool True if valid.
     */
    public function validateStep2(array $data): bool
    {
        $this->errors = [];

        if (empty($data['utility_no'])) {
            $this->errors['utility_no'] = __('Account number is required.', 'formflow');
        }

        if (empty($data['zip'])) {
            $this->errors['zip'] = __('ZIP code is required.', 'formflow');
        } elseif (!preg_match('/^\d{5}$/', $data['zip'])) {
            $this->errors['zip'] = __('Please enter a valid 5-digit ZIP code.', 'formflow');
        }

        return empty($this->errors);
    }

    /**
     * Validate step 3 data (Customer Information)
     *
     * @param array $data Form data.
     * @return bool True if valid.
     */
    public function validateStep3(array $data): bool
    {
        $this->errors = [];

        // Required fields
        $required = [
            'first_name' => __('First name is required.', 'formflow'),
            'last_name' => __('Last name is required.', 'formflow'),
            'email' => __('Email address is required.', 'formflow'),
            'phone' => __('Phone number is required.', 'formflow'),
            'street' => __('Street address is required.', 'formflow'),
            'city' => __('City is required.', 'formflow'),
            'state' => __('State is required.', 'formflow'),
        ];

        foreach ($required as $field => $message) {
            if (empty($data[$field])) {
                $this->errors[$field] = $message;
            }
        }

        // Email validation
        if (!empty($data['email']) && !is_email($data['email'])) {
            $this->errors['email'] = __('Please enter a valid email address.', 'formflow');
        }

        // Email confirmation
        if (!empty($data['email']) && !empty($data['email_confirm'])) {
            if ($data['email'] !== $data['email_confirm']) {
                $this->errors['email_confirm'] = __('Email addresses do not match.', 'formflow');
            }
        }

        // Phone validation
        if (!empty($data['phone'])) {
            $phone = preg_replace('/\D/', '', $data['phone']);
            if (strlen($phone) !== 10) {
                $this->errors['phone'] = __('Please enter a valid 10-digit phone number.', 'formflow');
            }
        }

        // State validation
        if (!empty($data['state']) && !in_array($data['state'], ['DC', 'DE', 'MD'], true)) {
            $this->errors['state'] = __('Please select a valid state.', 'formflow');
        }

        // ZIP confirmation
        if (empty($data['zip_confirm'])) {
            $this->errors['zip_confirm'] = __('ZIP code is required.', 'formflow');
        } elseif (!preg_match('/^\d{5}$/', $data['zip_confirm'])) {
            $this->errors['zip_confirm'] = __('Please enter a valid 5-digit ZIP code.', 'formflow');
        }

        return empty($this->errors);
    }

    /**
     * Validate step 4 data (Schedule)
     *
     * @param array $data Form data.
     * @return bool True if valid.
     */
    public function validateStep4(array $data): bool
    {
        $this->errors = [];

        if (empty($data['schedule_date'])) {
            $this->errors['schedule_date'] = __('Please select an installation date.', 'formflow');
        }

        if (empty($data['schedule_time'])) {
            $this->errors['schedule_time'] = __('Please select an installation time.', 'formflow');
        }

        // Validate date format and that it's in the future
        if (!empty($data['schedule_date'])) {
            $date = \DateTime::createFromFormat('Y-m-d', $data['schedule_date']);
            if (!$date || $date->format('Y-m-d') !== $data['schedule_date']) {
                $this->errors['schedule_date'] = __('Invalid date format.', 'formflow');
            } elseif ($date < new \DateTime('today')) {
                $this->errors['schedule_date'] = __('Please select a future date.', 'formflow');
            }
        }

        return empty($this->errors);
    }

    /**
     * Validate step 5 data (Confirmation)
     *
     * @param array $data Form data.
     * @return bool True if valid.
     */
    public function validateStep5(array $data): bool
    {
        $this->errors = [];

        if (empty($data['agree_terms'])) {
            $this->errors['agree_terms'] = __('You must agree to the Terms and Conditions.', 'formflow');
        }

        if (empty($data['agree_adult'])) {
            $this->errors['agree_adult'] = __('You must confirm that you are at least 18 years old.', 'formflow');
        }

        return empty($this->errors);
    }

    /**
     * Get validation errors
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get first error message
     *
     * @return string|null
     */
    public function getFirstError(): ?string
    {
        return !empty($this->errors) ? reset($this->errors) : null;
    }

    /**
     * Sanitize form data for step 1
     *
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    public function sanitizeStep1(array $data): array
    {
        return [
            'has_ac' => !empty($data['has_ac']),
            'device_type' => $this->security->sanitize($data['device_type'] ?? '', 'text'),
        ];
    }

    /**
     * Sanitize form data for step 2
     *
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    public function sanitizeStep2(array $data): array
    {
        return [
            'utility_no' => $this->security->sanitize($data['utility_no'] ?? '', 'text'),
            'zip' => $this->security->sanitize($data['zip'] ?? '', 'text'),
        ];
    }

    /**
     * Sanitize form data for step 3
     *
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    public function sanitizeStep3(array $data): array
    {
        return [
            'first_name' => $this->security->sanitize($data['first_name'] ?? '', 'text'),
            'last_name' => $this->security->sanitize($data['last_name'] ?? '', 'text'),
            'email' => $this->security->sanitize($data['email'] ?? '', 'email'),
            'email_confirm' => $this->security->sanitize($data['email_confirm'] ?? '', 'email'),
            'phone' => $this->security->sanitize($data['phone'] ?? '', 'phone'),
            'phone_type' => $this->security->sanitize($data['phone_type'] ?? 'mobile', 'text'),
            'alt_phone' => $this->security->sanitize($data['alt_phone'] ?? '', 'phone'),
            'alt_phone_type' => $this->security->sanitize($data['alt_phone_type'] ?? 'home', 'text'),
            'street' => $this->security->sanitize($data['street'] ?? '', 'text'),
            'city' => $this->security->sanitize($data['city'] ?? '', 'text'),
            'state' => $this->security->sanitize($data['state'] ?? '', 'text'),
            'zip_confirm' => $this->security->sanitize($data['zip_confirm'] ?? '', 'text'),
            'special_instructions' => $this->security->sanitize($data['special_instructions'] ?? '', 'textarea'),
            'promo_code' => $this->security->sanitize($data['promo_code'] ?? '', 'text'),
        ];
    }

    /**
     * Sanitize form data for step 4
     *
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    public function sanitizeStep4(array $data): array
    {
        return [
            'schedule_date' => $this->security->sanitize($data['schedule_date'] ?? '', 'text'),
            'schedule_time' => $this->security->sanitize($data['schedule_time'] ?? '', 'text'),
            'schedule_fsr' => $this->security->sanitize($data['schedule_fsr'] ?? '', 'text'),
        ];
    }

    /**
     * Sanitize form data for step 5
     *
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    public function sanitizeStep5(array $data): array
    {
        return [
            'agree_terms' => !empty($data['agree_terms']),
            'agree_adult' => !empty($data['agree_adult']),
            'agree_contact' => !empty($data['agree_contact']),
        ];
    }

    /**
     * Prepare data for API submission
     *
     * @param array $form_data Complete form data.
     * @return array API-ready data.
     */
    public function prepareForApi(array $form_data): array
    {
        // Format phone numbers for API
        $phone = preg_replace('/\D/', '', $form_data['phone'] ?? '');
        $alt_phone = preg_replace('/\D/', '', $form_data['alt_phone'] ?? '');

        return [
            'first_name' => $form_data['first_name'] ?? '',
            'last_name' => $form_data['last_name'] ?? '',
            'email' => $form_data['email'] ?? '',
            'phone_no' => $phone,
            'phone_type' => $this->mapPhoneType($form_data['phone_type'] ?? 'mobile'),
            'alt_phone_no' => $alt_phone,
            'alt_phone_type' => $this->mapPhoneType($form_data['alt_phone_type'] ?? 'home'),
            'street' => $form_data['street'] ?? '',
            'city' => $form_data['city'] ?? '',
            'state' => $form_data['state'] ?? '',
            'zip' => $form_data['zip_confirm'] ?? $form_data['zip'] ?? '',
            'special_instructions' => $form_data['special_instructions'] ?? '',
            'promo_code' => $form_data['promo_code'] ?? '',
            'device_type' => $form_data['device_type'] ?? '',
            'schedule_date' => $form_data['schedule_date'] ?? '',
            'schedule_time' => $form_data['schedule_time'] ?? '',
            'utility_no' => $form_data['utility_no'] ?? '',
            'account_number' => $form_data['account_number'] ?? $form_data['utility_no'] ?? '',
        ];
    }

    /**
     * Map phone type to API code
     *
     * @param string $type Phone type.
     * @return string API code.
     */
    private function mapPhoneType(string $type): string
    {
        $map = [
            'mobile' => 'C',
            'home' => 'H',
            'work' => 'W',
        ];

        return $map[$type] ?? 'H';
    }

    /**
     * Get equipment codes for device type
     *
     * @param string $device_type Device type (thermostat or dcu).
     * @return array Equipment codes.
     */
    public function getEquipmentCodes(string $device_type): array
    {
        if ($device_type === 'thermostat') {
            // Smart thermostat codes
            return ['05', '10', '15', '20'];
        }

        // DCU (outdoor switch)
        return ['01'];
    }

    /**
     * Generate confirmation number
     *
     * @return string
     */
    public function generateConfirmationNumber(): string
    {
        return strtoupper('EWR-' . date('Ymd') . '-' . substr(md5(uniqid(mt_rand(), true)), 0, 6));
    }
}

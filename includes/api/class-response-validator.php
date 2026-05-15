<?php
namespace ISF\Api;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Response Validator
 *
 * Validates API responses against expected schemas to prevent
 * processing of malformed or unexpected data.
 */


class ResponseValidator {

    /**
     * Validation errors from last validation
     */
    private static array $errors = [];

    /**
     * Validate a validation response
     *
     * @param array $response The parsed response array
     * @return bool True if valid
     */
    public static function validate_validation_response(array $response): bool {
        self::$errors = [];

        // Must have message wrapper
        if (!isset($response['message'])) {
            self::$errors[] = 'Missing root message element';
            return false;
        }

        $message = $response['message'];

        // Check for required fields (at least one identifier)
        $has_status_indicator = isset($message['status']) ||
                                isset($message['enroll-status']) ||
                                isset($message['messagetype']);

        if (!$has_status_indicator) {
            self::$errors[] = 'Missing status indicator in response';
            return false;
        }

        // Validate string fields don't contain dangerous content
        $string_fields = ['status', 'messagetype', 'caNo', 'fname', 'lname', 'email'];
        foreach ($string_fields as $field) {
            if (isset($message[$field])) {
                $value = self::extract_value($message[$field]);
                if (!self::is_safe_string($value)) {
                    self::$errors[] = "Unsafe content detected in field: {$field}";
                    return false;
                }
            }
        }

        // Validate address structure if present
        if (isset($message['address'])) {
            if (!self::validate_address_structure($message['address'])) {
                return false;
            }
        }

        // Validate error-detail structure if present
        if (isset($message['error-detail'])) {
            if (!self::validate_error_detail($message['error-detail'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a scheduling response
     *
     * @param array $response The parsed response array
     * @return bool True if valid
     */
    public static function validate_scheduling_response(array $response): bool {
        self::$errors = [];

        // Must have message wrapper
        if (!isset($response['message'])) {
            self::$errors[] = 'Missing root message element';
            return false;
        }

        $message = $response['message'];

        // Validate string fields
        $string_fields = ['messagetype', 'scheduled', 'fsrno', 'comvergeno', 'email', 'fname', 'lname'];
        foreach ($string_fields as $field) {
            if (isset($message[$field])) {
                $value = self::extract_value($message[$field]);
                if (!self::is_safe_string($value)) {
                    self::$errors[] = "Unsafe content detected in field: {$field}";
                    return false;
                }
            }
        }

        // Validate address structure if present
        if (isset($message['address'])) {
            if (!self::validate_address_structure($message['address'])) {
                return false;
            }
        }

        // Validate equipment structure if present
        if (isset($message['equipments']['equipment'])) {
            if (!self::validate_equipment_structure($message['equipments']['equipment'])) {
                return false;
            }
        }

        // Validate openslots structure if present
        if (isset($message['openslots'])) {
            if (!self::validate_slots_structure($message['openslots'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a booking response
     *
     * @param array $response The parsed response array
     * @return bool True if valid
     */
    public static function validate_booking_response(array $response): bool {
        self::$errors = [];

        // Must have message wrapper
        if (!isset($response['message'])) {
            self::$errors[] = 'Missing root message element';
            return false;
        }

        $message = $response['message'];

        // Check for required booking indicators
        $has_indicator = isset($message['status']) ||
                         isset($message['confirmation']) ||
                         isset($message['error']);

        if (!$has_indicator) {
            self::$errors[] = 'Missing booking status indicator';
            return false;
        }

        // Validate string fields
        $string_fields = ['status', 'confirmation', 'message'];
        foreach ($string_fields as $field) {
            if (isset($message[$field])) {
                $value = self::extract_value($message[$field]);
                if (!self::is_safe_string($value)) {
                    self::$errors[] = "Unsafe content detected in field: {$field}";
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validate address structure
     */
    private static function validate_address_structure(array $address): bool {
        $fields = ['street', 'city', 'state', 'zip'];
        foreach ($fields as $field) {
            if (isset($address[$field])) {
                $value = self::extract_value($address[$field]);
                if (!self::is_safe_string($value)) {
                    self::$errors[] = "Unsafe content in address.{$field}";
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Validate error detail structure
     */
    private static function validate_error_detail(array $error_detail): bool {
        // Error code must be alphanumeric if present
        if (isset($error_detail['error']['attr']['code'])) {
            $code = $error_detail['error']['attr']['code'];
            if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $code)) {
                self::$errors[] = 'Invalid error code format';
                return false;
            }
        }
        return true;
    }

    /**
     * Validate equipment structure
     */
    private static function validate_equipment_structure($equipment): bool {
        // Handle single vs array
        if (isset($equipment['attr'])) {
            $equipment = [$equipment];
        }

        foreach ($equipment as $item) {
            if (!isset($item['attr'])) {
                continue;
            }

            // Type must be numeric
            if (isset($item['attr']['type']) && !is_numeric($item['attr']['type'])) {
                self::$errors[] = 'Invalid equipment type';
                return false;
            }

            // Location must be numeric
            if (isset($item['attr']['location']) && !is_numeric($item['attr']['location'])) {
                self::$errors[] = 'Invalid equipment location';
                return false;
            }
        }
        return true;
    }

    /**
     * Validate slots structure
     */
    private static function validate_slots_structure(array $openslots): bool {
        // Check for noslots indicator - this is valid
        if (isset($openslots['noslots'])) {
            return true;
        }

        if (!isset($openslots['slot'])) {
            return true; // Empty is valid
        }

        $slots = $openslots['slot'];
        if (isset($slots['attr'])) {
            $slots = [$slots];
        }

        foreach ($slots as $slot) {
            // Date must be in valid format
            if (isset($slot['attr']['date'])) {
                $date = $slot['attr']['date'];
                if (!preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $date)) {
                    self::$errors[] = 'Invalid slot date format';
                    return false;
                }
            }

            // Validate time values
            if (isset($slot['time'])) {
                $times = $slot['time'];
                if (isset($times['attr'])) {
                    $times = [$times];
                }

                foreach ($times as $time) {
                    if (isset($time['attr']['value']) && !is_numeric($time['attr']['value'])) {
                        self::$errors[] = 'Invalid time slot value';
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * Extract value from potentially nested node
     */
    private static function extract_value($node): string {
        if (is_string($node)) {
            return $node;
        }
        if (is_array($node) && isset($node['value'])) {
            return (string) $node['value'];
        }
        if (is_array($node) && isset($node[0])) {
            return (string) $node[0];
        }
        return '';
    }

    /**
     * Check if string is safe (no script injection attempts)
     */
    private static function is_safe_string(string $value): bool {
        // Check for common XSS patterns
        $dangerous_patterns = [
            '/<script/i',
            '/javascript:/i',
            '/on\w+\s*=/i', // onclick=, onload=, etc.
            '/<iframe/i',
            '/<object/i',
            '/<embed/i',
            '/data:/i',
            '/vbscript:/i',
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return false;
            }
        }

        // Check for excessively long values (potential DoS)
        if (strlen($value) > 10000) {
            return false;
        }

        return true;
    }

    /**
     * Get validation errors from last validation
     *
     * @return array Array of error messages
     */
    public static function get_errors(): array {
        return self::$errors;
    }

    /**
     * Get last error as string
     *
     * @return string First error or empty string
     */
    public static function get_last_error(): string {
        return self::$errors[0] ?? '';
    }
}

<?php
namespace ISF\Api;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Validation Result
 *
 * Parses and provides access to account validation API response data.
 * Based on the legacy ValidateAccountResult class from validateXml.php
 */


class ValidationResult {

    private array $root_node = [];
    private bool $is_valid = false;
    private string $error_message = '';
    private string $error_code = '';
    private bool $is_already_enrolled = false;
    private bool $has_medical_condition = false;
    private bool $requires_medical_acknowledgment = false;

    /**
     * Constructor
     *
     * @param array $response Parsed XML response array
     */
    public function __construct(array $response) {
        if (isset($response['message'])) {
            $this->root_node = $response['message'];
            $this->parse_response();
        } else {
            $this->error_message = 'Unexpected response format';
        }
    }

    /**
     * Parse the response to determine validity
     */
    private function parse_response(): void {
        $message_type = $this->get_message_type();
        $status = $this->get_status();
        $enroll_status = $this->get_enroll_status();
        $this->error_code = $this->get_error_code();
        $part_type = $this->get_part_type();

        // Check for successful validation first
        // Valid if: enroll-status is "01" (eligible) and no blocking errors
        if ($enroll_status === '01') {
            $this->is_valid = true;
        } elseif (strtolower($status) === 'valid' || strtolower($message_type) === 'prospect') {
            $this->is_valid = true;
        }

        // Check for already enrolled (enroll-status = "02" or error code "03")
        if ($enroll_status === '02' || $this->error_code === '03') {
            $this->is_already_enrolled = true;
            $this->is_valid = false;
            $this->error_message = __("We're sorry, the customer information you entered has already been enrolled. If you would like to schedule an appointment, please use the scheduling form.", 'formflow');
            return;
        }

        // Check for medical condition (error code "21")
        // This is a special case - enrollment CAN proceed but user must acknowledge
        if ($this->error_code === '21' || ($status === '-1' && $this->error_code === '21')) {
            $this->has_medical_condition = true;
            $this->requires_medical_acknowledgment = true;
            // Still mark as valid but frontend will handle the acknowledgment
            $this->is_valid = true;
            return;
        }

        // Check for error response (enroll-status = "-1")
        if ($enroll_status === '-1' && !empty($this->error_code)) {
            $this->is_valid = false;
            $this->error_message = $this->map_error_code($this->error_code);
            return;
        }

        // Check participant type - must be "01" for residential
        if (!empty($part_type) && $part_type !== '01') {
            $this->is_valid = false;
            $this->error_message = __('Our records indicate that the Account Number you entered is for a Corporate Account. Please contact customer service for business enrollment.', 'formflow');
            return;
        }

        // Process other enroll statuses
        if (!empty($enroll_status)) {
            $this->process_enroll_status($enroll_status);
        }
    }

    /**
     * Check if the validation was successful
     *
     * @return bool
     */
    public function is_valid(): bool {
        return $this->is_valid;
    }

    /**
     * Get error message if validation failed
     *
     * @return string
     */
    public function get_error_message(): string {
        return $this->error_message;
    }

    /**
     * Get the message type from response
     *
     * @return string
     */
    public function get_message_type(): string {
        return XmlParser::node_value($this->root_node['messagetype'] ?? null, '');
    }

    /**
     * Get the status from response
     *
     * @return string
     */
    public function get_status(): string {
        return XmlParser::node_value($this->root_node['status'] ?? null, '');
    }

    /**
     * Get the enrollment status
     *
     * @return string
     */
    public function get_enroll_status(): string {
        return XmlParser::node_value($this->root_node['enroll-status'] ?? null, '');
    }

    /**
     * Check if there is an enrollment status
     *
     * @return bool
     */
    public function has_enroll_status(): bool {
        return XmlParser::node_has_value($this->root_node['enroll-status'] ?? null);
    }

    /**
     * Get the CA (Customer Account) number
     *
     * @return string
     */
    public function get_ca_no(): string {
        return XmlParser::node_value($this->root_node['caNo'] ?? null, '');
    }

    /**
     * Get the participant type
     *
     * @return string
     */
    public function get_part_type(): string {
        return XmlParser::node_value($this->root_node['partType'] ?? null, '');
    }

    /**
     * Get error code from error details
     *
     * @return string
     */
    public function get_error_code(): string {
        $error_detail = $this->root_node['error-detail'] ?? null;
        if (isset($error_detail['error']['attr']['code'])) {
            return $error_detail['error']['attr']['code'];
        }
        return '';
    }

    /**
     * Get the Comverge account number
     *
     * @return string
     */
    public function get_comverge_no(): string {
        return XmlParser::node_value($this->root_node['comvergeno'] ?? null, '');
    }

    /**
     * Get customer first name
     *
     * @return string
     */
    public function get_first_name(): string {
        return XmlParser::node_value($this->root_node['fname'] ?? null, '');
    }

    /**
     * Get customer last name
     *
     * @return string
     */
    public function get_last_name(): string {
        return XmlParser::node_value($this->root_node['lname'] ?? null, '');
    }

    /**
     * Get customer email
     *
     * @return string
     */
    public function get_email(): string {
        return XmlParser::node_value($this->root_node['email'] ?? null, '');
    }

    /**
     * Get service address
     *
     * @return array Address components
     */
    public function get_address(): array {
        $address = $this->root_node['address'] ?? [];
        return [
            'street' => XmlParser::node_value($address['street'] ?? null, ''),
            'city' => XmlParser::node_value($address['city'] ?? null, ''),
            'state' => XmlParser::node_value($address['state'] ?? null, ''),
            'zip' => XmlParser::node_value($address['zip'] ?? null, '')
        ];
    }

    /**
     * Get formatted address string
     *
     * @return string
     */
    public function get_formatted_address(): string {
        $addr = $this->get_address();
        if (empty($addr['street'])) {
            return '';
        }

        return sprintf(
            '%s, %s, %s %s',
            $addr['street'],
            $addr['city'],
            $addr['state'],
            $addr['zip']
        );
    }

    /**
     * Map API error codes to user-friendly messages
     *
     * @param string $code Error code
     * @return string User message
     */
    private function map_error_code(string $code): string {
        $messages = [
            '001' => __('Account not found. Please verify your account number and try again.', 'formflow'),
            '002' => __('ZIP code does not match account records.', 'formflow'),
            '003' => __('Account is not eligible for this program.', 'formflow'),
            '004' => __('Account is already enrolled in this program.', 'formflow'),
            '005' => __('Account has been suspended. Please contact customer service.', 'formflow'),
            '006' => __('Invalid account type for this program.', 'formflow'),
        ];

        return $messages[$code] ?? sprintf(
            __('Validation error (Code: %s). Please try again or contact customer service.', 'formflow'),
            $code
        );
    }

    /**
     * Process enrollment status and set appropriate flags/messages
     *
     * @param string $status The enrollment status
     */
    private function process_enroll_status(string $status): void {
        $status = strtoupper($status);

        switch ($status) {
            case 'A': // Already enrolled
                $this->error_message = __('This account is already enrolled in the program.', 'formflow');
                $this->is_valid = false;
                break;

            case 'P': // Pending
                $this->error_message = __('This account has a pending enrollment. Please wait for confirmation or contact customer service.', 'formflow');
                $this->is_valid = false;
                break;

            case 'S': // Suspended
                $this->error_message = __('This account enrollment has been suspended. Please contact customer service.', 'formflow');
                $this->is_valid = false;
                break;

            case 'N': // Not enrolled - OK to proceed
            case 'E': // Eligible
                // Keep valid status
                break;
        }
    }

    /**
     * Check if account is already enrolled
     *
     * @return bool
     */
    public function is_already_enrolled(): bool {
        return $this->is_already_enrolled;
    }

    /**
     * Check if account has medical condition flag
     *
     * @return bool
     */
    public function has_medical_condition(): bool {
        return $this->has_medical_condition;
    }

    /**
     * Check if medical acknowledgment is required to proceed
     *
     * @return bool
     */
    public function requires_medical_acknowledgment(): bool {
        return $this->requires_medical_acknowledgment;
    }

    /**
     * Get all response data as array
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'is_valid' => $this->is_valid,
            'error_message' => $this->error_message,
            'message_type' => $this->get_message_type(),
            'status' => $this->get_status(),
            'enroll_status' => $this->get_enroll_status(),
            'ca_no' => $this->get_ca_no(),
            'comverge_no' => $this->get_comverge_no(),
            'part_type' => $this->get_part_type(),
            'first_name' => $this->get_first_name(),
            'last_name' => $this->get_last_name(),
            'email' => $this->get_email(),
            'address' => $this->get_address(),
            'error_code' => $this->error_code,
            'is_already_enrolled' => $this->is_already_enrolled,
            'has_medical_condition' => $this->has_medical_condition,
            'requires_medical_acknowledgment' => $this->requires_medical_acknowledgment
        ];
    }
}

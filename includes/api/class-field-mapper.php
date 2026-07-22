<?php
namespace ISF\Api;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * API Field Mapper
 *
 * Maps internal form field names to PowerPortal IntelliSOURCE API parameter names.
 * This ensures data is sent with the exact field names the API expects.
 */


class FieldMapper {

    /**
     * Contract type codes based on cycling level and device type
     * Format: 'level%-device' => 'code'
     */
    private const CONTRACT_CODES = [
        '50%-Pro-VHF' => '01',
        '50%-Pro-Z' => '02',
        '50%-IT900' => '03',
        '50%-DCU' => '04',
        '75%-Pro-VHF' => '05',
        '75%-Pro-Z' => '06',
        '75%-IT900' => '07',
        '75%-DCU' => '08',
        '100%-Pro-VHF' => '09',
        '100%-Pro-Z' => '10',
        '100%-IT900' => '11',
        '100%-DCU' => '12',
        '50%-Business-DCU' => '13',
        '50%-Business-IT900' => '14',
        '50%-Business-Pro-Z' => '15',
        '50%-MMA-IT900' => '16',
        '75%-MMA-IT900' => '17',
        '100%-MMA-IT900' => '18',
        '50%-MMA-DCU' => '19',
        '75%-MMA-DCU' => '20',
        '100%-MMA-DCU' => '21',
    ];

    /**
     * Promo codes to include in the dropdown
     */
    private const PROMO_INCLUDE_LIST = [
        'WEB', 'RADIO', 'BLOG', 'BROCHURE', 'BUS', 'STOP', 'EVENT',
        'FACEBOOK', 'FRIEND', 'INSTALLER', 'NEWSPAPER', 'OTHER'
    ];

    /**
     * Map of internal field names to API parameter names for enrollment
     */
    private const ENROLLMENT_FIELD_MAP = [
        // Account Information
        'utility_no'        => 'utility_no',
        'account_number'    => 'utility_no',
        'cycling_level'     => 'level',

        // Personal Information
        'first_name'        => 'fname',
        'last_name'         => 'lname',

        // Address
        'street'            => 'address',
        'street2'           => 'address2',
        'city'              => 'city',
        'state'             => 'state',
        'zip'               => 'zip',
        'zip_confirm'       => 'zip',

        // Contact Information
        'phone'             => 'dayPhone',
        'alt_phone'         => 'evePhone',
        'email'             => 'email',

        // Property Information
        'ownership'         => 'ownsPrem',
        'thermostat_count'  => 'eqCount-15',

        // Additional
        'promo_code'        => 'pCode',

        // DCU-specific
        'easy_access'       => 'easyAccess',
        'install_time'      => 'installTime',
    ];

    /**
     * Map of internal field names to API parameter names for scheduling
     */
    private const SCHEDULING_FIELD_MAP = [
        'account_number'    => 'caNo',
        'utility_no'        => 'utility_no',
        'schedule_date'     => 'schedule_date',
        'schedule_time'     => 'time',
    ];

    /**
     * Required fields for enrollment API call
     */
    private const REQUIRED_ENROLLMENT_FIELDS = [
        'utility_no',
        'level',
        'fname',
        'lname',
        'zip',
        'dayPhone',
        'email',
        'ownsPrem',
        'eqCount-15',
    ];

    /**
     * Required fields for scheduling API call
     */
    private const REQUIRED_SCHEDULING_FIELDS = [
        'schedule_date',
        'time',
    ];

    /**
     * Transform form data to API parameters for enrollment
     *
     * @param array $form_data The collected form data
     * @return array Mapped API parameters
     * @throws FieldMappingException If required fields are missing
     */
    public static function mapEnrollmentData(array $form_data): array {
        $api_params = [];
        $missing_fields = [];

        // Map each field using the enrollment map
        foreach (self::ENROLLMENT_FIELD_MAP as $internal_name => $api_name) {
            if (isset($form_data[$internal_name]) && $form_data[$internal_name] !== '') {
                // Don't overwrite if already set (handles multiple internal names mapping to same API name)
                if (!isset($api_params[$api_name]) || $api_params[$api_name] === '') {
                    $api_params[$api_name] = self::transformValue($internal_name, $form_data[$internal_name]);
                }
            }
        }

        // Handle Comverge account number (starts with X)
        $account = $form_data['utility_no'] ?? $form_data['account_number'] ?? '';
        if (strtolower(substr($account, 0, 1)) === 'x') {
            unset($api_params['utility_no']);
            $api_params['caNo'] = substr($account, 1);
        }

        // Set partType (01 = residential)
        $api_params['partType'] = '01';

        // Calculate contract code based on cycling level and device type
        $level = $form_data['cycling_level'] ?? '100';
        $device_type = $form_data['device_type'] ?? 'thermostat';
        $api_params['contract'] = self::getContractCode($level, $device_type);

        // Handle ownership - convert to API codes
        $ownership = strtolower($form_data['ownership'] ?? 'own');
        $api_params['ownsPrem'] = ($ownership === 'lease' || $ownership === 'rent') ? '02' : '01';

        // Handle landlord info when renting
        if ($api_params['ownsPrem'] === '02') {
            // Build landlord name from customer name (legacy behavior)
            $fname = str_replace(' ', '', $form_data['first_name'] ?? '');
            $lname = str_replace(' ', '', $form_data['last_name'] ?? '');
            $api_params['llordName'] = 'Landlord,' . $fname . $lname;
            $api_params['llordPhone'] = '1234567890'; // Required placeholder
            $api_params['llordAuth'] = '02';
        } else {
            $api_params['llordName'] = '';
            $api_params['llordPhone'] = '';
            $api_params['llordAuth'] = '02';
        }

        // Phone - strip non-numeric
        if (isset($api_params['dayPhone'])) {
            $api_params['dayPhone'] = preg_replace('/\D/', '', $api_params['dayPhone']);
        }
        if (isset($api_params['evePhone'])) {
            $api_params['evePhone'] = preg_replace('/\D/', '', $api_params['evePhone']);
        }
        $api_params['dayPhoneExt'] = ''; // Required field, can be empty

        // Equipment location and count
        $api_params['eqLoc-15'] = '05'; // 05 = Interior
        if (!isset($api_params['eqCount-15'])) {
            $api_params['eqCount-15'] = $form_data['thermostat_count'] ?? '1';
        }

        // Desired device based on type
        if ($device_type === 'dcu') {
            $api_params['dd-15'] = '02'; // DCU
            // Determine if scheduling is required for DCU
            $easy_access = $form_data['easy_access'] ?? 'Yes';
            $install_time = $form_data['install_time'] ?? 'Anytime';
            $api_params['mustSchedule'] = ($easy_access === 'No' || $install_time === 'Appointment') ? 'Y' : 'N';
        } else {
            $api_params['dd-15'] = '03'; // Thermostat (Pro-VHF)
        }

        // Additional required fields
        $api_params['overrideFlag'] = '01';
        $api_params['noStories'] = '0';
        $api_params['gated'] = 'No';
        $api_params['mktSrc'] = 'WEB';
        $api_params['val'] = 'val';

        // Validate required fields
        foreach (self::REQUIRED_ENROLLMENT_FIELDS as $required_field) {
            // Check both direct field and caNo alternative for utility_no
            if ($required_field === 'utility_no') {
                if ((!isset($api_params['utility_no']) || $api_params['utility_no'] === '') &&
                    (!isset($api_params['caNo']) || $api_params['caNo'] === '')) {
                    $missing_fields[] = 'accountnumber';
                }
            } elseif (!isset($api_params[$required_field]) || $api_params[$required_field] === '') {
                $missing_fields[] = $required_field;
            }
        }

        if (!empty($missing_fields)) {
            throw new FieldMappingException(
                'Missing required fields for enrollment: ' . implode(', ', $missing_fields),
                $missing_fields
            );
        }

        return $api_params;
    }

    /**
     * Get the contract code based on cycling level and device type
     */
    public static function getContractCode(string $level, string $device_type): string {
        // Build contract key
        if ($device_type === 'dcu') {
            $key = $level . '%-DCU';
        } else {
            // Default to Pro-VHF for thermostats
            $key = $level . '%-Pro-VHF';
        }

        if (!isset(self::CONTRACT_CODES[$key])) {
            // Never silently fall back to '09' (100%-Pro-VHF). An unmapped
            // level/device combination is invalid input, and '09' is MAXIMUM
            // load-control cycling — silently enrolling a customer to have
            // their equipment curtailed 100% of the time is a real harm. Fail
            // loud so the bad data surfaces instead of shipping a wrong,
            // maximally-aggressive contract to IntelliSource.
            throw new FieldMappingException(
                sprintf('Unsupported cycling level / device combination: "%s".', $key),
                ['cycling_level']
            );
        }

        return self::CONTRACT_CODES[$key];
    }

    /**
     * Transform form data to API parameters for scheduling
     *
     * @param array $form_data The collected form data
     * @return array Mapped API parameters
     * @throws FieldMappingException If required fields are missing
     */
    public static function mapSchedulingData(array $form_data): array {
        $api_params = [];
        $missing_fields = [];

        // Map each field using the scheduling map
        foreach (self::SCHEDULING_FIELD_MAP as $internal_name => $api_name) {
            if (isset($form_data[$internal_name]) && $form_data[$internal_name] !== '') {
                $api_params[$api_name] = self::transformValue($internal_name, $form_data[$internal_name]);
            }
        }

        // Handle account number - use caNo if starts with X, otherwise utility_no
        if (isset($form_data['account_number'])) {
            $account = $form_data['account_number'];
            if (strtolower(substr($account, 0, 1)) === 'x') {
                $api_params['caNo'] = substr($account, 1);
            } else {
                $api_params['utility_no'] = $account;
            }
        }

        // Validate required fields
        foreach (self::REQUIRED_SCHEDULING_FIELDS as $required_field) {
            if (!isset($api_params[$required_field]) || $api_params[$required_field] === '') {
                $missing_fields[] = $required_field;
            }
        }

        if (!empty($missing_fields)) {
            throw new FieldMappingException(
                'Missing required fields for scheduling: ' . implode(', ', $missing_fields),
                $missing_fields
            );
        }

        return $api_params;
    }

    /**
     * Transform a value based on field type
     */
    private static function transformValue(string $field_name, mixed $value): mixed {
        switch ($field_name) {
            case 'cycling_level':
                return (string) $value;

            case 'state':
                return strtoupper($value);

            case 'phone':
            case 'alt_phone':
                return preg_replace('/\D/', '', $value);

            case 'email':
                return strtolower(trim($value));

            case 'zip':
            case 'zip_confirm':
                return preg_replace('/[^0-9\-]/', '', $value);

            case 'thermostat_count':
                return (string) max(1, (int) $value);

            default:
                return is_string($value) ? trim($value) : $value;
        }
    }

    /**
     * Filter promo codes from API response
     * Only include codes in the include list or starting with specific prefixes
     */
    public static function filterPromoCodes(array $promo_codes, string $utility_prefix = 'DE'): array {
        $filtered = [];

        foreach ($promo_codes as $code) {
            $code = trim($code);
            if (empty($code)) continue;

            // Include if in the standard include list
            if (in_array(strtoupper($code), self::PROMO_INCLUDE_LIST)) {
                $filtered[] = $code;
                continue;
            }

            // Include if starts with utility prefix (e.g., 'DEC' for Delmarva commercial codes)
            if (strtoupper(substr($code, 0, strlen($utility_prefix) + 1)) === $utility_prefix . 'C') {
                $filtered[] = $code;
            }
        }

        return $filtered;
    }

    /**
     * Validate Pepco vs Delmarva account number
     * Pepco accounts are exactly 10 digits, Delmarva are different lengths
     */
    public static function validateAccountNumberForUtility(string $account_number, string $utility): array {
        $account = preg_replace('/\D/', '', $account_number);
        $errors = [];

        // Check for Pepco account in Delmarva form
        if (strpos(strtolower($utility), 'delmarva') !== false) {
            if (strlen($account) === 10) {
                $errors[] = [
                    'code' => 'pepco_account',
                    'message' => "We're sorry, but the account number you are trying to enter is a Pepco account number. To enroll in Pepco Energy Wise Rewards, please visit the Pepco enrollment page."
                ];
            }
        }

        // Check for Delmarva account in Pepco form
        if (strpos(strtolower($utility), 'pepco') !== false) {
            if (strlen($account) !== 10) {
                $errors[] = [
                    'code' => 'delmarva_account',
                    'message' => "We're sorry, but the account number you are trying to enter appears to be a Delmarva Power account number. To enroll in Delmarva Energy Wise Rewards, please visit the Delmarva enrollment page."
                ];
            }
        }

        return $errors;
    }

    /**
     * Get a human-readable field label for an API field name
     */
    public static function getFieldLabel(string $api_field): string {
        $labels = [
            'accountnumber' => 'Account Number',
            'utility_no' => 'Account Number',
            'level' => 'Participation Level',
            'fname' => 'First Name',
            'lname' => 'Last Name',
            'address' => 'Street Address',
            'address2' => 'Address Line 2',
            'city' => 'City',
            'state' => 'State',
            'zip' => 'ZIP Code',
            'dayPhone' => 'Primary Phone',
            'evePhone' => 'Secondary Phone',
            'email' => 'Email Address',
            'ownsPrem' => 'Lease or Own',
            'eqCount-15' => 'Number of Thermostats/Units',
            'pCode' => 'Promo Code',
            'schedule_date' => 'Appointment Date',
            'time' => 'Appointment Time',
            'contract' => 'Contract Type',
        ];

        return $labels[$api_field] ?? ucwords(str_replace(['_', '-'], ' ', $api_field));
    }

    /**
     * Get a list of all mapped fields for debugging/documentation
     */
    public static function getFieldMappingInfo(): array {
        return [
            'enrollment' => [
                'map' => self::ENROLLMENT_FIELD_MAP,
                'required' => self::REQUIRED_ENROLLMENT_FIELDS,
                'contracts' => self::CONTRACT_CODES,
            ],
            'scheduling' => [
                'map' => self::SCHEDULING_FIELD_MAP,
                'required' => self::REQUIRED_SCHEDULING_FIELDS,
            ],
        ];
    }

    /**
     * Validate that form data has all required fields before submission
     */
    public static function validateRequiredFields(array $form_data, string $type = 'enrollment'): array {
        $missing = [];

        try {
            if ($type === 'enrollment') {
                self::mapEnrollmentData($form_data);
            } else {
                self::mapSchedulingData($form_data);
            }
        } catch (FieldMappingException $e) {
            foreach ($e->getMissingFields() as $field) {
                $missing[] = self::getFieldLabel($field);
            }
        }

        return $missing;
    }
}

/**
 * Exception for field mapping errors
 */
class FieldMappingException extends \Exception {
    private array $missing_fields;

    public function __construct(string $message, array $missing_fields = []) {
        parent::__construct($message);
        $this->missing_fields = $missing_fields;
    }

    public function getMissingFields(): array {
        return $this->missing_fields;
    }
}

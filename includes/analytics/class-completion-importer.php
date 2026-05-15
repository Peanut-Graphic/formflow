<?php
namespace ISF\Analytics;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Completion Importer
 *
 * Handles CSV import of external completions with field mapping.
 * Attempts to match imported completions to existing handoffs/visitors.
 */


use ISF\Database\Database;

class CompletionImporter {

    /**
     * Database instance
     */
    private Database $db;

    /**
     * Required fields for import
     */
    private const REQUIRED_FIELDS = ['account_number'];

    /**
     * Optional fields that can be mapped
     */
    private const OPTIONAL_FIELDS = [
        'customer_email',
        'external_id',
        'completion_type',
        'completion_date',
        'handoff_token',
        'first_name',
        'last_name',
        'phone',
        'address',
        'city',
        'state',
        'zip',
    ];

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database();
    }

    /**
     * Get available fields for mapping
     *
     * @return array
     */
    public function get_available_fields(): array {
        return [
            'required' => self::REQUIRED_FIELDS,
            'optional' => self::OPTIONAL_FIELDS,
        ];
    }

    /**
     * Parse CSV file and return headers and preview rows
     *
     * @param string $file_path Path to uploaded CSV file
     * @param int $preview_rows Number of rows to preview
     * @return array|WP_Error
     */
    public function parse_csv_preview(string $file_path, int $preview_rows = 5) {
        if (!file_exists($file_path)) {
            return new \WP_Error('file_not_found', __('CSV file not found.', 'formflow'));
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return new \WP_Error('file_open_error', __('Could not open CSV file.', 'formflow'));
        }

        // Detect delimiter
        $first_line = fgets($handle);
        rewind($handle);
        $delimiter = $this->detect_delimiter($first_line);

        // Get headers
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            return new \WP_Error('no_headers', __('Could not read CSV headers.', 'formflow'));
        }

        // Clean headers
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);

        // Get preview rows
        $rows = [];
        $count = 0;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false && $count < $preview_rows) {
            $rows[] = $row;
            $count++;
        }

        // Count total rows
        $total_rows = $count;
        while (fgetcsv($handle, 0, $delimiter) !== false) {
            $total_rows++;
        }

        fclose($handle);

        return [
            'headers' => $headers,
            'preview' => $rows,
            'total_rows' => $total_rows,
            'delimiter' => $delimiter,
        ];
    }

    /**
     * Detect CSV delimiter
     *
     * @param string $line First line of CSV
     * @return string
     */
    private function detect_delimiter(string $line): string {
        $delimiters = [',', ';', "\t", '|'];
        $counts = [];

        foreach ($delimiters as $delimiter) {
            $counts[$delimiter] = substr_count($line, $delimiter);
        }

        return array_search(max($counts), $counts);
    }

    /**
     * Auto-map CSV headers to fields
     *
     * @param array $headers CSV headers
     * @return array Suggested mapping
     */
    public function auto_map_headers(array $headers): array {
        $mapping = [];
        $all_fields = array_merge(self::REQUIRED_FIELDS, self::OPTIONAL_FIELDS);

        // Common variations of field names
        $aliases = [
            'account_number' => ['account', 'account_num', 'acct', 'acct_number', 'account_no', 'accountnumber'],
            'customer_email' => ['email', 'e-mail', 'emailaddress', 'email_address', 'customer_email'],
            'external_id' => ['id', 'external_id', 'externalid', 'ext_id', 'reference', 'ref'],
            'completion_type' => ['type', 'completion_type', 'enrollment_type', 'program'],
            'completion_date' => ['date', 'completion_date', 'completed', 'enrolled_date', 'enrollment_date', 'created'],
            'handoff_token' => ['token', 'handoff_token', 'tracking_token', 'isf_token'],
            'first_name' => ['first_name', 'firstname', 'first', 'fname'],
            'last_name' => ['last_name', 'lastname', 'last', 'lname', 'surname'],
            'phone' => ['phone', 'phone_number', 'telephone', 'mobile', 'cell'],
            'address' => ['address', 'street', 'street_address', 'address1', 'address_1'],
            'city' => ['city', 'town'],
            'state' => ['state', 'province', 'region'],
            'zip' => ['zip', 'zipcode', 'zip_code', 'postal', 'postal_code', 'postcode'],
        ];

        foreach ($headers as $index => $header) {
            $header_lower = strtolower(trim($header));
            $header_clean = preg_replace('/[^a-z0-9]/', '', $header_lower);

            foreach ($all_fields as $field) {
                // Exact match
                if ($header_lower === $field || $header_clean === str_replace('_', '', $field)) {
                    $mapping[$index] = $field;
                    break;
                }

                // Check aliases
                if (isset($aliases[$field])) {
                    foreach ($aliases[$field] as $alias) {
                        if ($header_lower === $alias || $header_clean === str_replace('_', '', $alias)) {
                            $mapping[$index] = $field;
                            break 2;
                        }
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Import completions from CSV
     *
     * @param string $file_path Path to CSV file
     * @param array $field_mapping Map of column index => field name
     * @param int $instance_id Form instance ID
     * @param array $options Import options
     * @return array Import results
     */
    public function import_csv(
        string $file_path,
        array $field_mapping,
        int $instance_id,
        array $options = []
    ): array {
        global $wpdb;

        $options = wp_parse_args($options, [
            'skip_first_row' => true,
            'delimiter' => ',',
            'match_handoffs' => true,
            'dry_run' => false,
        ]);

        // Validate required fields are mapped
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!in_array($field, $field_mapping)) {
                return [
                    'success' => false,
                    'error' => sprintf(__('Required field "%s" is not mapped.', 'formflow'), $field),
                ];
            }
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return [
                'success' => false,
                'error' => __('Could not open CSV file.', 'formflow'),
            ];
        }

        // Skip header row if needed
        if ($options['skip_first_row']) {
            fgetcsv($handle, 0, $options['delimiter']);
        }

        $results = [
            'success' => true,
            'imported' => 0,
            'matched' => 0,
            'skipped' => 0,
            'errors' => [],
            'rows_processed' => 0,
        ];

        $table = $wpdb->prefix . ISF_TABLE_EXTERNAL_COMPLETIONS;
        $handoffs_table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        $row_number = $options['skip_first_row'] ? 2 : 1;

        while (($row = fgetcsv($handle, 0, $options['delimiter'])) !== false) {
            $results['rows_processed']++;

            // Map row data to fields
            $data = $this->map_row_to_fields($row, $field_mapping);

            // Validate required fields have values
            $missing = [];
            foreach (self::REQUIRED_FIELDS as $field) {
                if (empty($data[$field])) {
                    $missing[] = $field;
                }
            }

            if (!empty($missing)) {
                $results['skipped']++;
                $results['errors'][] = sprintf(
                    __('Row %d: Missing required fields: %s', 'formflow'),
                    $row_number,
                    implode(', ', $missing)
                );
                $row_number++;
                continue;
            }

            // Check for duplicate by account number
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE instance_id = %d AND account_number = %s",
                $instance_id,
                $data['account_number']
            ));

            if ($existing) {
                $results['skipped']++;
                $results['errors'][] = sprintf(
                    __('Row %d: Duplicate account number: %s', 'formflow'),
                    $row_number,
                    $data['account_number']
                );
                $row_number++;
                continue;
            }

            // Try to match to handoff
            $handoff_id = null;
            if ($options['match_handoffs']) {
                $handoff_id = $this->find_matching_handoff(
                    $instance_id,
                    $data['account_number'],
                    $data['customer_email'] ?? null,
                    $data['handoff_token'] ?? null
                );

                if ($handoff_id) {
                    $results['matched']++;
                }
            }

            if (!$options['dry_run']) {
                // Prepare completion data
                $completion_data = [
                    'instance_id' => $instance_id,
                    'source' => 'import',
                    'handoff_id' => $handoff_id,
                    'account_number' => $data['account_number'],
                    'customer_email' => $data['customer_email'] ?? null,
                    'external_id' => $data['external_id'] ?? null,
                    'completion_type' => $data['completion_type'] ?? 'enrollment',
                    'raw_data' => wp_json_encode($data),
                    'processed' => 1,
                    'created_at' => $data['completion_date'] ?? current_time('mysql'),
                ];

                $inserted = $wpdb->insert($table, $completion_data);

                if ($inserted) {
                    $results['imported']++;

                    // Update handoff status if matched
                    if ($handoff_id) {
                        $wpdb->update(
                            $handoffs_table,
                            [
                                'status' => 'completed',
                                'account_number' => $data['account_number'],
                                'completed_at' => current_time('mysql'),
                            ],
                            ['id' => $handoff_id]
                        );
                    }
                } else {
                    $results['errors'][] = sprintf(
                        __('Row %d: Database error: %s', 'formflow'),
                        $row_number,
                        $wpdb->last_error
                    );
                }
            } else {
                // Dry run - just count
                $results['imported']++;
            }

            $row_number++;
        }

        fclose($handle);

        // Log the import
        if (!$options['dry_run'] && $results['imported'] > 0) {
            $this->db->log_activity(
                'completion_import',
                sprintf(
                    'Imported %d completions (%d matched to handoffs) for instance %d',
                    $results['imported'],
                    $results['matched'],
                    $instance_id
                ),
                [
                    'instance_id' => $instance_id,
                    'imported' => $results['imported'],
                    'matched' => $results['matched'],
                    'skipped' => $results['skipped'],
                ]
            );
        }

        return $results;
    }

    /**
     * Map a CSV row to field names
     *
     * @param array $row CSV row data
     * @param array $mapping Column index => field name mapping
     * @return array
     */
    private function map_row_to_fields(array $row, array $mapping): array {
        $data = [];

        foreach ($mapping as $index => $field) {
            if (isset($row[$index])) {
                $value = trim($row[$index]);

                // Clean up common data issues
                if ($field === 'customer_email') {
                    $value = sanitize_email($value);
                } elseif ($field === 'phone') {
                    $value = preg_replace('/[^0-9+]/', '', $value);
                } elseif ($field === 'completion_date') {
                    $value = $this->parse_date($value);
                }

                $data[$field] = $value;
            }
        }

        return $data;
    }

    /**
     * Parse various date formats
     *
     * @param string $date_string
     * @return string|null MySQL datetime format
     */
    private function parse_date(string $date_string): ?string {
        if (empty($date_string)) {
            return null;
        }

        // Try common formats
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d',
            'm/d/Y H:i:s',
            'm/d/Y',
            'm-d-Y',
            'd/m/Y',
            'Y/m/d',
            'M d, Y',
            'F d, Y',
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $date_string);
            if ($date) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        // Try strtotime as fallback
        $timestamp = strtotime($date_string);
        if ($timestamp) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        return null;
    }

    /**
     * Find a matching handoff record
     *
     * @param int $instance_id
     * @param string $account_number
     * @param string|null $email
     * @param string|null $token
     * @return int|null Handoff ID if found
     */
    private function find_matching_handoff(
        int $instance_id,
        string $account_number,
        ?string $email = null,
        ?string $token = null
    ): ?int {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        // Try token match first (most reliable)
        if ($token) {
            $handoff_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE handoff_token = %s
                 AND status = 'redirected'",
                $token
            ));

            if ($handoff_id) {
                return (int) $handoff_id;
            }
        }

        // Try account number match (within last 30 days)
        $handoff_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE instance_id = %d
             AND account_number = %s
             AND status = 'redirected'
             AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY created_at DESC
             LIMIT 1",
            $instance_id,
            $account_number
        ));

        if ($handoff_id) {
            return (int) $handoff_id;
        }

        // Try visitor matching via email (if we have visitor records with email)
        if ($email) {
            $handoff_id = $wpdb->get_var($wpdb->prepare(
                "SELECT h.id FROM {$table} h
                 JOIN {$wpdb->prefix}" . ISF_TABLE_VISITORS . " v ON h.visitor_id = v.visitor_id
                 WHERE h.instance_id = %d
                 AND h.status = 'redirected'
                 AND JSON_UNQUOTE(JSON_EXTRACT(v.device_info, '$.email')) = %s
                 AND h.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                 ORDER BY h.created_at DESC
                 LIMIT 1",
                $instance_id,
                $email
            ));

            if ($handoff_id) {
                return (int) $handoff_id;
            }
        }

        return null;
    }

    /**
     * Get import history
     *
     * @param int $limit
     * @return array
     */
    public function get_import_history(int $limit = 20): array {
        global $wpdb;
        $logs_table = $wpdb->prefix . 'isf_logs';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$logs_table}
             WHERE action = 'completion_import'
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    /**
     * Get unmatched completions
     *
     * @param int $instance_id
     * @param int $limit
     * @return array
     */
    public function get_unmatched_completions(int $instance_id = 0, int $limit = 100): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_EXTERNAL_COMPLETIONS;

        $where = "handoff_id IS NULL";
        $params = [];

        if ($instance_id) {
            $where .= " AND instance_id = %d";
            $params[] = $instance_id;
        }

        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE {$where}
             ORDER BY created_at DESC
             LIMIT %d",
            ...$params
        ), ARRAY_A);
    }

    /**
     * Retry matching for unmatched completions
     *
     * @param int $instance_id
     * @return array Results
     */
    public function retry_matching(int $instance_id = 0): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_EXTERNAL_COMPLETIONS;
        $handoffs_table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        $unmatched = $this->get_unmatched_completions($instance_id, 1000);

        $results = [
            'processed' => 0,
            'matched' => 0,
        ];

        foreach ($unmatched as $completion) {
            $results['processed']++;

            $data = json_decode($completion['raw_data'], true) ?: [];

            $handoff_id = $this->find_matching_handoff(
                (int) $completion['instance_id'],
                $completion['account_number'],
                $completion['customer_email'],
                $data['handoff_token'] ?? null
            );

            if ($handoff_id) {
                // Update completion with handoff match
                $wpdb->update(
                    $table,
                    ['handoff_id' => $handoff_id],
                    ['id' => $completion['id']]
                );

                // Update handoff status
                $wpdb->update(
                    $handoffs_table,
                    [
                        'status' => 'completed',
                        'account_number' => $completion['account_number'],
                        'completed_at' => $completion['created_at'],
                    ],
                    ['id' => $handoff_id]
                );

                $results['matched']++;
            }
        }

        return $results;
    }
}

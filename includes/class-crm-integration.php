<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * CRM Integration Handler
 *
 * Syncs enrollment data to external CRM systems (Salesforce, HubSpot, custom).
 */


class CRMIntegration {

    /**
     * Supported CRM providers
     */
    private const PROVIDERS = ['salesforce', 'hubspot', 'zoho', 'custom'];

    /**
     * Database instance
     */
    private Database\Database $db;

    /**
     * Encryption instance
     */
    private Encryption $encryption;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database\Database();
        $this->encryption = new Encryption();
    }

    /**
     * Sync submission to CRM
     */
    public function sync_submission(array $instance, array $submission): array {
        if (!FeatureManager::is_enabled($instance, 'crm_integration')) {
            return ['success' => false, 'message' => 'CRM integration not enabled'];
        }

        $config = FeatureManager::get_feature($instance, 'crm_integration');
        $provider = $config['provider'] ?? 'salesforce';

        try {
            switch ($provider) {
                case 'salesforce':
                    return $this->sync_to_salesforce($config, $submission, $instance);
                case 'hubspot':
                    return $this->sync_to_hubspot($config, $submission, $instance);
                case 'zoho':
                    return $this->sync_to_zoho($config, $submission, $instance);
                case 'custom':
                    return $this->sync_to_custom($config, $submission, $instance);
                default:
                    return ['success' => false, 'message' => 'Unknown CRM provider'];
            }
        } catch (\Throwable $e) {
            $this->db->log('error', 'CRM sync failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ], $instance['id'], $submission['id'] ?? null);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Sync to Salesforce
     */
    private function sync_to_salesforce(array $config, array $submission, array $instance): array {
        $api_url = rtrim($config['api_url'] ?? '', '/');
        $access_token = $this->get_salesforce_token($config);

        if (!$access_token) {
            return ['success' => false, 'message' => 'Failed to authenticate with Salesforce'];
        }

        $form_data = $submission['form_data'] ?? [];
        $object_type = $config['object_type'] ?? 'Lead';
        $field_mapping = $config['field_mapping'] ?? $this->get_default_salesforce_mapping();

        // Map form data to Salesforce fields
        $sf_data = $this->map_fields($form_data, $field_mapping, $instance);

        // Add record type if specified
        if (!empty($config['record_type_id'])) {
            $sf_data['RecordTypeId'] = $config['record_type_id'];
        }

        $endpoint = "{$api_url}/services/data/v58.0/sobjects/{$object_type}/";

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($sf_data),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 201 && !empty($body['id'])) {
            // Store CRM ID in submission
            $this->store_crm_reference($submission['id'], 'salesforce', $body['id']);

            $this->db->log('info', 'Synced to Salesforce', [
                'object_type' => $object_type,
                'record_id' => $body['id'],
            ], $instance['id'], $submission['id']);

            return [
                'success' => true,
                'crm_id' => $body['id'],
                'provider' => 'salesforce',
            ];
        }

        $error = $body[0]['message'] ?? 'Unknown Salesforce error';
        throw new \Exception($error);
    }

    /**
     * Get Salesforce OAuth token
     */
    private function get_salesforce_token(array $config): ?string {
        $cached_token = get_transient('isf_sf_token_' . md5($config['api_key']));

        if ($cached_token) {
            return $cached_token;
        }

        $auth_url = $config['auth_url'] ?? 'https://login.salesforce.com/services/oauth2/token';

        $response = wp_remote_post($auth_url, [
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $config['api_key'],
                'client_secret' => $this->encryption->decrypt_secret($config['api_secret'] ?? ''),
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['access_token'])) {
            $expires = ($body['expires_in'] ?? 3600) - 300; // 5 min buffer
            set_transient('isf_sf_token_' . md5($config['api_key']), $body['access_token'], $expires);
            return $body['access_token'];
        }

        return null;
    }

    /**
     * Sync to HubSpot
     */
    private function sync_to_hubspot(array $config, array $submission, array $instance): array {
        $api_key = $config['api_key'] ?? '';
        $form_data = $submission['form_data'] ?? [];
        $field_mapping = $config['field_mapping'] ?? $this->get_default_hubspot_mapping();

        // Map form data to HubSpot properties
        $properties = [];
        $mapped_data = $this->map_fields($form_data, $field_mapping, $instance);

        foreach ($mapped_data as $key => $value) {
            $properties[] = [
                'property' => $key,
                'value' => $value,
            ];
        }

        $endpoint = 'https://api.hubapi.com/contacts/v1/contact/';

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['properties' => $properties]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['vid'])) {
            $this->store_crm_reference($submission['id'], 'hubspot', $body['vid']);

            $this->db->log('info', 'Synced to HubSpot', [
                'contact_id' => $body['vid'],
            ], $instance['id'], $submission['id']);

            return [
                'success' => true,
                'crm_id' => $body['vid'],
                'provider' => 'hubspot',
            ];
        }

        // Handle existing contact (409 conflict)
        if ($code === 409 && !empty($body['identityProfile']['vid'])) {
            return $this->update_hubspot_contact($config, $body['identityProfile']['vid'], $properties, $submission, $instance);
        }

        $error = $body['message'] ?? 'Unknown HubSpot error';
        throw new \Exception($error);
    }

    /**
     * Update existing HubSpot contact
     */
    private function update_hubspot_contact(array $config, string $vid, array $properties, array $submission, array $instance): array {
        $api_key = $config['api_key'] ?? '';
        $endpoint = "https://api.hubapi.com/contacts/v1/contact/vid/{$vid}/profile";

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['properties' => $properties]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 204) {
            $this->store_crm_reference($submission['id'], 'hubspot', $vid);

            $this->db->log('info', 'Updated HubSpot contact', [
                'contact_id' => $vid,
            ], $instance['id'], $submission['id']);

            return [
                'success' => true,
                'crm_id' => $vid,
                'provider' => 'hubspot',
                'action' => 'updated',
            ];
        }

        throw new \Exception('Failed to update HubSpot contact');
    }

    /**
     * Sync to Zoho CRM
     */
    private function sync_to_zoho(array $config, array $submission, array $instance): array {
        $api_url = $config['api_url'] ?? 'https://www.zohoapis.com/crm/v2';
        $access_token = $this->get_zoho_token($config);

        if (!$access_token) {
            return ['success' => false, 'message' => 'Failed to authenticate with Zoho'];
        }

        $form_data = $submission['form_data'] ?? [];
        $module = $config['object_type'] ?? 'Leads';
        $field_mapping = $config['field_mapping'] ?? $this->get_default_zoho_mapping();

        $zoho_data = $this->map_fields($form_data, $field_mapping, $instance);

        $endpoint = "{$api_url}/{$module}";

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['data' => [$zoho_data]]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['data'][0]['details']['id'])) {
            $record_id = $body['data'][0]['details']['id'];
            $this->store_crm_reference($submission['id'], 'zoho', $record_id);

            $this->db->log('info', 'Synced to Zoho', [
                'module' => $module,
                'record_id' => $record_id,
            ], $instance['id'], $submission['id']);

            return [
                'success' => true,
                'crm_id' => $record_id,
                'provider' => 'zoho',
            ];
        }

        $error = $body['data'][0]['message'] ?? 'Unknown Zoho error';
        throw new \Exception($error);
    }

    /**
     * Get Zoho OAuth token
     */
    private function get_zoho_token(array $config): ?string {
        $cached_token = get_transient('isf_zoho_token_' . md5($config['api_key']));

        if ($cached_token) {
            return $cached_token;
        }

        $refresh_token = $this->encryption->decrypt_secret($config['api_secret'] ?? '');

        $response = wp_remote_post('https://accounts.zoho.com/oauth/v2/token', [
            'body' => [
                'grant_type' => 'refresh_token',
                'client_id' => $config['api_key'],
                'client_secret' => $config['client_secret'] ?? '',
                'refresh_token' => $refresh_token,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['access_token'])) {
            $expires = ($body['expires_in'] ?? 3600) - 300;
            set_transient('isf_zoho_token_' . md5($config['api_key']), $body['access_token'], $expires);
            return $body['access_token'];
        }

        return null;
    }

    /**
     * Sync to custom webhook/API
     */
    private function sync_to_custom(array $config, array $submission, array $instance): array {
        $api_url = $config['api_url'] ?? '';

        if (empty($api_url)) {
            return ['success' => false, 'message' => 'Custom API URL not configured'];
        }

        $form_data = $submission['form_data'] ?? [];
        $field_mapping = $config['field_mapping'] ?? [];

        // Use mapping or send raw data
        $payload = !empty($field_mapping)
            ? $this->map_fields($form_data, $field_mapping, $instance)
            : $form_data;

        // Add metadata
        $payload['_meta'] = [
            'submission_id' => $submission['id'],
            'instance_id' => $instance['id'],
            'timestamp' => current_time('c'),
        ];

        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Add authentication
        if (!empty($config['api_key'])) {
            $auth_header = $config['auth_header'] ?? 'Authorization';
            $auth_prefix = $config['auth_prefix'] ?? 'Bearer ';
            $headers[$auth_header] = $auth_prefix . $config['api_key'];
        }

        $response = wp_remote_post($api_url, [
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300) {
            $crm_id = $body['id'] ?? $body['record_id'] ?? $submission['id'];
            $this->store_crm_reference($submission['id'], 'custom', $crm_id);

            $this->db->log('info', 'Synced to custom CRM', [
                'url' => $api_url,
                'response_code' => $code,
            ], $instance['id'], $submission['id']);

            return [
                'success' => true,
                'crm_id' => $crm_id,
                'provider' => 'custom',
            ];
        }

        $error = $body['error'] ?? $body['message'] ?? "HTTP {$code}";
        throw new \Exception($error);
    }

    /**
     * Map form fields to CRM fields
     */
    private function map_fields(array $form_data, array $mapping, array $instance): array {
        $result = [];
        $content = $instance['settings']['content'] ?? [];

        foreach ($mapping as $crm_field => $source) {
            $value = null;

            if (is_array($source)) {
                // Complex mapping with transformation
                $form_field = $source['field'] ?? '';
                $value = $form_data[$form_field] ?? '';

                if (!empty($source['transform'])) {
                    $value = $this->transform_value($value, $source['transform']);
                }

                if (!empty($source['default']) && empty($value)) {
                    $value = $source['default'];
                }
            } elseif (strpos($source, '{') !== false) {
                // Template string
                $value = preg_replace_callback('/\{(\w+)\}/', function($matches) use ($form_data, $content) {
                    return $form_data[$matches[1]] ?? $content[$matches[1]] ?? '';
                }, $source);
            } else {
                // Direct field mapping
                $value = $form_data[$source] ?? '';
            }

            if ($value !== null && $value !== '') {
                $result[$crm_field] = $value;
            }
        }

        return $result;
    }

    /**
     * Transform field value
     */
    private function transform_value($value, string $transform) {
        switch ($transform) {
            case 'uppercase':
                return strtoupper($value);
            case 'lowercase':
                return strtolower($value);
            case 'phone_e164':
                return preg_replace('/[^0-9+]/', '', $value);
            case 'date_iso':
                return date('Y-m-d', strtotime($value));
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            default:
                return $value;
        }
    }

    /**
     * Store CRM reference in submission
     */
    private function store_crm_reference(int $submission_id, string $provider, string $crm_id): void {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;

        $submission = $this->db->get_submission($submission_id);

        if ($submission) {
            $form_data = $submission['form_data'] ?? [];
            $form_data['crm_sync'] = [
                'provider' => $provider,
                'record_id' => $crm_id,
                'synced_at' => current_time('mysql'),
            ];

            $this->db->update_submission($submission_id, ['form_data' => $form_data]);
        }
    }

    /**
     * Get default Salesforce field mapping
     */
    private function get_default_salesforce_mapping(): array {
        return [
            'FirstName' => 'first_name',
            'LastName' => 'last_name',
            'Email' => 'email',
            'Phone' => 'phone',
            'Street' => 'address',
            'City' => 'city',
            'State' => 'state',
            'PostalCode' => 'zip',
            'Description' => ['field' => 'device_type', 'transform' => 'uppercase'],
            'LeadSource' => ['field' => '', 'default' => 'EnergyWise Enrollment Form'],
        ];
    }

    /**
     * Get default HubSpot field mapping
     */
    private function get_default_hubspot_mapping(): array {
        return [
            'firstname' => 'first_name',
            'lastname' => 'last_name',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'address',
            'city' => 'city',
            'state' => 'state',
            'zip' => 'zip',
        ];
    }

    /**
     * Get default Zoho field mapping
     */
    private function get_default_zoho_mapping(): array {
        return [
            'First_Name' => 'first_name',
            'Last_Name' => 'last_name',
            'Email' => 'email',
            'Phone' => 'phone',
            'Street' => 'address',
            'City' => 'city',
            'State' => 'state',
            'Zip_Code' => 'zip',
            'Lead_Source' => ['field' => '', 'default' => 'EnergyWise Enrollment'],
        ];
    }

    /**
     * Test CRM connection
     */
    public function test_connection(array $config): array {
        $provider = $config['provider'] ?? 'salesforce';

        try {
            switch ($provider) {
                case 'salesforce':
                    $token = $this->get_salesforce_token($config);
                    return $token
                        ? ['success' => true, 'message' => 'Salesforce connection successful']
                        : ['success' => false, 'message' => 'Failed to authenticate with Salesforce'];

                case 'hubspot':
                    $response = wp_remote_get('https://api.hubapi.com/contacts/v1/lists/all/contacts/all', [
                        'headers' => ['Authorization' => 'Bearer ' . ($config['api_key'] ?? '')],
                        'timeout' => 10,
                    ]);
                    $code = wp_remote_retrieve_response_code($response);
                    return $code === 200
                        ? ['success' => true, 'message' => 'HubSpot connection successful']
                        : ['success' => false, 'message' => 'HubSpot authentication failed'];

                case 'zoho':
                    $token = $this->get_zoho_token($config);
                    return $token
                        ? ['success' => true, 'message' => 'Zoho connection successful']
                        : ['success' => false, 'message' => 'Failed to authenticate with Zoho'];

                case 'custom':
                    $response = wp_remote_get($config['api_url'] ?? '', ['timeout' => 10]);
                    return !is_wp_error($response)
                        ? ['success' => true, 'message' => 'Custom endpoint reachable']
                        : ['success' => false, 'message' => $response->get_error_message()];

                default:
                    return ['success' => false, 'message' => 'Unknown provider'];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get available CRM providers
     */
    public static function get_providers(): array {
        return [
            'salesforce' => [
                'name' => 'Salesforce',
                'requires' => ['api_url', 'api_key', 'api_secret'],
            ],
            'hubspot' => [
                'name' => 'HubSpot',
                'requires' => ['api_key'],
            ],
            'zoho' => [
                'name' => 'Zoho CRM',
                'requires' => ['api_key', 'api_secret', 'client_secret'],
            ],
            'custom' => [
                'name' => 'Custom API',
                'requires' => ['api_url'],
            ],
        ];
    }
}

<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}

/** Separates caller input from provider-owned enrollment state. */
final class PublicFormData {
    private const USER_FIELDS = ['first_name','last_name','email','email_confirm','phone','phone_type','alt_phone','alt_phone_type','address','street','street2','city','state','zip','zip_confirm','device_type','thermostat_count','cycling_level','easy_access','install_time','ownership','owner_approval','has_ac','has_wifi','agree_adult','agree_contact','agree_terms','promo_code','promo_code_other','special_instructions','schedule_date','schedule_time','schedule_later','medical_acknowledgment','device_converted','ac_units','heat_pumps','ac_heat_units'];
    private const DISPLAY_FIELDS = ['account_number','utility_no','zip_code'];
    private const RETAIN_ON_VALIDATION = ['device_type','thermostat_count','cycling_level','easy_access','install_time','has_ac','has_wifi','device_converted'];

    public static function capture(array $stored, $posted, string $session_id, int $instance_id): array {
        $prior = self::signed_fields($stored, $session_id, $instance_id);
        $stored = self::isolate_legacy($stored);
        $fields = array_merge($prior, self::clean(is_array($posted) ? $posted : []));
        foreach (self::USER_FIELDS as $key) {
            unset($stored[$key]);
        }
        $stored = array_merge($stored, array_intersect_key($fields, array_flip(self::USER_FIELDS)));
        $stored['_public_input'] = self::envelope($fields, $session_id, $instance_id);
        return $stored;
    }

    public static function project(array $stored, string $session_id, int $instance_id): array {
        $public = self::signed_fields($stored, $session_id, $instance_id);
        foreach (['enrollment_completed','confirmation_number','schedule_time_display'] as $key) {
            if (isset($stored[$key]) && is_scalar($stored[$key])) {
                $public[$key] = $stored[$key];
            }
        }
        return $public;
    }

    public static function validated(array $stored, array $provider_result, array $customer, string $account_number, string $zip_code, string $session_id, int $instance_id): array {
        $fields = array_intersect_key(self::signed_fields($stored, $session_id, $instance_id), array_flip(self::RETAIN_ON_VALIDATION));
        $stored = self::isolate_legacy($stored);
        foreach (self::USER_FIELDS as $key) {
            unset($stored[$key]);
        }
        // References and equipment belong to the previously validated account.
        foreach (['validation_result', 'fsr_no', 'scheduling_result', 'confirmation_number', 'enrollment_response', '_dd_code', '_eqloc_code', 'schedule_time_display'] as $key) {
            unset($stored[$key]);
        }
        $stored['_provider_validation'] = ['result' => $provider_result, 'customer' => $customer];
        $stored['account_number'] = $account_number;
        $stored['utility_no'] = $account_number;
        $stored['zip_code'] = $zip_code;
        $stored['account_validated'] = true;
        $fields = array_merge($fields, ['account_number' => $account_number, 'utility_no' => $account_number, 'zip_code' => $zip_code]);
        $stored = array_merge($stored, array_intersect_key($fields, array_flip(self::USER_FIELDS)));
        $stored['_public_input'] = self::envelope($fields, $session_id, $instance_id);
        return $stored;
    }

    private static function clean(array $input): array {
        $input = array_intersect_key($input, array_flip(self::USER_FIELDS));
        foreach ($input as $key => $value) {
            if ($key === 'address') {
                $input[$key] = is_array($value) ? array_filter(array_intersect_key($value, array_flip(['street','street2','city','state','zip'])), 'is_scalar') : [];
            } elseif (!is_scalar($value) && $value !== null) {
                unset($input[$key]);
            }
        }
        return Security::sanitize_form_data($input);
    }

    private static function signed_fields(array $stored, string $session_id, int $instance_id): array {
        $input = $stored['_public_input'] ?? null;
        if (!is_array($input) || ($input['version'] ?? null) !== 1 || !is_array($input['fields'] ?? null) || !is_string($input['signature'] ?? null)) {
            return [];
        }
        try {
            $expected = self::envelope($input['fields'], $session_id, $instance_id);
        } catch (\JsonException $exception) {
            return [];
        }
        if (!hash_equals($expected['signature'], $input['signature'])) {
            return [];
        }
        return array_intersect_key($input['fields'], array_flip(array_merge(self::USER_FIELDS, self::DISPLAY_FIELDS)));
    }

    private static function envelope(array $fields, string $session_id, int $instance_id): array {
        $payload = json_encode(['version' => 1, 'session' => $session_id, 'instance' => $instance_id, 'fields' => $fields], JSON_THROW_ON_ERROR);
        return ['version' => 1, 'fields' => $fields, 'signature' => hash_hmac('sha256', 'isf-public-input-v1|' . $payload, wp_salt('auth'))];
    }

    private static function isolate_legacy(array $stored): array {
        $legacy = is_array($stored['_legacy_form_data'] ?? null) ? $stored['_legacy_form_data'] : [];
        foreach (array_merge(self::USER_FIELDS, ['validation_result','validated_first_name','validated_last_name','validated_street','validated_city','validated_state','validated_zip']) as $key) {
            if (array_key_exists($key, $stored)) {
                if (!array_key_exists($key, $legacy)) {
                    $legacy[$key] = $stored[$key];
                }
                unset($stored[$key]);
            }
        }
        unset($legacy['_legacy_form_data'], $legacy['_public_input'], $legacy['_provider_validation']);
        if ($legacy !== []) {
            $stored['_legacy_form_data'] = $legacy;
        }
        return $stored;
    }
}

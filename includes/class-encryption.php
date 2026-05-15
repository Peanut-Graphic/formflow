<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Encryption Utilities
 *
 * Handles AES-256-CBC encryption/decryption for sensitive data storage.
 */


class Encryption {

    private const METHOD = 'AES-256-CBC';
    private const IV_LENGTH = 16;

    private string $key;

    /**
     * Constructor
     */
    public function __construct() {
        $this->key = $this->get_encryption_key();
    }

    /**
     * Get or generate the encryption key
     */
    private function get_encryption_key(): string {
        // First, check for defined constant
        if (defined('ISF_ENCRYPTION_KEY') && strlen(ISF_ENCRYPTION_KEY) >= 32) {
            return substr(ISF_ENCRYPTION_KEY, 0, 32);
        }

        // Fall back to WordPress auth salt
        $key = wp_salt('auth');

        // Ensure key is exactly 32 bytes
        return substr(hash('sha256', $key), 0, 32);
    }

    /**
     * Encrypt data
     */
    public function encrypt(string $data): string {
        if (empty($data)) {
            return '';
        }

        // Generate random IV
        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);

        // Encrypt
        $encrypted = openssl_encrypt(
            $data,
            self::METHOD,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Combine IV and encrypted data, then base64 encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data
     */
    public function decrypt(string $data): string {
        if (empty($data)) {
            return '';
        }

        // Decode base64
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return '';
        }

        // Extract IV and encrypted data
        $iv = substr($decoded, 0, self::IV_LENGTH);
        $encrypted = substr($decoded, self::IV_LENGTH);

        if (strlen($iv) !== self::IV_LENGTH) {
            return '';
        }

        // Decrypt
        $decrypted = openssl_decrypt(
            $encrypted,
            self::METHOD,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Encrypt an array (converts to JSON first)
     */
    public function encrypt_array(array $data): string {
        return $this->encrypt(json_encode($data));
    }

    /**
     * Decrypt to array
     */
    public function decrypt_array(string $data): array {
        $decrypted = $this->decrypt($data);
        if (empty($decrypted)) {
            return [];
        }

        $array = json_decode($decrypted, true);
        return is_array($array) ? $array : [];
    }

    /**
     * Hash sensitive data for comparison (one-way)
     */
    public static function hash(string $data): string {
        return hash('sha256', $data);
    }

    /**
     * Verify a value against its hash
     */
    public static function verify_hash(string $data, string $hash): bool {
        return hash_equals($hash, self::hash($data));
    }

    /**
     * Mask sensitive data for display (e.g., account numbers)
     */
    public static function mask(string $data, int $visible_start = 0, int $visible_end = 4): string {
        $length = strlen($data);
        if ($length <= ($visible_start + $visible_end)) {
            return str_repeat('*', $length);
        }

        $start = substr($data, 0, $visible_start);
        $end = substr($data, -$visible_end);
        $middle = str_repeat('*', $length - $visible_start - $visible_end);

        return $start . $middle . $end;
    }

    /**
     * Test if encryption is working properly
     */
    public function test(): bool {
        $test_data = 'FormFlow Encryption Test ' . time();

        try {
            $encrypted = $this->encrypt($test_data);
            $decrypted = $this->decrypt($encrypted);
            return $decrypted === $test_data;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if using custom encryption key (not WordPress fallback)
     */
    public static function is_using_custom_key(): bool {
        return defined('ISF_ENCRYPTION_KEY') && strlen(ISF_ENCRYPTION_KEY) >= 32;
    }

    /**
     * Check if encryption key is properly configured
     */
    public static function get_key_status(): array {
        if (!defined('ISF_ENCRYPTION_KEY')) {
            return [
                'status' => 'warning',
                'message' => __('ISF_ENCRYPTION_KEY is not defined. Using WordPress auth salt as fallback. For better security, add a custom encryption key to wp-config.php.', 'formflow'),
                'code' => 'key_not_defined'
            ];
        }

        if (strlen(ISF_ENCRYPTION_KEY) < 32) {
            return [
                'status' => 'error',
                'message' => __('ISF_ENCRYPTION_KEY is too short. It must be at least 32 characters for AES-256 encryption.', 'formflow'),
                'code' => 'key_too_short'
            ];
        }

        return [
            'status' => 'ok',
            'message' => __('Custom encryption key is properly configured.', 'formflow'),
            'code' => 'key_ok'
        ];
    }
}

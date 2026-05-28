<?php
/**
 * SFTP Destination
 *
 * Delivers finished submissions to a remote SFTP endpoint. Built on
 * phpseclib3 — no PECL ssh2 extension required.
 *
 * Supports both password and SSH-key authentication (Itron undecided as
 * of 2026-05-26; admin picks at setup time).
 *
 * Credentials (password, private key, key passphrase, optional host key
 * fingerprint) are stored encrypted via ISF\Encryption (AES-256-CBC
 * keyed off ISF_ENCRYPTION_KEY, or WP auth salt as fallback).
 *
 * @package FormFlow
 * @subpackage Destinations
 * @since 2.9.0
 */

namespace ISF\Destinations\Sftp;

use ISF\Destinations\BaseDestination;
use ISF\Destinations\DeliveryFailure;
use ISF\Destinations\DeliveryResult;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

if (!defined('ABSPATH')) {
    exit;
}

class SftpDestination extends BaseDestination {

    private const VERSION = '1.0.0';
    private const CONNECT_TIMEOUT = 10; // seconds

    public function get_id(): string {
        return 'sftp';
    }

    public function get_name(): string {
        return __('SFTP', 'formflow');
    }

    public function get_description(): string {
        return __('Deliver each submission as a file (CSV / JSON / XML) to a remote SFTP endpoint. Supports password and SSH-key authentication.', 'formflow');
    }

    public function get_version(): string {
        return self::VERSION;
    }

    public function get_supported_features(): array {
        return ['retry', 'purge_after_export', 'test_connection'];
    }

    public function get_config_fields(): array {
        return [
            'host' => [
                'label' => __('Host', 'formflow'),
                'type' => 'text',
                'required' => true,
                'description' => __('SFTP hostname or IP.', 'formflow'),
            ],
            'port' => [
                'label' => __('Port', 'formflow'),
                'type' => 'number',
                'required' => true,
                'default' => 22,
            ],
            'username' => [
                'label' => __('Username', 'formflow'),
                'type' => 'text',
                'required' => true,
            ],
            'auth_mode' => [
                'label' => __('Authentication', 'formflow'),
                'type' => 'select',
                'required' => true,
                'default' => 'password',
                'options' => [
                    'password' => __('Password', 'formflow'),
                    'key'      => __('SSH private key', 'formflow'),
                ],
                'description' => __('Itron accepts either. Pick whichever the intake endpoint is configured for.', 'formflow'),
            ],
            'password' => [
                'label' => __('Password', 'formflow'),
                'type' => 'password',
                'sensitive' => true,
                'required' => true,
                'show_if' => ['auth_mode' => 'password'],
                'description' => __('Encrypted at rest. Leave blank when editing to keep the existing value.', 'formflow'),
            ],
            'private_key' => [
                'label' => __('Private key', 'formflow'),
                'type' => 'textarea',
                'sensitive' => true,
                'required' => true,
                'show_if' => ['auth_mode' => 'key'],
                'description' => __('PEM-format private key (RSA, ECDSA, or Ed25519). Encrypted at rest. Leave blank when editing to keep the existing value.', 'formflow'),
            ],
            'private_key_passphrase' => [
                'label' => __('Key passphrase', 'formflow'),
                'type' => 'password',
                'sensitive' => true,
                'required' => false,
                'show_if' => ['auth_mode' => 'key'],
                'description' => __('Optional. Only needed if the private key is encrypted.', 'formflow'),
            ],
            'remote_path' => [
                'label' => __('Remote path', 'formflow'),
                'type' => 'text',
                'required' => true,
                'default' => '/',
                'description' => __('Absolute path on the SFTP server where files will be uploaded. Must start with /.', 'formflow'),
            ],
            'filename_template' => [
                'label' => __('Filename template', 'formflow'),
                'type' => 'text',
                'required' => true,
                'default' => 'dominion_ptr_{date:Ymd}_{date:His}.csv',
                'description' => __('Tokens: {date:Ymd}, {date:His}, {slug}, {submission_id}, {ext}. Path separators are not allowed.', 'formflow'),
            ],
            'format' => [
                'label' => __('Format', 'formflow'),
                'type' => 'select',
                'required' => true,
                'default' => 'csv',
                'options' => [
                    'csv'  => 'CSV',
                    'json' => 'JSON',
                    'xml'  => 'XML',
                ],
            ],
            'csv_delimiter' => [
                'label' => __('CSV delimiter', 'formflow'),
                'type' => 'select',
                'default' => ',',
                'show_if' => ['format' => 'csv'],
                'options' => [
                    ','  => __('Comma (,)', 'formflow'),
                    "\t" => __('Tab', 'formflow'),
                    '|'  => __('Pipe (|)', 'formflow'),
                    ';'  => __('Semicolon (;)', 'formflow'),
                ],
            ],
            'csv_quote_mode' => [
                'label' => __('CSV quoting', 'formflow'),
                'type' => 'select',
                'default' => 'rfc4180',
                'show_if' => ['format' => 'csv'],
                'options' => [
                    'rfc4180' => __('RFC 4180 — quote when needed', 'formflow'),
                    'always'  => __('Always quote', 'formflow'),
                ],
            ],
            'csv_line_ending' => [
                'label' => __('Line endings', 'formflow'),
                'type' => 'select',
                'default' => 'crlf',
                'options' => [
                    'crlf' => __('CRLF (Windows / RFC 4180)', 'formflow'),
                    'lf'   => __('LF (Unix)', 'formflow'),
                ],
            ],
            'csv_encoding' => [
                'label' => __('Encoding', 'formflow'),
                'type' => 'select',
                'default' => 'utf-8',
                'options' => [
                    'utf-8'     => 'UTF-8',
                    'utf-8-bom' => 'UTF-8 with BOM',
                    'ascii'     => 'ASCII (transliterate)',
                ],
            ],
            'csv_include_header' => [
                'label' => __('Include header row', 'formflow'),
                'type' => 'checkbox',
                'default' => true,
                'show_if' => ['format' => 'csv'],
            ],
            'boolean_representation' => [
                'label' => __('Boolean representation', 'formflow'),
                'type' => 'select',
                'default' => 'yes_no',
                'options' => [
                    'yes_no'    => 'yes / no',
                    'y_n'       => 'Y / N',
                    'true_false'=> 'true / false',
                    '1_0'       => '1 / 0',
                ],
            ],
            'purge_after_export' => [
                'label' => __('Purge submission after successful delivery', 'formflow'),
                'type' => 'checkbox',
                'default' => false,
                'description' => __('Deletes the local submission row once this destination has delivered successfully. Useful for privacy-sensitive intakes (Dominion PTR pattern).', 'formflow'),
            ],
            'host_key_fingerprint' => [
                'label' => __('Host key fingerprint (optional)', 'formflow'),
                'type' => 'text',
                'sensitive' => false,
                'required' => false,
                'description' => __('SHA-256 fingerprint of the server\'s SSH host key. If set, connections to a different host key will be rejected (MITM defense).', 'formflow'),
            ],
        ];
    }

    public function validate_config(array $config): array {
        $errors = parent::validate_config($config);

        $port = isset($config['port']) ? (int) $config['port'] : 0;
        if ($port < 1 || $port > 65535) {
            $errors[] = __('Port must be between 1 and 65535.', 'formflow');
        }

        $remote = (string) ($config['remote_path'] ?? '');
        if ($remote !== '') {
            if (!str_starts_with($remote, '/')) {
                $errors[] = __('Remote path must be absolute (start with /).', 'formflow');
            }
            if (str_contains($remote, '..') || str_contains($remote, "\0")) {
                $errors[] = __('Remote path contains disallowed sequences.', 'formflow');
            }
        }

        $template = (string) ($config['filename_template'] ?? '');
        if (str_contains($template, '/') || str_contains($template, "\0")) {
            $errors[] = __('Filename template cannot contain / or null bytes.', 'formflow');
        }

        return $errors;
    }

    public function test_connection(array $config): array {
        try {
            $sftp = $this->open_connection($config);
            $remote = (string) ($config['remote_path'] ?? '/');
            $listing = $sftp->nlist($remote);
            $sftp->disconnect();

            if ($listing === false) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        /* translators: %s: remote path */
                        __('Connected, but the remote path %s could not be listed. Check that the path exists and the user has read access.', 'formflow'),
                        $remote
                    ),
                    'code' => 'path_not_found',
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    /* translators: %d: number of entries listed */
                    __('Connected. Remote path readable (%d entries).', 'formflow'),
                    is_array($listing) ? count($listing) : 0
                ),
                'code' => 'ok',
            ];
        } catch (\Throwable $e) {
            return $this->classify_exception($e);
        }
    }

    public function deliver(array $submission, array $config): DeliveryResult {
        try {
            $bytes = SftpFormatter::render($submission, $config);
            $filename = SftpFormatter::filename($submission, $config);
            $hash = hash('sha256', $bytes);

            $sftp = $this->open_connection($config);
            $remote_dir = rtrim((string) ($config['remote_path'] ?? '/'), '/');
            $remote_path = $remote_dir . '/' . $filename;

            $ok = $sftp->put($remote_path, $bytes);
            $sftp->disconnect();

            if (!$ok) {
                return DeliveryResult::fail(
                    sprintf(
                        /* translators: %s: remote file path */
                        __('SFTP upload failed for %s.', 'formflow'),
                        $remote_path
                    ),
                    DeliveryFailure::TRANSIENT,
                    ['remote_path' => $remote_path]
                );
            }

            return DeliveryResult::ok(
                sprintf(
                    /* translators: %s: remote file path */
                    __('Delivered %s', 'formflow'),
                    $remote_path
                ),
                $hash,
                [
                    'remote_path' => $remote_path,
                    'bytes' => strlen($bytes),
                    'filename' => $filename,
                ]
            );
        } catch (\Throwable $e) {
            $classified = $this->classify_exception($e);
            return DeliveryResult::fail(
                $classified['message'],
                $this->failure_kind_from_code($classified['code']),
                ['code' => $classified['code']]
            );
        }
    }

    // ----- connection helpers -----

    /**
     * Open an authenticated SFTP connection. Throws on any failure.
     *
     * @throws \RuntimeException
     */
    private function open_connection(array $config): SFTP {
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 22);
        $username = (string) ($config['username'] ?? '');
        $auth_mode = (string) ($config['auth_mode'] ?? 'password');

        if ($host === '' || $username === '') {
            throw new \RuntimeException(__('Host and username are required.', 'formflow'));
        }

        $sftp = new SFTP($host, $port, self::CONNECT_TIMEOUT);

        // Optional host-key fingerprint check (sha256:base64 or hex form).
        $expected_fp = trim((string) ($config['host_key_fingerprint'] ?? ''));
        if ($expected_fp !== '') {
            $server_fp = $sftp->getServerPublicHostKey();
            if ($server_fp === false) {
                throw new \RuntimeException(__('Could not read server host key for fingerprint check.', 'formflow'));
            }
            $actual_fp = 'sha256:' . base64_encode(hash('sha256', (string) $server_fp, true));
            if (!hash_equals($expected_fp, $actual_fp)) {
                throw new \RuntimeException(__('Host key fingerprint does not match — connection refused (possible MITM).', 'formflow'));
            }
        }

        $credential = $this->build_credential($auth_mode, $config);
        $logged_in = $sftp->login($username, $credential);

        if (!$logged_in) {
            $err = $sftp->getLastError();
            throw new \RuntimeException(
                __('SFTP login failed.', 'formflow')
                . ($err ? ' (' . $err . ')' : '')
            );
        }

        return $sftp;
    }

    /**
     * Build the credential object phpseclib's login() accepts.
     *
     * @return string|object Password string, or a PrivateKey instance.
     * @throws \RuntimeException
     */
    private function build_credential(string $auth_mode, array $config) {
        if ($auth_mode === 'key') {
            $key_text = (string) ($config['private_key'] ?? '');
            if ($key_text === '') {
                throw new \RuntimeException(__('Private key is empty.', 'formflow'));
            }
            $passphrase = (string) ($config['private_key_passphrase'] ?? '');
            try {
                $key = PublicKeyLoader::load($key_text, $passphrase !== '' ? $passphrase : false);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    __('Private key could not be parsed.', 'formflow')
                    . ($e->getMessage() ? ' (' . $e->getMessage() . ')' : '')
                );
            }
            return $key;
        }

        $pw = (string) ($config['password'] ?? '');
        if ($pw === '') {
            throw new \RuntimeException(__('Password is empty.', 'formflow'));
        }
        return $pw;
    }

    /**
     * Map a thrown exception to a user-friendly admin-facing result.
     *
     * @return array{success: bool, message: string, code: string}
     */
    private function classify_exception(\Throwable $e): array {
        $msg = $e->getMessage();
        $lower = strtolower($msg);

        if (str_contains($lower, 'login') || str_contains($lower, 'auth')) {
            return ['success' => false, 'message' => $msg, 'code' => 'auth_failed'];
        }
        if (str_contains($lower, 'host key') || str_contains($lower, 'fingerprint')) {
            return ['success' => false, 'message' => $msg, 'code' => 'host_key_mismatch'];
        }
        if (str_contains($lower, 'no such') || str_contains($lower, 'not found')) {
            return ['success' => false, 'message' => $msg, 'code' => 'path_not_found'];
        }
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return ['success' => false, 'message' => $msg, 'code' => 'timeout'];
        }
        if (str_contains($lower, 'connection') || str_contains($lower, 'refused')) {
            return ['success' => false, 'message' => $msg, 'code' => 'connect_failed'];
        }
        return ['success' => false, 'message' => $msg, 'code' => 'unknown'];
    }

    private function failure_kind_from_code(string $code): string {
        return match ($code) {
            'auth_failed', 'host_key_mismatch' => DeliveryFailure::AUTH,
            'path_not_found'                   => DeliveryFailure::CONFIG,
            'timeout', 'connect_failed'        => DeliveryFailure::TRANSIENT,
            default                            => DeliveryFailure::UNKNOWN,
        };
    }
}

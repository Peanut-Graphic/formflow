<?php
/**
 * Delivery Result
 *
 * Value object returned from DestinationInterface::deliver().
 *
 * @package FormFlow
 * @since 2.9.0
 */

namespace ISF\Destinations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Failure categories used by the delivery worker to decide retry policy.
 */
final class DeliveryFailure {
    /** Transient (network, timeout, temporary 5xx). Retry per backoff. */
    public const TRANSIENT = 'transient';

    /** Auth failed (bad creds). Do not retry; alert immediately. */
    public const AUTH = 'auth';

    /** Bad config (path missing, permission denied). Do not retry; alert. */
    public const CONFIG = 'config';

    /** Quota or disk full. Retry once, then alert. */
    public const QUOTA = 'quota';

    /** Payload was rejected (format / schema). Do not retry; alert. */
    public const PAYLOAD = 'payload';

    /** Unknown / unclassified. Retry per backoff. */
    public const UNKNOWN = 'unknown';

    public static function should_retry(string $kind): bool {
        return in_array($kind, [self::TRANSIENT, self::QUOTA, self::UNKNOWN], true);
    }

    private function __construct() {}
}

class DeliveryResult {

    public bool $success;
    public string $message;
    public string $failure_kind;
    public string $payload_hash;
    public array $meta;

    /**
     * @param bool   $success
     * @param string $message      Readable status or error.
     * @param string $failure_kind One of DeliveryFailure::* (only meaningful on failure).
     * @param string $payload_hash sha256 of the delivered bytes, for dedupe / audit.
     * @param array  $meta         Free-form per-destination metadata (e.g. ['remote_filename' => '...']).
     */
    public function __construct(
        bool $success,
        string $message = '',
        string $failure_kind = '',
        string $payload_hash = '',
        array $meta = []
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->failure_kind = $failure_kind;
        $this->payload_hash = $payload_hash;
        $this->meta = $meta;
    }

    public static function ok(string $message = '', string $payload_hash = '', array $meta = []): self {
        return new self(true, $message, '', $payload_hash, $meta);
    }

    public static function fail(string $message, string $failure_kind = DeliveryFailure::UNKNOWN, array $meta = []): self {
        return new self(false, $message, $failure_kind, '', $meta);
    }

    public function should_retry(): bool {
        return !$this->success && DeliveryFailure::should_retry($this->failure_kind);
    }

    public function to_array(): array {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'failure_kind' => $this->failure_kind,
            'payload_hash' => $this->payload_hash,
            'meta' => $this->meta,
        ];
    }
}

<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enrollment session binding.
 *
 * Enrollment wizard sessions used to be identified by a random id that the
 * shortcode rendered into the page HTML (data-session). With full-page caching
 * every visitor of a cached copy shared that id, and every AJAX handler looked
 * the session up by the client-sent id alone — so visitor B could read and
 * overwrite visitor A's name, email, phone, address and account number.
 *
 * The id is now issued only by an uncached AJAX bootstrap call
 * (isf_start_session) together with a session token:
 *
 *     token = HMAC-SHA256( wp_salt('auth'), "isf-session-v1|{instance_id}|{session_id}" )
 *
 * Every handler that touches a session must present both, and the token is
 * compared with hash_equals(). The token is never stored and never rendered
 * into HTML, so a cached page carries no session state, a client cannot pick
 * its own session id (no fixation / no planting rows for a guessed id), and a
 * session id that leaks on its own (analytics, logs) is useless.
 *
 * @package FormFlow
 */
final class SessionGuard {

    /**
     * Domain-separation prefix for the HMAC. Bump the version to invalidate
     * every outstanding session token at once.
     */
    private const CONTEXT = 'isf-session-v1';

    /**
     * Submission status after which a session accepts no further writes.
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Error code returned when the session id/token pair is missing or wrong.
     */
    public const ERROR_INVALID = 'session_invalid';

    /**
     * Error code returned when a write targets a completed session.
     */
    public const ERROR_COMPLETED = 'session_completed';

    /**
     * Issue a fresh, server-chosen session for an instance.
     *
     * @param int $instance_id Form instance ID.
     * @return array{session_id: string, session_token: string}
     */
    public static function issue(int $instance_id): array {
        $session_id = Security::generate_session_id();

        return [
            'session_id'    => $session_id,
            'session_token' => self::token_for($session_id, $instance_id),
        ];
    }

    /**
     * Compute the token that proves the server issued $session_id for $instance_id.
     */
    public static function token_for(string $session_id, int $instance_id): string {
        return hash_hmac('sha256', self::CONTEXT . '|' . $instance_id . '|' . $session_id, wp_salt('auth'));
    }

    /**
     * Session ids come from Security::generate_session_id(): 64 hex chars, or
     * a 64-char alphanumeric fallback. Anything else was not issued by us.
     */
    public static function is_well_formed(string $session_id): bool {
        return preg_match('/^[A-Za-z0-9]{32,64}$/', $session_id) === 1;
    }

    /**
     * Constant-time check of a session id/token pair.
     */
    public static function verify(string $session_id, string $token, int $instance_id): bool {
        if ($instance_id <= 0 || $token === '' || !self::is_well_formed($session_id)) {
            return false;
        }

        return hash_equals(self::token_for($session_id, $instance_id), $token);
    }

    /**
     * Read the session id/token pair from the current AJAX request.
     *
     * @return string|null The verified session id, or null when the pair is
     *                     missing or does not verify.
     */
    public static function from_request(int $instance_id): ?string {
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash((string) $_POST['session_id'])) : '';
        $token      = isset($_POST['session_token']) ? sanitize_text_field(wp_unslash((string) $_POST['session_token'])) : '';

        return self::verify($session_id, $token, $instance_id) ? $session_id : null;
    }

    /**
     * Whether a submission row has reached its terminal, completed state.
     */
    public static function is_completed(?array $submission): bool {
        return is_array($submission) && ($submission['status'] ?? '') === self::STATUS_COMPLETED;
    }

    /**
     * JSON error payload for a missing/invalid session.
     */
    public static function invalid_error(): array {
        return [
            'message' => __('Your session has expired. Please refresh the page to start again.', 'formflow'),
            'code'    => self::ERROR_INVALID,
        ];
    }

    /**
     * JSON error payload for a write against a completed session.
     */
    public static function completed_error(): array {
        return [
            'message' => __('This enrollment has already been submitted. Please refresh the page to start a new one.', 'formflow'),
            'code'    => self::ERROR_COMPLETED,
        ];
    }

    /**
     * Response headers for any response that carries session material.
     */
    public static function send_private_headers(): void {
        if (headers_sent()) {
            return;
        }
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Ask page caches not to store the current page.
     *
     * DONOTCACHEPAGE is honored by WP Super Cache, W3 Total Cache, WP Rocket,
     * WP Fastest Cache, Cache Enabler and others; LiteSpeed Cache listens for
     * the litespeed_control_set_nocache action. nocache_headers() covers
     * reverse proxies / CDNs that respect Cache-Control — but only while
     * headers can still be sent, which is why the plugin also calls this from
     * template_redirect (see Frontend::maybe_disable_page_cache()).
     */
    public static function mark_page_uncacheable(): void {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        do_action('litespeed_control_set_nocache', 'formflow: enrollment form');

        if (!headers_sent()) {
            nocache_headers();
        }
    }
}

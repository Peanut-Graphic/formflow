<?php
namespace ISF\Analytics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Signing for external-completion return URLs.
 *
 * GET /isf/v1/completions/redirect is public by design (the partner's hosted
 * form sends the visitor's browser back to it), so the handoff token in the
 * URL cannot by itself prove that an enrollment happened: anyone can mint a
 * handoff and hit the return URL. Each form instance therefore has its own
 * secret, shared with the enrollment partner, and the return URL must carry
 *
 *     isf_sig = hex( HMAC-SHA256( secret, isf_ref ) )
 *
 * The plugin never hands a signature to the browser — only the partner, after
 * a real completion, can compute it.
 *
 * Secrets live in the non-autoloaded option isf_completion_secrets
 * ([instance_id => 64 hex chars]) and are created on first use. The instance
 * editor shows the secret to admins so it can be given to the partner.
 *
 * @package FormFlow
 */
final class CompletionSigner {

    private const OPTION = 'isf_completion_secrets';

    /**
     * The per-instance signing secret, created on first use.
     */
    public static function secret_for(int $instance_id): string {
        $secrets = get_option(self::OPTION, []);
        if (!is_array($secrets)) {
            $secrets = [];
        }

        $secret = $secrets[$instance_id] ?? '';
        if (is_string($secret) && preg_match('/^[a-f0-9]{64}$/', $secret) === 1) {
            return $secret;
        }

        $secret = bin2hex(random_bytes(32));
        $secrets[$instance_id] = $secret;

        if (!add_option(self::OPTION, $secrets, '', false)) {
            update_option(self::OPTION, $secrets, false);
        }

        return $secret;
    }

    /**
     * Signature a partner must append as isf_sig.
     */
    public static function sign(string $token, int $instance_id): string {
        return hash_hmac('sha256', $token, self::secret_for($instance_id));
    }

    /**
     * Constant-time check of a return-URL signature.
     */
    public static function verify(string $token, string $signature, int $instance_id): bool {
        if ($instance_id <= 0 || $token === '' || preg_match('/^[a-f0-9]{64}$/i', $signature) !== 1) {
            return false;
        }

        return hash_equals(self::sign($token, $instance_id), strtolower($signature));
    }
}

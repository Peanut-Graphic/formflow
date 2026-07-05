<?php

namespace ISF\Tests\Regression;

use ISF\Api\HandoffEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for CLUSTER-3 (P2) — open redirect + anonymous-write abuse
 * in includes/api/class-handoff-endpoint.php.
 *
 * BEFORE the fix:
 *   - `create_handoff` was an anonymous (__return_true) REST write that stored
 *     an attacker-controlled `destination_url` in isf_handoffs, and a later
 *     GET /handoff/{token} → `process_redirect` sent the visitor to whatever
 *     was stored — on the site's trusted PII domain. javascript:/data:/malformed
 *     destinations sailed through, giving a phishing / open-redirect vector.
 *   - The same public route (and the visitor/touch writes it triggers) had NO
 *     rate limiting, so it could be hammered to exhaust DB/resources.
 *
 * AFTER the fix:
 *   - `HandoffEndpoint::is_safe_destination_url()` requires a well-formed
 *     ABSOLUTE http(s) URL with a host and is enforced at BOTH the store
 *     (`create_handoff`) and every redirect site (`process_redirect`,
 *     `handle_redirect_param`).
 *   - `create_handoff` calls `Security::check_rate_limit()` before writing.
 *
 * The URL-invariant assertions call the pure, WordPress-free validator directly.
 * The rate-limit + redirect-guard assertions are self-contained SOURCE scans
 * (mirroring AbspathGuardOrderTest) so they run green in CI without a live WP /
 * $wpdb harness, and fail loudly if a future edit drops either guard.
 */
final class HandoffRedirectDestinationTest extends TestCase
{
    /**
     * Malicious / malformed destinations that MUST be rejected.
     *
     * @return array<string, array{0: string}>
     */
    public static function unsafeDestinations(): array
    {
        return [
            'javascript scheme'         => ['javascript:alert(1)'],
            'javascript with slashes'   => ['javascript://%0aalert(1)'],
            'data scheme'               => ['data:text/html,<script>alert(1)</script>'],
            'vbscript scheme'           => ['vbscript:msgbox(1)'],
            'file scheme'               => ['file:///etc/passwd'],
            'protocol-relative'         => ['//evil.example.com/phish'],
            'no scheme, path only'      => ['/wp-admin'],
            'no host'                   => ['https://'],
            'bare word'                 => ['not-a-url'],
            'empty string'             => [''],
            'ftp scheme'               => ['ftp://evil.example.com/x'],
            'embedded newline scheme'  => ["java\nscript:alert(1)"],
            'embedded null'            => ["https://good.example.com\x00.evil.com"],
        ];
    }

    /**
     * Legitimate external handoff destinations that MUST keep working.
     *
     * @return array<string, array{0: string}>
     */
    public static function safeDestinations(): array
    {
        return [
            'https host'                => ['https://enroll.example.com/start'],
            'https with query'          => ['https://enroll.example.com/start?plan=basic&ref=42'],
            'http host'                 => ['http://enroll.example.com/start'],
            'https with port + path'    => ['https://enroll.example.com:8443/a/b/c'],
            'uppercase scheme'          => ['HTTPS://enroll.example.com/start'],
        ];
    }

    /**
     * @dataProvider unsafeDestinations
     */
    public function test_unsafe_destinations_are_rejected(string $url): void
    {
        $this->assertFalse(
            HandoffEndpoint::is_safe_destination_url($url),
            sprintf('Expected unsafe destination to be rejected: %s', var_export($url, true))
        );
    }

    /**
     * @dataProvider safeDestinations
     */
    public function test_valid_http_and_https_destinations_are_accepted(string $url): void
    {
        $this->assertTrue(
            HandoffEndpoint::is_safe_destination_url($url),
            sprintf('Expected legitimate destination to be accepted: %s', var_export($url, true))
        );
    }

    /**
     * create_handoff (the anonymous write path) must throttle via the shared
     * rate limiter, matching how the enrollment handlers guard their writes.
     */
    public function test_create_handoff_is_rate_limited(): void
    {
        $body = $this->methodBody('create_handoff');

        $this->assertMatchesRegularExpression(
            '/Security::check_rate_limit\s*\(/',
            $body,
            'create_handoff() must call Security::check_rate_limit() before writing '
            . '(anonymous-write abuse guard removed?).'
        );
    }

    /**
     * Every redirect site must validate the stored destination before sending
     * the visitor, so a bad value can never produce an open redirect.
     */
    public function test_redirect_paths_validate_destination(): void
    {
        foreach (['process_redirect', 'handle_redirect_param'] as $method) {
            $this->assertMatchesRegularExpression(
                '/is_safe_destination_url\s*\(/',
                $this->methodBody($method),
                sprintf('%s() must guard the redirect with is_safe_destination_url().', $method)
            );
        }
    }

    /**
     * Returns the source text of a named method on HandoffEndpoint by reading
     * the file between the method's start and end lines. Self-contained: no WP,
     * no DB, no plugin bootstrap.
     */
    private function methodBody(string $method): string
    {
        $ref = new \ReflectionMethod(HandoffEndpoint::class, $method);
        $file = $ref->getFileName();
        $this->assertIsString($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        $start = $ref->getStartLine() - 1;
        $length = $ref->getEndLine() - $ref->getStartLine() + 1;

        return implode("\n", array_slice($lines, $start, $length));
    }
}

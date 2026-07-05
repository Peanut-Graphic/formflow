<?php
/**
 * Regression guard — trusted-proxy client-IP resolver (Security::get_client_ip).
 *
 * An earlier revision returned the FIRST value of the attacker-controlled
 * `CF-Connecting-IP` / `X-Forwarded-For` request headers (explode(',')[0]) with
 * NO trusted-proxy allowlist. That IP is the sole key for the per-IP rate limit
 * (check_rate_limit -> 'isf_rate_' . md5($ip)) that sits in front of the public
 * nopriv enrollment handlers (isf_submit_enrollment = paid external enroll + SMS
 * + email per hit) and the expensive geocode/address routes. So an attacker
 * could send a FRESH spoofed header on every request, rotate the bucket key, and
 * the throttle would never trip.
 *
 * The fix: default to REMOTE_ADDR (the unspoofable TCP peer) and only honor the
 * forwarded headers when REMOTE_ADDR is an admin-configured trusted proxy; when
 * honoring X-Forwarded-For, take the RIGHT-MOST untrusted hop, not the left-most.
 *
 * These tests pin both halves:
 *   - untrusted peer  => forwarded headers are IGNORED, bucket keys on REMOTE_ADDR
 *   - trusted proxy   => forwarded value is honored (CF single value; XFF rightmost hop)
 *
 * Seam: get_client_ip() is a static that reads $_SERVER and get_option('isf_settings').
 * Brain Monkey stubs get_option so the resolver runs WordPress-free.
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use ISF\Security;

final class ClientIpTrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_SERVER = [];
        // Default: no trusted proxies configured.
        Functions\when('get_option')->justReturn([]);
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Configure the isf_settings trusted_proxies allowlist for a test.
     *
     * @param array<int,string> $proxies
     */
    private function withTrustedProxies(array $proxies): void
    {
        Functions\when('get_option')->alias(function ($name, $default = false) use ($proxies) {
            if ($name === 'isf_settings') {
                return ['trusted_proxies' => $proxies];
            }
            return $default;
        });
    }

    public function test_spoofed_xff_is_ignored_when_remote_addr_is_untrusted(): void
    {
        $_SERVER['REMOTE_ADDR']          = '203.0.113.9'; // real peer, not a trusted proxy
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';     // attacker-supplied

        $this->assertSame('203.0.113.9', Security::get_client_ip());
    }

    public function test_spoofed_cf_connecting_ip_is_ignored_when_remote_addr_is_untrusted(): void
    {
        $_SERVER['REMOTE_ADDR']           = '203.0.113.9';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '9.9.9.9';

        $this->assertSame('203.0.113.9', Security::get_client_ip());
    }

    public function test_rotating_spoofed_headers_do_not_rotate_the_rate_limit_bucket(): void
    {
        // The core exploit: a fresh spoofed header per request must NOT change
        // the bucket the throttle keys on. We drive the real check_rate_limit()
        // and capture the transient key it writes.
        $keys = [];
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->alias(function ($key) use (&$keys) {
            $keys[] = $key;
            return true;
        });

        $_SERVER['REMOTE_ADDR']          = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1';
        Security::check_rate_limit();

        // Attacker rotates the spoofed header on the next request.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '2.2.2.2';
        Security::check_rate_limit();

        $this->assertCount(2, $keys);
        $this->assertSame(
            $keys[0],
            $keys[1],
            'Spoofed X-Forwarded-For rotated the rate-limit bucket key — throttle defeatable.'
        );
        $this->assertSame('isf_rate_' . md5('203.0.113.9'), $keys[0]);
    }

    public function test_trusted_proxy_honors_cf_connecting_ip(): void
    {
        $this->withTrustedProxies(['10.0.0.1']);
        $_SERVER['REMOTE_ADDR']           = '10.0.0.1';      // the trusted proxy
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';  // real client per Cloudflare

        $this->assertSame('198.51.100.7', Security::get_client_ip());
    }

    public function test_trusted_proxy_honors_rightmost_untrusted_xff_hop(): void
    {
        // Attacker prepends a fake left-most value; the trusted proxy (10.0.0.1)
        // appended the address it actually observed (203.0.113.5) on the right.
        $this->withTrustedProxies(['10.0.0.1']);
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.5';

        // Right-most untrusted hop is the genuine client — NOT explode(',')[0].
        $this->assertSame('203.0.113.5', Security::get_client_ip());
    }

    public function test_trusted_proxy_falls_back_to_peer_when_no_usable_forwarded_value(): void
    {
        $this->withTrustedProxies(['10.0.0.1']);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        // No CF / XFF headers present.

        $this->assertSame('10.0.0.1', Security::get_client_ip());
    }

    public function test_constant_allowlist_is_honored(): void
    {
        if (!defined('ISF_TRUSTED_PROXIES')) {
            define('ISF_TRUSTED_PROXIES', '172.16.0.5');
        }
        $_SERVER['REMOTE_ADDR']           = '172.16.0.5';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.20';

        $this->assertSame('198.51.100.20', Security::get_client_ip());
    }

    public function test_invalid_remote_addr_returns_sentinel(): void
    {
        $_SERVER['REMOTE_ADDR']          = 'not-an-ip';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $this->assertSame('0.0.0.0', Security::get_client_ip());
    }
}

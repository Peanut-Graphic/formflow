<?php
/**
 * Regression guard (audit 2026-07, F4) — every client-IP resolver in the plugin
 * must delegate to the hardened, trusted-proxy-aware Security::get_client_ip().
 *
 * Two secondary resolvers used to trust the FIRST value of the attacker-
 * controlled CF-Connecting-IP / X-Forwarded-For / X-Real-IP headers with no
 * trusted-proxy allowlist:
 *   - ISF\Platform\APIPlatform::get_client_ip()  — keys the API-key allowed_ips
 *     allowlist, so spoofing X-Forwarded-For bypassed the IP restriction.
 *   - ISF\SecurityHardening::get_client_ip()     — keys security logs + IP blocks.
 *
 * Both now delegate to Security::get_client_ip(). These source-level guards
 * fail loudly if anyone reintroduces a direct forwarded-header read in either.
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class ClientIpResolverDelegationTest extends TestCase
{
    private function resolverBody(string $relPath): string
    {
        $src = file_get_contents(ISF_PLUGIN_DIR . $relPath);
        $this->assertNotFalse($src, "cannot read $relPath");
        // Isolate the get_client_ip() method body.
        $start = strpos($src, 'function get_client_ip(');
        $this->assertNotFalse($start, "get_client_ip missing from $relPath");
        // Body ends at the next method or two-space closing brace after $start.
        $slice = substr($src, $start, 800);
        return $slice;
    }

    public function test_api_platform_delegates_to_hardened_resolver(): void
    {
        $body = $this->resolverBody('includes/platform/class-api-platform.php');
        $this->assertMatchesRegularExpression(
            '/Security::get_client_ip\(\)/',
            $body,
            'APIPlatform::get_client_ip must delegate to Security::get_client_ip (F4).'
        );
        $this->assertStringNotContainsString(
            'HTTP_X_FORWARDED_FOR',
            $body,
            'APIPlatform::get_client_ip must NOT read X-Forwarded-For directly — that is the spoof bypass (F4).'
        );
    }

    public function test_security_hardening_delegates_to_hardened_resolver(): void
    {
        $body = $this->resolverBody('includes/class-security-hardening.php');
        $this->assertMatchesRegularExpression(
            '/Security::get_client_ip\(\)/',
            $body,
            'SecurityHardening::get_client_ip must delegate to Security::get_client_ip (F4).'
        );
        $this->assertStringNotContainsString(
            'HTTP_CF_CONNECTING_IP',
            $body,
            'SecurityHardening::get_client_ip must NOT read CF-Connecting-IP directly (F4).'
        );
    }
}

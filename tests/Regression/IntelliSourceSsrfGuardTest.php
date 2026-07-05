<?php
/**
 * Regression guard: IntelliSource connector must refuse SSRF targets.
 *
 * The IntelliSOURCE api_endpoint is admin-configured and was previously
 * validated only with FILTER_VALIDATE_URL, which accepts http://127.0.0.1,
 * http://169.254.169.254 (cloud metadata) and RFC1918 hosts. make_request()
 * then fetched it, letting an admin-set (or config-poisoned) endpoint pivot the
 * server into internal infrastructure.
 *
 * These tests exercise the REAL private make_request() via reflection and prove
 * that loopback / private / link-local / reserved / non-http(s) targets are
 * blocked BEFORE any outbound HTTP call, while a public host still goes through.
 *
 * Self-contained: WordPress functions the connector touches on these code paths
 * are shimmed in the connector's own namespace (unqualified calls resolve there
 * first), so the test needs neither a booted WordPress nor Brain Monkey. Every
 * probe uses an IP-literal host, so no DNS resolution occurs.
 */

namespace ISF\Connectors\IntelliSource;

// --- Namespaced WordPress shims (resolved before the global namespace) --------
if (!function_exists(__NAMESPACE__ . '\\wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return \parse_url($url, $component);
    }
}
if (!function_exists(__NAMESPACE__ . '\\__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}
// Reached only on the "allowed" (public-host) path — asserts the guard let the
// request continue to the (stubbed) HTTP layer instead of throwing.
if (!function_exists(__NAMESPACE__ . '\\wp_remote_request')) {
    function wp_remote_request($url, $args = [])
    {
        $GLOBALS['__isf_ssrf_test_remote_called'] = true;
        return ['stubbed' => true];
    }
}
if (!function_exists(__NAMESPACE__ . '\\is_wp_error')) {
    function is_wp_error($thing)
    {
        return false;
    }
}
if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return 200;
    }
}
if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return 'OK';
    }
}

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use ISF\Connectors\IntelliSource\IntelliSourceConnector;

final class IntelliSourceSsrfGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The connector lives under connectors/ (outside the includes/ PSR-4
        // root), so load it — and the interface it implements — explicitly.
        require_once dirname(__DIR__, 2) . '/includes/api/interface-api-connector.php';
        require_once dirname(__DIR__, 2) . '/connectors/intellisource/class-intellisource-connector.php';

        $GLOBALS['__isf_ssrf_test_remote_called'] = false;
    }

    /**
     * @dataProvider blockedEndpointProvider
     */
    public function test_make_request_blocks_ssrf_targets(string $endpoint): void
    {
        $connector = new IntelliSourceConnector();

        $threw = false;
        try {
            $this->invokeMakeRequest($connector, $endpoint);
        } catch (\Exception $e) {
            $threw = true;
        }

        $this->assertTrue(
            $threw,
            "Endpoint {$endpoint} should have been blocked by the SSRF guard."
        );
        $this->assertFalse(
            $GLOBALS['__isf_ssrf_test_remote_called'],
            "wp_remote_request() must NOT be called for blocked endpoint {$endpoint}."
        );
    }

    public static function blockedEndpointProvider(): array
    {
        return [
            'loopback'            => ['http://127.0.0.1/phiIntelliSOURCE/api'],
            'loopback-hi'         => ['http://127.255.255.254/api'],
            'private-10'          => ['http://10.0.0.5/api'],
            'private-172'         => ['http://172.16.0.9/api'],
            'private-192'         => ['http://192.168.1.10/api'],
            'link-local-metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6-loopback'       => ['http://[::1]/api'],
            'non-http-scheme'     => ['file:///etc/passwd'],
            'gopher-scheme'       => ['gopher://127.0.0.1:11211/'],
        ];
    }

    public function test_make_request_allows_public_endpoint(): void
    {
        $connector = new IntelliSourceConnector();

        // Public IP literal (Google DNS) — no private/reserved range, no DNS
        // lookup needed. The guard should pass and hand off to the stubbed
        // wp_remote_request(), returning its (parse-skipped) body.
        $result = $this->invokeMakeRequest($connector, 'https://8.8.8.8/phiIntelliSOURCE/api');

        $this->assertTrue(
            $GLOBALS['__isf_ssrf_test_remote_called'],
            'A public endpoint must still reach the HTTP layer.'
        );
        $this->assertSame('OK', $result);
    }

    /**
     * Invoke the private make_request() with parse_xml disabled.
     *
     * @return array|string
     */
    private function invokeMakeRequest(IntelliSourceConnector $connector, string $endpoint)
    {
        $ref = new \ReflectionMethod($connector, 'make_request');
        $ref->setAccessible(true);

        return $ref->invoke($connector, $endpoint, '/promo_codes', ['pswd' => 'x'], 'GET', false);
    }
}

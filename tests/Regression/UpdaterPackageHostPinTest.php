<?php
/**
 * Regression guard: updater must pin the package download host.
 *
 * class-updater.php installs whatever download_url the license server returns.
 * A compromised / spoofed / MITM'd response could point WordPress at an
 * attacker-controlled zip that then runs as plugin code. As an interim
 * supply-chain stopgap (the durable fix — cryptographic package-signature
 * verification — is Nat-gated and lockstep with peanut-license-server) the
 * updater now refuses any package URL that is not HTTPS on the expected Peanut
 * update host.
 *
 * This test exercises the REAL private assert_trusted_package_url() via
 * reflection and proves rogue hosts / non-HTTPS / scheme tricks are rejected
 * (returned as '' so the WP updater simply offers no package), while the
 * legitimate peanutgraphic.com host (and its subdomains) is accepted.
 *
 * Self-contained: wp_parse_url() is shimmed in the ISF namespace, so no booted
 * WordPress or Brain Monkey is required.
 */

namespace ISF;

if (!function_exists(__NAMESPACE__ . '\\wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return \parse_url($url, $component);
    }
}

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use ISF\Updater;

final class UpdaterPackageHostPinTest extends TestCase
{
    private function pin(string $url): string
    {
        $updater = new Updater();
        $ref = new \ReflectionMethod($updater, 'assert_trusted_package_url');
        $ref->setAccessible(true);

        return $ref->invoke($updater, $url);
    }

    /**
     * @dataProvider untrustedUrlProvider
     */
    public function test_rejects_untrusted_package_urls(string $url): void
    {
        $this->assertSame(
            '',
            $this->pin($url),
            "Untrusted package URL must be rejected (blanked): {$url}"
        );
    }

    public static function untrustedUrlProvider(): array
    {
        return [
            'rogue-host'          => ['https://evil.example.com/formflow.zip'],
            'http-not-https'      => ['http://peanutgraphic.com/formflow.zip'],
            'lookalike-suffix'    => ['https://evilpeanutgraphic.com/formflow.zip'],
            'host-in-path-only'   => ['https://attacker.com/peanutgraphic.com/formflow.zip'],
            'userinfo-spoof'      => ['https://peanutgraphic.com@attacker.com/formflow.zip'],
            'empty'               => [''],
        ];
    }

    /**
     * @dataProvider trustedUrlProvider
     */
    public function test_accepts_trusted_package_urls(string $url): void
    {
        $this->assertSame(
            $url,
            $this->pin($url),
            "Legitimate Peanut package URL must be accepted unchanged: {$url}"
        );
    }

    public static function trustedUrlProvider(): array
    {
        return [
            'apex'      => ['https://peanutgraphic.com/wp-json/peanut-api/v1/updates/download?plugin=formflow'],
            'subdomain' => ['https://downloads.peanutgraphic.com/formflow-4.0.7.zip'],
        ];
    }
}

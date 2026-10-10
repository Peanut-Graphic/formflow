<?php
/**
 * LicenseIpWhitelistSpoofTest — license IP whitelist trusted forwarded headers
 * (audit 2026-10, LOW).
 *
 * BEFORE: LicenseManager::get_client_ip() returned the first value of
 * CF-Connecting-IP / X-Forwarded-For / X-Real-IP, so any visitor could claim a
 * whitelisted IP and unlock Pro features (is_pro()) without a license.
 * AFTER: it delegates to Security::get_client_ip(), which only honors forwarded
 * headers from configured trusted proxies.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Regression;

use Brain\Monkey\Functions;
use ISF\LicenseManager;
use ISF\Tests\Unit\TestCase;

final class LicenseIpWhitelistSpoofTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return $key === LicenseManager::OPTION_WHITELIST_IPS ? ['198.51.100.7'] : $default;
        });
        require_once ISF_PLUGIN_DIR . 'includes/class-license-manager.php';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    private function manager(): LicenseManager
    {
        return (new \ReflectionClass(LicenseManager::class))->newInstanceWithoutConstructor();
    }

    public function test_spoofed_forwarded_headers_do_not_match_the_whitelist(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
        $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.7';

        $this->assertFalse($this->manager()->is_ip_whitelisted(),
            'A client that is not behind a trusted proxy must not be able to claim a whitelisted IP via headers.');
    }

    public function test_real_peer_address_still_matches(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP']);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        $this->assertTrue($this->manager()->is_ip_whitelisted());
    }

    public function test_resolver_delegates_to_hardened_security_resolver(): void
    {
        $src = file_get_contents(ISF_PLUGIN_DIR . 'includes/class-license-manager.php');
        $body = substr($src, strpos($src, 'function get_client_ip('), 400);
        $this->assertStringContainsString('Security::get_client_ip()', $body);
        $this->assertStringNotContainsString('HTTP_X_FORWARDED_FOR', $body);
    }
}

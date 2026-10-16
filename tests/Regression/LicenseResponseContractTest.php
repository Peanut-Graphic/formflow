<?php
namespace ISF\Tests\Regression;

use Brain\Monkey\Functions;
use ISF\LicenseManager;
use ISF\Tests\Unit\TestCase;

final class LicenseResponseContractTest extends TestCase
{
    /** @dataProvider rejectedResponses */
    public function test_errors_are_explicit_denials(int $status, string $body, string $error): void
    {
        require_once ISF_PLUGIN_DIR . 'includes/class-license-manager.php';
        Functions\when('get_bloginfo')->justReturn('Synthetic site');
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
        $manager = (new \ReflectionClass(LicenseManager::class))->newInstanceWithoutConstructor();
        $result = (new \ReflectionMethod($manager, 'validate_license_remote'))->invoke($manager, 'synthetic-key', true);
        $this->assertFalse($result['success']);
        $this->assertSame($error, $result['error']);
    }

    public static function rejectedResponses(): array
    {
        return [
            [403, '{"code":"license_restricted","message":"Denied","data":{"status":403}}', 'license_restricted'],
            [400, '{"success":false,"error":"license_restricted"}', 'license_restricted'],
            [502, '<html>Bad gateway</html>', 'invalid_license_response'],
            [200, 'true', 'invalid_license_response'],
            [200, '["unexpected"]', 'invalid_license_response'],
            [200, '{"success":true}', 'invalid_license_response'],
            [200, '{"success":true,"license":"invalid"}', 'invalid_license_response'],
            [500, '{"success":true,"license":{"status":"active"}}', 'invalid_license_response'],
        ];
    }

    public function test_valid_status_response_keeps_header_transport(): void
    {
        require_once ISF_PLUGIN_DIR . 'includes/class-license-manager.php';
        Functions\expect('wp_remote_get')->once()->withArgs(function ($url, $args) {
            $this->assertStringNotContainsString('synthetic-key', $url);
            $this->assertSame('synthetic-key', $args['headers']['X-Peanut-License-Key']);
            return true;
        })->andReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        $data = ['success' => true, 'license' => ['status' => 'active', 'tier' => 'pro']];
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode($data));
        $manager = (new \ReflectionClass(LicenseManager::class))->newInstanceWithoutConstructor();
        $this->assertSame($data, (new \ReflectionMethod($manager, 'validate_license_remote'))->invoke($manager, 'synthetic-key', false));
    }
}

<?php
/**
 * Base Test Case for Unit Tests
 *
 * Provides common setup and mocking utilities for unit tests.
 * Uses Brain Monkey to mock WordPress functions.
 */

namespace ISF\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Set up Brain Monkey before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Mock common WordPress functions
        $this->mockWordPressFunctions();
    }

    /**
     * Tear down Brain Monkey after each test
     */
    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Mock common WordPress functions used throughout the plugin
     */
    protected function mockWordPressFunctions(): void
    {
        // Sanitization functions
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('sanitize_title')->alias(function ($title) {
            return strtolower(str_replace(' ', '-', $title));
        });
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();

        // Translation functions
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('_e')->echoArg();

        // JSON functions
        Functions\when('wp_json_encode')->alias('json_encode');

        // Cryptographic salt — Encryption::get_encryption_key() falls back to
        // wp_salt('auth') when no ISF_ENCRYPTION_KEY constant is defined. Provide
        // a deterministic, fixed salt so encryption-backed code paths are testable
        // without booting WordPress.
        Functions\when('wp_salt')->alias(function ($scheme = 'auth') {
            return 'isf-test-salt-' . $scheme;
        });

        // URL functions
        Functions\when('home_url')->justReturn('http://example.com');
        Functions\when('admin_url')->alias(function ($path = '') {
            return 'http://example.com/wp-admin/' . $path;
        });
        Functions\when('rest_url')->alias(function ($path = '') {
            return 'http://example.com/wp-json/' . $path;
        });
        Functions\when('add_query_arg')->alias(function ($args, $url = '') {
            if (is_array($args)) {
                $query = http_build_query($args);
            } else {
                $query = $args . '=' . $url;
                $url = func_get_arg(2) ?? '';
            }
            $separator = strpos($url, '?') !== false ? '&' : '?';
            return $url . $separator . $query;
        });

        // SSL check
        Functions\when('is_ssl')->justReturn(false);

        // Time functions
        Functions\when('current_time')->alias(function ($type) {
            return $type === 'mysql' ? date('Y-m-d H:i:s') : time();
        });

        // Options
        Functions\when('get_option')->justReturn([]);

        // Capabilities
        Functions\when('current_user_can')->justReturn(true);

        // User functions
        Functions\when('get_current_user_id')->justReturn(1);

        // Transients (used for throttle/cache gates). Default to a cache miss
        // so code under test exercises its real path; set/delete are no-ops.
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
    }

    /**
     * Mock the global $wpdb object
     *
     * @param array $methods Methods to mock with return values
     * @return \Mockery\MockInterface
     */
    protected function mockWpdb(array $methods = []): \Mockery\MockInterface
    {
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        foreach ($methods as $method => $return) {
            if (is_callable($return)) {
                $wpdb->shouldReceive($method)->andReturnUsing($return);
            } else {
                $wpdb->shouldReceive($method)->andReturn($return);
            }
        }

        // Default prepare to return the query with values substituted
        if (!isset($methods['prepare'])) {
            $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
                return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
            });
        }

        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }

    /**
     * Create a mock Database instance
     *
     * @return \Mockery\MockInterface
     */
    protected function mockDatabase(): \Mockery\MockInterface
    {
        return \Mockery::mock('ISF\Database\Database');
    }

    /**
     * Assert that an array has expected keys
     *
     * @param array $expectedKeys
     * @param array $actual
     */
    protected function assertArrayHasKeys(array $expectedKeys, array $actual): void
    {
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $actual, "Array missing expected key: {$key}");
        }
    }

    /**
     * Get a sample visitor data array
     */
    protected function getSampleVisitorData(): array
    {
        return [
            'id' => 1,
            'visitor_id' => 'abc123def456789012345678901234567',
            'fingerprint_hash' => 'hash123',
            'first_seen_at' => '2024-01-01 10:00:00',
            'last_seen_at' => '2024-01-15 14:30:00',
            'visit_count' => 5,
            'first_touch' => json_encode([
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'winter_promo',
            ]),
            'device_info' => json_encode([
                'browser' => 'Chrome',
                'os' => 'Windows',
                'is_mobile' => false,
            ]),
        ];
    }

    /**
     * Get a sample touch data array
     */
    protected function getSampleTouchData(): array
    {
        return [
            'id' => 1,
            'visitor_id' => 'abc123def456789012345678901234567',
            'instance_id' => 1,
            'touch_type' => 'form_view',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'winter_promo',
            'utm_term' => null,
            'utm_content' => null,
            'gclid' => 'gclid123',
            'fbclid' => null,
            'msclkid' => null,
            'referrer' => 'https://google.com',
            'referrer_domain' => 'google.com',
            'landing_page' => 'http://example.com/enroll',
            'page_url' => 'http://example.com/enroll',
            'promo_code' => null,
            'touch_data' => '{}',
            'created_at' => '2024-01-15 14:30:00',
        ];
    }

    /**
     * Get a sample handoff data array
     */
    protected function getSampleHandoffData(): array
    {
        return [
            'id' => 1,
            'instance_id' => 1,
            'visitor_id' => 'abc123def456789012345678901234567',
            'handoff_token' => 'token123456789012345678901234567',
            'destination_url' => 'https://external-enrollment.com/enroll',
            'attribution' => json_encode([
                'utm_source' => 'email',
                'utm_campaign' => 'spring_campaign',
            ]),
            'status' => 'redirected',
            'account_number' => null,
            'external_id' => null,
            'completion_data' => null,
            'created_at' => '2024-01-15 14:30:00',
            'completed_at' => null,
        ];
    }
}

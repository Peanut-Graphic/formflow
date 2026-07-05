<?php
/**
 * Regression guard — hardcoded license-bypass key removed.
 *
 * An earlier revision shipped a hardcoded admin key,
 * LicenseManager::ADMIN_TEST_KEY = 'FFTEST-ADMIN-DEV-MODE'. Anyone who typed
 * that literal string into the license-key field (or set it as the stored
 * `formflow_license_key` option) had every Pro/Agency feature unlocked for
 * free: is_admin_testing_mode() short-circuited to true, is_pro() rode on top
 * of it, and activate_license() minted an 'agency' license locally without ever
 * contacting the license server.
 *
 * The fix deletes the constant and every branch that special-cased it. The only
 * remaining operator escape hatch is the wp-config-defined FORMFLOW_ADMIN_KEY
 * constant (an install-owned secret), which is intentionally kept.
 *
 * These tests pin the removal: the FFTEST string must be treated as an ordinary,
 * unrecognised license key — no admin-testing mode, no Pro unlock, no local
 * activation.
 *
 * Seam: LicenseManager reads/writes WordPress options and talks to the license
 * server over wp_remote_*. Brain Monkey stubs those so the manager runs
 * WordPress-free; the instance is built via reflection (the ctor is private) and
 * its license_data is seeded directly so we exercise the key-checking logic in
 * isolation.
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use ISF\LicenseManager;
use ReflectionClass;

final class LicenseAdminBypassKeyTest extends TestCase
{
    private const REMOVED_KEY = 'FFTEST-ADMIN-DEV-MODE';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Minimal WordPress surface the manager touches.
        Functions\when('__')->returnArg(1);
        Functions\when('sanitize_text_field')->alias(static fn ($v) => is_string($v) ? trim($v) : $v);
        Functions\when('get_option')->alias(static fn ($name, $default = false) => $default);
        Functions\when('update_option')->justReturn(true);
        Functions\when('home_url')->justReturn('https://example.test');
        Functions\when('get_bloginfo')->justReturn('Example');
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        // Simulate the license server being unreachable so activation cannot
        // succeed via the legitimate remote path — isolating the point under
        // test: the FFTEST key must not short-circuit BEFORE the remote call.
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 0]]);
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 0]]);
        Functions\when('is_wp_error')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Build a LicenseManager with a given stored license key, bypassing the
     * private singleton constructor and seeding license_data directly.
     */
    private function managerWithKey(string $key): LicenseManager
    {
        $ref = new ReflectionClass(LicenseManager::class);
        /** @var LicenseManager $manager */
        $manager = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('license_data');
        $prop->setValue($manager, [
            'key'        => $key,
            'data'       => [],
            'last_check' => 0,
        ]);

        return $manager;
    }

    public function test_removed_constant_no_longer_exists(): void
    {
        $this->assertFalse(
            defined(LicenseManager::class . '::ADMIN_TEST_KEY'),
            'LicenseManager::ADMIN_TEST_KEY was re-introduced — the hardcoded bypass is back.'
        );
    }

    public function test_fftest_key_does_not_enable_admin_testing_mode(): void
    {
        $manager = $this->managerWithKey(self::REMOVED_KEY);

        $this->assertFalse(
            $manager->is_admin_testing_mode(),
            'FFTEST-ADMIN-DEV-MODE still flips admin-testing mode — the bypass is live.'
        );
    }

    public function test_fftest_key_does_not_grant_pro(): void
    {
        $manager = $this->managerWithKey(self::REMOVED_KEY);

        $this->assertFalse(
            $manager->is_pro(),
            'FFTEST-ADMIN-DEV-MODE still reports a Pro license.'
        );
    }

    public function test_fftest_key_does_not_unlock_a_pro_feature(): void
    {
        $manager = $this->managerWithKey(self::REMOVED_KEY);

        // 'external_enrollment' is a Pro-tier feature (see PRO_FEATURES).
        $this->assertFalse(
            $manager->has_feature('external_enrollment'),
            'FFTEST-ADMIN-DEV-MODE still unlocks a Pro feature for free.'
        );
    }

    public function test_activating_fftest_key_does_not_locally_mint_a_license(): void
    {
        $manager = $this->managerWithKey('');

        $result = $manager->activate_license(self::REMOVED_KEY);

        $this->assertIsArray($result);
        $this->assertFalse(
            $result['success'] ?? true,
            'Activating with FFTEST-ADMIN-DEV-MODE succeeded — the local dev-mode bypass survived.'
        );
        $this->assertNotSame(
            'Development mode activated. All features unlocked.',
            $result['message'] ?? '',
            'The removed dev-mode activation branch is still being taken.'
        );
    }
}

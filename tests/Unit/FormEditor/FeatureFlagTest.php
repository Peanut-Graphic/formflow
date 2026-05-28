<?php
namespace ISF\Tests\Unit\FormEditor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ISF\FormEditor\FeatureFlag;

class FeatureFlagTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_constant_true_enables(): void {
        define('ISF_NEW_EDITOR', true);
        $this->assertTrue(FeatureFlag::is_enabled());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_constant_false_disables_even_when_option_enabled(): void {
        define('ISF_NEW_EDITOR', false);
        Functions\when('get_option')->justReturn('1');
        $this->assertFalse(FeatureFlag::is_enabled());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_option_path_via_is_enabled(): void {
        // ISF_NEW_EDITOR is intentionally NOT defined in this process.
        Functions\when('get_option')->justReturn('1');
        $this->assertTrue(FeatureFlag::is_enabled());
    }

    public function test_is_option_enabled_returns_true_when_option_is_one(): void {
        Functions\when('get_option')->justReturn('1');
        $this->assertTrue(FeatureFlag::is_option_enabled());
    }

    public function test_is_option_enabled_returns_false_when_option_is_zero(): void {
        Functions\when('get_option')->justReturn('0');
        $this->assertFalse(FeatureFlag::is_option_enabled());
    }
}

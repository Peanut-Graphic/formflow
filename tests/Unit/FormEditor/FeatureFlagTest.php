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

    public function test_constant_true_enables(): void {
        if (!defined('ISF_NEW_EDITOR')) define('ISF_NEW_EDITOR', true);
        $this->assertTrue(FeatureFlag::is_enabled());
    }

    public function test_option_fallback_when_constant_undefined(): void {
        Functions\when('get_option')->justReturn('1');
        $this->assertTrue(FeatureFlag::is_enabled_via_option_for_test('isf_new_editor'));
    }

    public function test_option_off_returns_false(): void {
        Functions\when('get_option')->justReturn('0');
        $this->assertFalse(FeatureFlag::is_enabled_via_option_for_test('isf_new_editor'));
    }
}

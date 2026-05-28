<?php
namespace ISF\Tests\Unit\FormEditor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ISF\FormEditor\ModeResolver;

class ModeResolverTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('get_current_user_id')->justReturn(1);
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_user_with_dev_cap_defaults_to_dev_mode(): void {
        Functions\when('current_user_can')->alias(fn ($cap) => $cap === 'isf_dev_mode');
        Functions\when('get_user_meta')->justReturn('');
        $this->assertSame(ModeResolver::MODE_DEV, ModeResolver::effective_mode());
    }

    public function test_user_without_dev_cap_gets_client_mode(): void {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_user_meta')->justReturn('');
        $this->assertSame(ModeResolver::MODE_CLIENT, ModeResolver::effective_mode());
    }

    public function test_user_with_dev_cap_can_prefer_client_mode(): void {
        Functions\when('current_user_can')->alias(fn ($cap) => $cap === 'isf_dev_mode');
        Functions\when('get_user_meta')->justReturn('client');
        Functions\when('get_current_user_id')->justReturn(1);
        $this->assertSame(ModeResolver::MODE_CLIENT, ModeResolver::effective_mode());
    }

    public function test_user_without_dev_cap_cannot_force_dev_via_meta(): void {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_user_meta')->justReturn('dev');
        Functions\when('get_current_user_id')->justReturn(1);
        $this->assertSame(ModeResolver::MODE_CLIENT, ModeResolver::effective_mode());
    }

    public function test_has_both_modes_true_when_user_has_dev_cap(): void {
        Functions\when('current_user_can')->alias(fn ($cap) => $cap === 'isf_dev_mode');
        $this->assertTrue(ModeResolver::has_both_modes());
    }

    public function test_has_both_modes_false_without_dev_cap(): void {
        Functions\when('current_user_can')->justReturn(false);
        $this->assertFalse(ModeResolver::has_both_modes());
    }
}

<?php
namespace ISF\Tests\Unit\FormEditor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ISF\FormEditor\Capabilities;
use Mockery;

class CapabilitiesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_register_on_activate_adds_dev_cap_to_admin_role(): void {
        $admin_role = Mockery::mock('WP_Role');
        $admin_role->shouldReceive('add_cap')->once()->with(Capabilities::DEV_MODE);
        Functions\when('get_role')->justReturn($admin_role);

        Capabilities::register_on_activate();

        $this->assertTrue(true); // assertion is in the Mockery expectation above
    }

    public function test_dev_mode_cap_name(): void {
        $this->assertSame('isf_dev_mode', Capabilities::DEV_MODE);
    }
}

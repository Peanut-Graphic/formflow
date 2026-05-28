<?php
namespace ISF\Tests\Unit\FormEditor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ISF\FormEditor\Router;
use ISF\FormEditor\ModeResolver;

class RouterTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_no_task_returns_overview(): void {
        $r = new Router(['id'=>5], ModeResolver::MODE_DEV);
        $this->assertSame('overview', $r->resolved_view());
    }

    public function test_known_task_returns_task_slug(): void {
        $r = new Router(['id'=>5, 'task'=>'delivery'], ModeResolver::MODE_DEV);
        $this->assertSame('delivery', $r->resolved_view());
    }

    public function test_unknown_task_returns_no_task_view(): void {
        $r = new Router(['id'=>5, 'task'=>'banana'], ModeResolver::MODE_DEV);
        $this->assertSame('no-task', $r->resolved_view());
    }

    public function test_task_blocked_by_mode_returns_no_task(): void {
        // 'advanced' is dev-only; client mode shouldn't reach it
        $r = new Router(['id'=>5, 'task'=>'advanced'], ModeResolver::MODE_CLIENT);
        $this->assertSame('no-task', $r->resolved_view());
    }

    public function test_instance_id(): void {
        $r = new Router(['id'=>'42'], ModeResolver::MODE_DEV);
        $this->assertSame(42, $r->instance_id());
    }

    public function test_zero_instance_id_when_missing(): void {
        $r = new Router([], ModeResolver::MODE_DEV);
        $this->assertSame(0, $r->instance_id());
    }
}

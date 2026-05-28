<?php
namespace ISF\Tests\Unit\FormEditor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ISF\FormEditor\TaskRegistry;
use ISF\FormEditor\ModeResolver;

class TaskRegistryTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_dev_mode_lists_nine_tasks(): void {
        $tasks = TaskRegistry::tasks_for_mode(ModeResolver::MODE_DEV);
        $slugs = array_keys($tasks);
        $this->assertCount(9, $slugs);
        $this->assertSame(
            ['setup','fields','connector','delivery','scheduling','copy','notifications','tracking','advanced'],
            $slugs
        );
    }

    public function test_client_mode_lists_four_tasks(): void {
        $tasks = TaskRegistry::tasks_for_mode(ModeResolver::MODE_CLIENT);
        $slugs = array_keys($tasks);
        $this->assertCount(4, $slugs);
        $this->assertSame(
            ['delivery','copy','notifications','submissions'],
            $slugs
        );
    }

    public function test_each_task_has_required_keys(): void {
        $tasks = TaskRegistry::tasks_for_mode(ModeResolver::MODE_DEV);
        foreach ($tasks as $slug => $def) {
            $this->assertIsString($def['title'] ?? null, "$slug missing title");
            $this->assertIsString($def['icon'] ?? null, "$slug missing icon");
            $this->assertIsString($def['view'] ?? null, "$slug missing view path");
        }
    }

    public function test_task_visible_for_form_type_respects_conditional(): void {
        // connector is hidden when form_type === 'custom'
        $this->assertFalse(
            TaskRegistry::is_visible_for_form_type('connector', 'custom'),
            'connector should hide on custom forms'
        );
        $this->assertTrue(
            TaskRegistry::is_visible_for_form_type('connector', 'enrollment'),
            'connector should show on enrollment forms'
        );
        $this->assertTrue(
            TaskRegistry::is_visible_for_form_type('copy', 'custom'),
            'copy is always visible'
        );
    }
}

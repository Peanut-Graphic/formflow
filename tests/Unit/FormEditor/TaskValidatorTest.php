<?php
namespace ISF\Tests\Unit\FormEditor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ISF\FormEditor\TaskValidator;

class TaskValidatorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_setup_ok_when_all_required_set(): void {
        $instance = ['name'=>'Dominion PTR', 'slug'=>'dominion', 'form_type'=>'custom', 'settings'=>[]];
        $this->assertSame(TaskValidator::STATUS_OK, TaskValidator::status_for('setup', $instance));
    }

    public function test_setup_attention_when_name_missing(): void {
        $instance = ['name'=>'', 'slug'=>'dominion', 'form_type'=>'custom', 'settings'=>[]];
        $this->assertSame(TaskValidator::STATUS_ATTENTION, TaskValidator::status_for('setup', $instance));
    }

    public function test_delivery_attention_when_active_destination_missing_required(): void {
        $instance = ['form_type'=>'custom', 'settings'=>['destinations'=>[[
            'type'=>'sftp','is_active'=>true,'config'=>['host'=>'','username'=>'']
        ]]]];
        $this->assertSame(TaskValidator::STATUS_ATTENTION, TaskValidator::status_for('delivery', $instance));
    }

    public function test_delivery_attention_when_no_destinations(): void {
        $instance = ['form_type'=>'custom', 'settings'=>[]];
        $this->assertSame(TaskValidator::STATUS_ATTENTION, TaskValidator::status_for('delivery', $instance));
    }

    public function test_connector_na_on_custom_form(): void {
        $instance = ['form_type'=>'custom', 'settings'=>[]];
        $this->assertSame(TaskValidator::STATUS_NA, TaskValidator::status_for('connector', $instance));
    }

    public function test_fields_ok_when_step_has_fields(): void {
        $instance = ['settings'=>['form_schema'=>[
            'steps'=>[['id'=>'s1','fields'=>[['type'=>'text','name'=>'first']]]]
        ]]];
        $this->assertSame(TaskValidator::STATUS_OK, TaskValidator::status_for('fields', $instance));
    }

    public function test_fields_attention_when_no_fields(): void {
        $instance = ['settings'=>['form_schema'=>['steps'=>[]]]];
        $this->assertSame(TaskValidator::STATUS_ATTENTION, TaskValidator::status_for('fields', $instance));
    }

    public function test_tracking_defaults_when_neither_enabled(): void {
        $instance = ['settings'=>[]];
        $this->assertSame(TaskValidator::STATUS_DEFAULTS, TaskValidator::status_for('tracking', $instance));
    }
}

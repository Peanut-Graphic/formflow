<?php
namespace ISF\Tests\Unit\FormEditor;

use PHPUnit\Framework\TestCase;
use ISF\FormEditor\FieldGate;
use ISF\FormEditor\ModeResolver;

// This is a plain PHPUnit test (no Brain Monkey), so wp_json_encode() is not
// stubbed. Define a namespace-local polyfill so the fixtures below resolve
// regardless of whether a global wp_json_encode() exists in the run.
if (!function_exists(__NAMESPACE__ . '\\wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

class FieldGateTest extends TestCase {

    public function test_dev_mode_passes_all_fields_through(): void {
        $posted = ['name'=>'X', 'slug'=>'x', 'form_type'=>'enrollment', 'api_password'=>'secret'];
        $filtered = FieldGate::strip_blocked_fields($posted, ModeResolver::MODE_DEV);
        $this->assertSame($posted, $filtered);
    }

    public function test_client_mode_strips_dev_only_top_level_fields(): void {
        $posted = ['name'=>'X', 'slug'=>'x', 'form_type'=>'enrollment', 'api_password'=>'secret'];
        $filtered = FieldGate::strip_blocked_fields($posted, ModeResolver::MODE_CLIENT);
        $this->assertArrayNotHasKey('name', $filtered);
        $this->assertArrayNotHasKey('slug', $filtered);
        $this->assertArrayNotHasKey('form_type', $filtered);
        $this->assertArrayNotHasKey('api_password', $filtered);
    }

    public function test_client_mode_keeps_copy_and_notification_fields(): void {
        $posted = [
            'name'=>'X',
            'settings'=>wp_json_encode([
                'content'=>['form_title'=>'New title'],
                'email'=>['from_address'=>'a@b.c'],
                'destinations'=>[[ 'type'=>'sftp','config'=>['host'=>'new.host','password'=>'new'] ]],
            ]),
        ];
        $filtered = FieldGate::strip_blocked_fields($posted, ModeResolver::MODE_CLIENT);
        $this->assertArrayNotHasKey('name', $filtered);
        $decoded = json_decode($filtered['settings'], true);
        $this->assertSame('New title', $decoded['content']['form_title']);
        $this->assertSame('a@b.c', $decoded['email']['from_address']);
        // Destination creds allowed in client mode
        $this->assertSame('new.host', $decoded['destinations'][0]['config']['host']);
    }

    public function test_client_mode_strips_dev_only_settings_sections(): void {
        $posted = [
            'settings'=>wp_json_encode([
                'content'=>['form_title'=>'X'],
                'form_schema'=>['steps'=>[]],   // dev-only
                'gtm'=>['enabled'=>true],       // dev-only
                'features'=>['ab_testing'=>true], // dev-only
            ]),
        ];
        $filtered = FieldGate::strip_blocked_fields($posted, ModeResolver::MODE_CLIENT);
        $decoded = json_decode($filtered['settings'], true);
        $this->assertArrayHasKey('content', $decoded);
        $this->assertArrayNotHasKey('form_schema', $decoded);
        $this->assertArrayNotHasKey('gtm', $decoded);
        $this->assertArrayNotHasKey('features', $decoded);
    }

    public function test_dev_mode_passes_array_settings_through(): void {
        $posted = ['settings'=>['content'=>['form_title'=>'Hi']]];
        $filtered = FieldGate::strip_blocked_fields($posted, ModeResolver::MODE_DEV);
        $this->assertSame(['content'=>['form_title'=>'Hi']], $filtered['settings']);
    }

    public function test_client_mode_strips_dev_sections_from_array_settings(): void {
        $posted = ['settings'=>[
            'content'=>['form_title'=>'Hi'],
            'form_schema'=>['steps'=>[]],
            'gtm'=>['enabled'=>true],
        ]];
        $filtered = FieldGate::strip_blocked_fields($posted, ModeResolver::MODE_CLIENT);
        $this->assertIsArray($filtered['settings']);
        $this->assertArrayHasKey('content', $filtered['settings']);
        $this->assertArrayNotHasKey('form_schema', $filtered['settings']);
        $this->assertArrayNotHasKey('gtm', $filtered['settings']);
    }
}

<?php
/**
 * GravityFormsImporterTest — protects the GF importer mapping contracts.
 *
 * Each test feeds a realistic Gravity Forms field/form JSON shape and
 * asserts the importer produces the expected FormFlow shape. This is
 * the unit-level contract for the importer; an end-to-end test with a
 * real GF export file lives separately at tests/Integration/.
 *
 * The importer's persistence path (writing to wp_isf_instances) is not
 * exercised here — that requires a wpdb mock. We test the mapping
 * pipeline by exporting the produced schema via the dry-run path.
 */

namespace ISF\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

final class GravityFormsImporterTest extends TestCase
{
    private \ISF\Builder\Importers\GravityFormsImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Mock the WordPress functions the importer touches.
        Functions\when('sanitize_title')->alias(function ($t) {
            $t = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $t));
            return trim($t, '-');
        });
        Functions\when('current_time')->justReturn('2026-05-29 12:00:00');
        Functions\when('__')->returnArg(1);

        require_once ISF_PLUGIN_DIR . 'includes/builder/importers/class-gravity-forms-importer.php';
        // Pass a stub Database so the constructor doesn't try to talk
        // to wpdb. Dry-run mode never calls persist() so the Database
        // is unused.
        $this->importer = new \ISF\Builder\Importers\GravityFormsImporter(
            $this->createStub(\ISF\Database\Database::class)
        );
    }

    public function test_text_field_maps_with_canonical_settings(): void
    {
        $r = $this->do_import([
            'title' => 'Contact',
            'fields' => [[
                'id' => 1, 'type' => 'text', 'label' => 'Your Name',
                'isRequired' => true, 'placeholder' => 'Jane Doe',
                'description' => 'Full legal name.',
            ]],
        ]);
        $f = $this->fields($r)[0];
        $this->assertSame('text', $f['type']);
        $this->assertSame('your_name', $f['name']);
        $this->assertSame('Your Name', $f['label']);
        $this->assertSame('Your Name', $f['settings']['label']);
        $this->assertTrue($f['settings']['required']);
        $this->assertSame('Jane Doe', $f['settings']['placeholder']);
        $this->assertSame('Full legal name.', $f['settings']['help_text']);
    }

    public function test_email_phone_textarea_pass_through_with_correct_types(): void
    {
        $r = $this->do_import([
            'title' => 'Mixed',
            'fields' => [
                ['id' => 1, 'type' => 'email',    'label' => 'Email'],
                ['id' => 2, 'type' => 'phone',    'label' => 'Phone'],
                ['id' => 3, 'type' => 'textarea', 'label' => 'Notes'],
            ],
        ]);
        $types = array_column($this->fields($r), 'type');
        $this->assertSame(['email', 'phone', 'textarea'], $types);
    }

    public function test_name_field_expands_to_first_and_last_with_half_width(): void
    {
        $r = $this->do_import([
            'title' => 'Name only',
            'fields' => [[
                'id' => 1, 'type' => 'name', 'label' => 'Name', 'isRequired' => true,
                'inputs' => [
                    ['id' => '1.3', 'label' => 'First', 'name' => 'first'],
                    ['id' => '1.6', 'label' => 'Last',  'name' => 'last'],
                ],
            ]],
        ]);
        $fields = $this->fields($r);
        $this->assertCount(2, $fields);
        $this->assertSame(['first_name', 'last_name'], array_column($fields, 'name'));
        $this->assertSame(['half', 'half'], array_column($fields, 'width'));
        $this->assertTrue($fields[0]['settings']['required']);
        $this->assertTrue($fields[1]['settings']['required']);
    }

    public function test_address_field_expands_to_five_fields_with_layout_hints(): void
    {
        $r = $this->do_import([
            'title' => 'Address only',
            'fields' => [[
                'id' => 1, 'type' => 'address', 'label' => 'Address', 'isRequired' => true,
            ]],
        ]);
        $fields = $this->fields($r);
        $this->assertCount(5, $fields);
        $names = array_column($fields, 'name');
        $this->assertSame(
            ['street_address', 'address_line_2', 'city', 'state', 'zip'],
            $names
        );
        // city + state must be half-width to match canonical layout.
        // Look up by name rather than position so future field additions
        // don't break this test.
        $by_name = array_column($fields, null, 'name');
        $this->assertSame('half', $by_name['city']['width'] ?? null);
        $this->assertSame('half', $by_name['state']['width'] ?? null);
    }

    public function test_select_radio_checkbox_choices_map_to_options(): void
    {
        $r = $this->do_import([
            'title' => 'Options',
            'fields' => [
                ['id' => 1, 'type' => 'select', 'label' => 'Pick', 'choices' => [
                    ['text' => 'Alpha', 'value' => 'a'],
                    ['text' => 'Beta',  'value' => 'b'],
                ]],
                ['id' => 2, 'type' => 'radio', 'label' => 'Side', 'choices' => [
                    ['text' => 'Yes', 'value' => 'yes'],
                    ['text' => 'No',  'value' => 'no'],
                ]],
            ],
        ]);
        $fields = $this->fields($r);
        $this->assertSame('select', $fields[0]['type']);
        $this->assertSame(
            [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta']],
            $fields[0]['settings']['options']
        );
        $this->assertSame('radio', $fields[1]['type']);
    }

    public function test_consent_field_becomes_single_option_checkbox(): void
    {
        $r = $this->do_import([
            'title' => 'TOS',
            'fields' => [[
                'id' => 1, 'type' => 'consent', 'label' => 'Terms', 'isRequired' => true,
                'checkboxLabel' => 'I agree',
            ]],
        ]);
        $f = $this->fields($r)[0];
        $this->assertSame('checkbox', $f['type']);
        $this->assertCount(1, $f['settings']['options']);
        $this->assertSame('I agree', $f['settings']['options'][0]['label']);
        $this->assertTrue($f['settings']['required']);
    }

    public function test_conditional_logic_maps_to_show_when(): void
    {
        $r = $this->do_import([
            'title' => 'Conditional',
            'fields' => [
                ['id' => 1, 'type' => 'radio', 'label' => 'Account?', 'inputName' => 'has_account',
                 'choices' => [['text' => 'Yes', 'value' => 'yes'], ['text' => 'No', 'value' => 'no']]],
                ['id' => 2, 'type' => 'text', 'label' => 'Account #', 'inputName' => 'account_number',
                 'conditionalLogic' => [
                    'actionType' => 'show',
                    'rules' => [['fieldId' => 1, 'operator' => 'is', 'value' => 'yes']],
                 ]],
            ],
        ]);
        $fields = $this->fields($r);
        $this->assertSame('account_number', $fields[1]['name']);
        $this->assertArrayHasKey('show_when', $fields[1]['settings']);
        $this->assertSame('field_1', $fields[1]['settings']['show_when']['field']);
        $this->assertSame('yes', $fields[1]['settings']['show_when']['equals']);
    }

    public function test_unsupported_fields_drop_and_emit_warnings(): void
    {
        $r = $this->do_import([
            'title' => 'Junkyard',
            'fields' => [
                ['id' => 1, 'type' => 'post_title', 'label' => 'WP Post Title'],
                ['id' => 2, 'type' => 'product',    'label' => 'Buy It'],
                ['id' => 3, 'type' => 'captcha',    'label' => 'Verify'],
                ['id' => 4, 'type' => 'calculation','label' => 'Total'],
                ['id' => 5, 'type' => 'page',       'label' => 'Break'],
                ['id' => 6, 'type' => 'completely-made-up', 'label' => 'Mystery'],
            ],
        ]);
        $this->assertSame(0, count($this->fields($r)));
        $this->assertGreaterThanOrEqual(6, count($r['warnings']));
    }

    public function test_section_and_html_become_heading_and_paragraph(): void
    {
        $r = $this->do_import([
            'title' => 'Structure',
            'fields' => [
                ['id' => 1, 'type' => 'section', 'label' => 'Account Info'],
                ['id' => 2, 'type' => 'html', 'label' => '', 'content' => '<p>Read carefully.</p>'],
            ],
        ]);
        $fields = $this->fields($r);
        $this->assertSame('heading', $fields[0]['type']);
        $this->assertSame('Account Info', $fields[0]['settings']['text']);
        $this->assertSame('paragraph', $fields[1]['type']);
        $this->assertSame('<p>Read carefully.</p>', $fields[1]['settings']['content']);
    }

    public function test_form_settings_capture_submit_text_and_success_message(): void
    {
        $r = $this->do_import([
            'title' => 'Settings',
            'fields' => [],
            'button' => ['text' => 'Submit Enrollment'],
            'confirmations' => [
                ['type' => 'message', 'message' => 'Thanks!'],
            ],
        ]);
        $this->assertSame('Submit Enrollment', $r['schema']['settings']['submit_button_text']);
        $this->assertSame('Thanks!', $r['schema']['settings']['success_message']);
    }

    public function test_form_with_notifications_records_a_warning(): void
    {
        $r = $this->do_import([
            'title' => 'Notify',
            'fields' => [],
            'notifications' => [
                ['name' => 'Admin', 'to' => 'admin@example.com'],
                ['name' => 'User',  'to' => '{user_email}'],
            ],
        ]);
        $this->assertNotEmpty(array_filter(
            $r['warnings'],
            fn($w) => str_contains($w, 'notification')
        ));
    }

    public function test_slug_derives_from_title_with_imported_suffix(): void
    {
        $r = $this->do_import([
            'title' => 'Dominion Energy PTR — Innsbrook',
            'fields' => [],
        ]);
        $this->assertStringEndsWith('-imported', $r['slug']);
        $this->assertStringContainsString('dominion', $r['slug']);
    }

    public function test_field_name_priority_input_name_then_admin_then_label(): void
    {
        $r = $this->do_import([
            'title' => 'Names',
            'fields' => [
                ['id' => 1, 'type' => 'text', 'label' => 'Display Label', 'inputName' => 'machine_name'],
                ['id' => 2, 'type' => 'text', 'label' => 'Display Label 2', 'adminLabel' => 'admin_name'],
                ['id' => 3, 'type' => 'text', 'label' => 'Just A Label'],
            ],
        ]);
        $names = array_column($this->fields($r), 'name');
        $this->assertSame(['machine_name', 'admin_name', 'just_a_label'], $names);
    }

    // -- helpers ----------------------------------------------------------

    private function do_import(array $gf_form): array
    {
        return $this->importer->import($gf_form, ['dry_run' => true]);
    }

    /** @return array[] FormFlow fields produced. */
    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function fields(array $result): array
    {
        return $result['schema']['steps'][0]['fields'] ?? [];
    }
}

<?php
/**
 * FormTypeClassifierTest — pins the 4.0 form_type dispatch table.
 *
 * 4.0 introduced canonical form_type values (`intellisource_wizard`,
 * `intellisource_scheduler`, `builder`) alongside the legacy aliases
 * (`enrollment`, `scheduler`, `custom`). The new `Frontend::classify()`
 * + `Frontend::canonicalize_form_type()` helpers route every value to
 * the right subsystem so existing sites with legacy form_type rows
 * keep working forever.
 *
 * Today's bugs (3.0.5, 3.1.1) all came from inconsistent form_type
 * routing. This test prevents the dispatch table from drifting
 * silently — every supported value must map to a known subsystem,
 * and the canonicalizer must produce the canonical form for sub-shape
 * comparisons inside a subsystem.
 */

namespace ISF\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class FormTypeClassifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once ISF_PLUGIN_DIR . 'public/class-public.php';
    }

    /**
     * @dataProvider classify_data_provider
     */
    public function test_classify_routes_value_to_expected_subsystem(string $form_type, string $expected_subsystem): void
    {
        $this->assertSame(
            $expected_subsystem,
            \ISF\Frontend\Frontend::classify($form_type),
            "classify('{$form_type}') should route to '{$expected_subsystem}'."
        );
    }

    public static function classify_data_provider(): array
    {
        return [
            // Legacy aliases
            'enrollment (legacy)'                   => ['enrollment', 'intellisource'],
            'scheduler (legacy)'                    => ['scheduler', 'intellisource'],
            'custom (legacy)'                       => ['custom', 'builder'],

            // 4.0 canonical names
            'intellisource_wizard (canonical)'       => ['intellisource_wizard', 'intellisource'],
            'intellisource_scheduler (canonical)'    => ['intellisource_scheduler', 'intellisource'],
            'builder (canonical)'                    => ['builder', 'builder'],

            // External is its own subsystem
            'external'                               => ['external', 'external'],

            // Unknown / empty defaults to builder (safer than the
            // pre-4.0 silent fallthrough to IntelliSOURCE wizard that
            // caused 3.0.5 / 3.1.1 regressions).
            'unknown'                                => ['totally_made_up', 'builder'],
            'empty string'                           => ['', 'builder'],
        ];
    }

    /**
     * @dataProvider canonicalize_data_provider
     */
    public function test_canonicalize_form_type_returns_canonical_name(string $input, string $expected): void
    {
        $this->assertSame(
            $expected,
            \ISF\Frontend\Frontend::canonicalize_form_type($input),
            "canonicalize_form_type('{$input}') should return '{$expected}'."
        );
    }

    public static function canonicalize_data_provider(): array
    {
        return [
            // Legacy → canonical
            'enrollment → intellisource_wizard'      => ['enrollment', 'intellisource_wizard'],
            'scheduler → intellisource_scheduler'    => ['scheduler', 'intellisource_scheduler'],
            'custom → builder'                       => ['custom', 'builder'],

            // Canonical is idempotent (already canonical)
            'intellisource_wizard idempotent'        => ['intellisource_wizard', 'intellisource_wizard'],
            'intellisource_scheduler idempotent'     => ['intellisource_scheduler', 'intellisource_scheduler'],
            'builder idempotent'                     => ['builder', 'builder'],
            'external idempotent'                    => ['external', 'external'],

            // Unknown passes through unchanged (don't silently rename
            // a value we don't understand).
            'unknown passes through'                 => ['totally_made_up', 'totally_made_up'],
            'empty string passes through'            => ['', ''],
        ];
    }

    /**
     * Every value the classifier knows about must be in the
     * FormTypeEnumRoundTripTest::REQUIRED_FORM_TYPES list, AND must
     * appear in the canonical CREATE TABLE ENUM. Catches future
     * drift between the classifier and the schema.
     */
    public function test_classifier_canonical_values_are_in_required_set(): void
    {
        $canonical_values = ['external', 'intellisource_wizard', 'intellisource_scheduler', 'builder'];
        $required = [
            'enrollment', 'scheduler', 'external', 'custom',
            'intellisource_wizard', 'intellisource_scheduler', 'builder',
        ];
        foreach ($canonical_values as $v) {
            $this->assertContains(
                $v,
                $required,
                "Canonical value '{$v}' must be present in FormTypeEnumRoundTripTest::REQUIRED_FORM_TYPES."
            );
        }
    }
}

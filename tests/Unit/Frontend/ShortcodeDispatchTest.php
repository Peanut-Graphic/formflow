<?php
/**
 * ShortcodeDispatchTest — protects against 3.0.5 + 3.1.1 class of bugs.
 *
 * The render_form_shortcode method dispatches to different rendering
 * pipelines based on form_type. Bugs in this dispatch table cost
 * hours of debugging today (custom forms rendering as IntelliSOURCE
 * wizard, etc.).
 *
 * Strategy: static-check the dispatch table to confirm every form_type
 * the application supports has an explicit branch, AND that the empty/
 * unknown form_type case is handled (default = a, per the spec, reject
 * with admin notice). Reject means: do NOT silently fall through to
 * the enrollment wizard.
 */

namespace ISF\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class ShortcodeDispatchTest extends TestCase
{
    private string $public_source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->public_source = file_get_contents(ISF_PLUGIN_DIR . 'public/class-public.php');
    }

    public function test_external_form_type_routes_to_external_renderer(): void
    {
        // 4.0 dispatch shape: classify() returns 'external' for
        // form_type='external', then render_external_form is called
        // for that subsystem.
        $this->assertMatchesRegularExpression(
            "/\\\$subsystem\s*=\s*self::classify/",
            $this->public_source,
            'render_form_shortcode must dispatch via Frontend::classify() — direct equality checks against form_type are the silent-fallthrough bug shape (3.0.5 / 3.1.1).'
        );
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$subsystem\s*===\s*'external'\s*\)\s*\{[^}]*render_external_form/s",
            $this->public_source,
            "form_type subsystem='external' must route to render_external_form."
        );
    }

    public function test_custom_form_type_routes_to_builder_renderer(): void
    {
        // 4.0 dispatch shape: classify() returns 'builder' for both
        // legacy 'custom' and canonical 'builder', then
        // render_custom_form is called for that subsystem.
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$subsystem\s*===\s*'builder'\s*\)\s*\{[^}]*render_custom_form/s",
            $this->public_source,
            "form_type subsystem='builder' must route to render_custom_form. Removing this sends every builder form (including all 8 Gravity Forms migrations) back through the IntelliSOURCE wizard."
        );
    }

    public function test_scheduler_form_type_is_recognized(): void
    {
        // 4.0 sub-shape check inside the IntelliSOURCE subsystem:
        // distinguish wizard vs scheduler via canonicalize_form_type
        // so both 'scheduler' (legacy) and 'intellisource_scheduler'
        // (canonical) trigger the 2-step scheduler flow instead of
        // the 5-step enrollment wizard.
        $this->assertMatchesRegularExpression(
            "/\\\$is_scheduler\s*=\s*self::canonicalize_form_type[^;]+===\s*'intellisource_scheduler'/",
            $this->public_source,
            'Scheduler sub-shape detection missing; scheduler forms will render as the 5-step enrollment wizard.'
        );
    }

    /**
     * The render_custom_form method must enqueue the behavior layer so
     * conditional show/hide, scroll-gate, and AJAX submit all work.
     */
    public function test_render_custom_form_enqueues_builder_js(): void
    {
        $this->assertMatchesRegularExpression(
            "/wp_enqueue_script\(\s*'isf-builder-form'\s*\)/",
            $this->public_source,
            "render_custom_form must enqueue isf-builder-form for conditionals, scroll-gate, and AJAX submit."
        );
    }

    /**
     * The render_custom_form method must localize the isfBuilderForm
     * config global with all four keys the JS depends on. Today's
     * 3.1.1 patch added this; if it's removed, submit AJAX breaks.
     */
    public function test_render_custom_form_localizes_required_config(): void
    {
        // Find the localize_script call for isfBuilderForm.
        $matches = [];
        $found = preg_match(
            "/wp_localize_script\(\s*'isf-builder-form'\s*,\s*'isfBuilderForm'\s*,\s*\[(.*?)\]\s*\)/s",
            $this->public_source,
            $matches
        );

        $this->assertSame(1, $found, 'isfBuilderForm config global is not localized; submit JS will fail.');

        $config = $matches[1];
        foreach (['ajax_url', 'action', 'nonce', 'instance_id'] as $key) {
            $this->assertStringContainsString(
                "'{$key}'",
                $config,
                "isfBuilderForm config missing '{$key}' — submit AJAX cannot function without it."
            );
        }
    }

    /**
     * The empty/unknown form_type case must not silently fall through
     * to step-1-program.php (the IntelliSOURCE wizard). Per the Phase 5
     * spec decision, the canonical fix is to reject with an admin-only
     * notice. This test pins the current shape so we notice if anyone
     * weakens it.
     */
    public function test_unknown_form_type_does_not_silently_render_enrollment(): void
    {
        // 4.0 changed the unknown-form_type behavior. Pre-4.0 silently
        // fell through to the IntelliSOURCE wizard (this caused the
        // 3.0.5 and 3.1.1 regressions when the database column lost
        // the value via ENUM coercion). 4.0's classify() defaults
        // unknown values to 'builder' — the safe default for new
        // clients that doesn't pretend they're utility enrollments.
        //
        // Pin the classify default by checking the match expression's
        // default arm. If anyone changes it back to 'intellisource',
        // this test fails loudly.
        $this->assertMatchesRegularExpression(
            "/default\s*=>\s*'builder'/",
            $this->public_source,
            "Unknown form_type must default to 'builder', not 'intellisource'. The pre-4.0 silent-fallthrough-to-wizard shape is the bug we're permanently fixing."
        );

        // Both branches still need to be reachable. The wizard
        // path stays inline in render_form_shortcode until PR 4
        // extracts it into includes/intellisource/.
        $external_pos = strpos($this->public_source, "'external'");
        $builder_pos  = strpos($this->public_source, "'builder'");
        $step1_pos    = strpos($this->public_source, 'step-1-program.php');

        $this->assertNotFalse($external_pos, 'No external dispatch branch.');
        $this->assertNotFalse($builder_pos,  'No builder dispatch branch.');
        $this->assertNotFalse($step1_pos,    'No step-1-program include — the IntelliSOURCE wizard is gone too?');
    }
}

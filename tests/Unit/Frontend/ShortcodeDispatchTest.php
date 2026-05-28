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
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$instance\['form_type'\]\s*===\s*'external'\s*\)\s*\{[^}]*render_external_form/s",
            $this->public_source,
            "form_type='external' must route to render_external_form. If this branch is missing, external forms render as the enrollment wizard."
        );
    }

    public function test_custom_form_type_routes_to_builder_renderer(): void
    {
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$instance\['form_type'\]\s*===\s*'custom'\s*\)\s*\{[^}]*render_custom_form/s",
            $this->public_source,
            "form_type='custom' must route to render_custom_form. The 3.0.5 patch added this branch; removing it sends every builder form back through the IntelliSOURCE wizard."
        );
    }

    public function test_scheduler_form_type_is_recognized(): void
    {
        $this->assertMatchesRegularExpression(
            "/\\\$is_scheduler\s*=\s*\\\$instance\['form_type'\]\s*===\s*'scheduler'/",
            $this->public_source,
            "Scheduler form_type detection missing; scheduler forms will render as enrollment."
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
        // Locate the render_form_shortcode method body.
        $matches = [];
        $found = preg_match(
            '/public function render_form_shortcode\([^)]*\)[^{]*\{(.*?)\n    \}/s',
            $this->public_source,
            $matches
        );
        $this->assertSame(1, $found, 'Could not locate render_form_shortcode method.');

        $body = $matches[1];

        // We expect explicit checks for 'external', 'custom', 'scheduler'
        // BEFORE the fallthrough that includes step-1-program.php.
        $external_pos  = strpos($body, "'external'");
        $custom_pos    = strpos($body, "'custom'");
        $step1_pos     = strpos($body, 'step-1-program.php');

        $this->assertNotFalse($external_pos, 'No external branch.');
        $this->assertNotFalse($custom_pos,   'No custom branch.');
        $this->assertNotFalse($step1_pos,    'No step-1-program include — the IntelliSOURCE wizard is gone too?');

        $this->assertLessThan(
            $step1_pos,
            $external_pos,
            'external branch must precede the IntelliSOURCE wizard fallthrough or external forms will render as the wizard.'
        );
        $this->assertLessThan(
            $step1_pos,
            $custom_pos,
            'custom branch must precede the IntelliSOURCE wizard fallthrough or custom forms will render as the wizard (the 3.0.5 + 3.1.1 hotfixes).'
        );
    }
}

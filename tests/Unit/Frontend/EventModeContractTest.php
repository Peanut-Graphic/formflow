<?php
/**
 * EventModeContractTest — pins the event-mode contract.
 *
 * Event mode is used at outreach events on shared iPads where multiple
 * people enroll back-to-back without a manual browser refresh. Both
 * sides of the contract (PHP wiring + JS behavior) must stay aligned;
 * if either drifts, the kiosk experience breaks silently.
 */

namespace ISF\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class EventModeContractTest extends TestCase
{
    private string $public_source;
    private string $js_source;
    private string $copy_view_source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->public_source    = file_get_contents(ISF_PLUGIN_DIR . 'public/class-public.php');
        $this->js_source        = file_get_contents(ISF_PLUGIN_DIR . 'public/assets/js/builder-form.js');
        $this->copy_view_source = file_get_contents(ISF_PLUGIN_DIR . 'admin/views/form-editor/tasks/copy.php');
    }

    public function test_render_custom_form_localizes_event_mode_config(): void
    {
        $this->assertMatchesRegularExpression(
            "/'event_mode'\\s*=>\\s*\\\$event_mode/",
            $this->public_source,
            "render_custom_form must localize event_mode boolean into isfBuilderForm."
        );
        $this->assertMatchesRegularExpression(
            "/'event_mode_auto_reset_seconds'\\s*=>\\s*\\\$event_mode_secs/",
            $this->public_source,
            "render_custom_form must localize the auto-reset seconds value."
        );
    }

    public function test_event_mode_auto_reset_seconds_is_clamped(): void
    {
        // 0..60 inclusive. Clamping prevents a typo in the admin from
        // turning into a 10-minute "broken kiosk" period.
        $this->assertMatchesRegularExpression(
            '/\\$event_mode_secs\\s*<\\s*0.*\\$event_mode_secs\\s*=\\s*0/s',
            $this->public_source,
            "event_mode_auto_reset_seconds must be clamped at 0 minimum."
        );
        $this->assertMatchesRegularExpression(
            '/\\$event_mode_secs\\s*>\\s*60.*\\$event_mode_secs\\s*=\\s*60/s',
            $this->public_source,
            "event_mode_auto_reset_seconds must be clamped at 60 maximum."
        );
    }

    public function test_render_success_branches_on_event_mode(): void
    {
        $this->assertMatchesRegularExpression(
            '/if\\s*\\(\\s*cfg\\.event_mode\\s*\\)/',
            $this->js_source,
            'builder-form.js renderSuccess must check cfg.event_mode before adding the kiosk button.'
        );
    }

    public function test_reset_button_calls_window_location_reload(): void
    {
        // location.reload() is the cleanest reset — kills any half-saved
        // state, re-fetches the visitor cookie, resets all the form
        // state-machine. Anything else (DOM swap, AJAX re-render) leaks
        // state between submissions.
        $this->assertMatchesRegularExpression(
            '/window\\.location\\.reload\\(\\)/',
            $this->js_source,
            'Event-mode reset must call window.location.reload() — anything else leaks state between submissions.'
        );
    }

    public function test_countdown_can_be_cancelled_by_tapping(): void
    {
        // Cancelling the countdown lets the user pause if they want
        // to read the confirmation longer. The manual button still
        // works after cancellation.
        $this->assertMatchesRegularExpression(
            '/function\\s+cancel\\(\\)/',
            $this->js_source,
            'Event-mode countdown must have a cancel() function so users can pause the auto-reset.'
        );
        $this->assertMatchesRegularExpression(
            '/addEventListener\\(\\s*[\'"]click[\'"]/',
            $this->js_source,
            'Event-mode must wire a click listener for tap-to-cancel on the confirmation panel.'
        );
    }

    public function test_admin_ui_exposes_event_mode_toggle_and_seconds(): void
    {
        $this->assertStringContainsString(
            "name=\"settings[event_mode]\"",
            $this->copy_view_source,
            "Copy task must expose an event_mode checkbox."
        );
        $this->assertStringContainsString(
            "name=\"settings[event_mode_auto_reset_seconds]\"",
            $this->copy_view_source,
            "Copy task must expose an event_mode_auto_reset_seconds input."
        );
    }
}

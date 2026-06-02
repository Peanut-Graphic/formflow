<?php
/**
 * FormRendererProgressBarTest — pins the "no progress UI on single-step
 * forms" contract.
 *
 * Surfaced 2026-06-02 on the Dominion PTR form: a lonely "1" circle
 * with a horizontal bar floated above the form because
 * render_progress_bar() ran unconditionally. Fixed in 4.0.4 by
 * short-circuiting when count($steps) <= 1.
 *
 * This is a source-text contract test (Phase 5 sentinel style) — it
 * pins the guard so a future refactor can't drop it.
 */

namespace ISF\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class FormRendererProgressBarTest extends TestCase
{
    public function test_progress_bar_is_short_circuited_for_single_step_forms(): void
    {
        $src = file_get_contents(ISF_PLUGIN_DIR . 'includes/builder/class-form-renderer.php');

        // render_progress_bar() must early-return when there's <= 1 step.
        // Without this guard, single-step forms get a meaningless "1" circle
        // and progress bar — the Dominion PTR regression that prompted 4.0.4.
        $this->assertMatchesRegularExpression(
            '/function\s+render_progress_bar.*?\$total_steps\s*<=\s*1.*?return\s*;/s',
            $src,
            'render_progress_bar() must early-return when count($steps) <= 1 — single-step forms should NOT show a progress indicator.'
        );
    }
}

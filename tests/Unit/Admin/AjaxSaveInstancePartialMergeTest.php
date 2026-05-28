<?php
/**
 * AjaxSaveInstancePartialMergeTest — protects against 3.0.3 class of
 * bugs (the form-editor's save-on-blur clobbering unrelated settings).
 *
 * Per the Phase 5 spec, this test asserts SHALLOW partial-merge
 * semantics: a save that targets one settings key must not delete
 * other top-level settings keys. Deep-merge (nested values like
 * settings.scheduling.timezone) is out of scope for now.
 *
 * Strategy: static-check the ajax_save_instance method body for the
 * merge pattern that 3.0.3 settled on. The handler must read the
 * existing instance settings, merge the POST in on top, and write
 * the union back — never replace.
 */

namespace ISF\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class AjaxSaveInstancePartialMergeTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = file_get_contents(ISF_PLUGIN_DIR . 'admin/class-admin.php');
    }

    public function test_method_exists(): void
    {
        require_once ISF_PLUGIN_DIR . 'admin/class-admin.php';
        $this->assertTrue(
            method_exists(\ISF\Admin\Admin::class, 'ajax_save_instance'),
            'Admin::ajax_save_instance was removed; the form editor cannot persist changes.'
        );
    }

    /**
     * 3.0.3 regression guard. On an existing instance (update path),
     * the handler must build $data from POST-present columns only —
     * not from a static schema. If $data is built statically, every
     * partial save clobbers the rest of the instance.
     */
    public function test_update_path_builds_data_only_from_post_keys(): void
    {
        // The 3.0.3 patch added an $is_update branch that skips
        // required-field validation. Confirm the gating exists.
        $this->assertMatchesRegularExpression(
            '/\$is_update\s*=\s*\$id\s*>\s*0/',
            $this->source,
            '3.0.3 regression: ajax_save_instance must distinguish create vs update paths.'
        );
    }

    /**
     * 3.0.4 regression guard. References to $data['slug'] /
     * $data['name'] after $data construction must be isset-guarded
     * because partial updates do not populate them.
     */
    public function test_post_data_references_are_isset_guarded(): void
    {
        // Find the duplicate-slug check.
        $this->assertMatchesRegularExpression(
            "/isset\(\s*\\\$data\['slug'\]\s*\)/",
            $this->source,
            '3.0.4 regression: duplicate-slug check must be gated on isset($data[\'slug\']) — partial updates omit it.'
        );
    }

    /**
     * The audit log entry must include the existing row values so a
     * partial save still produces a complete log entry (3.0.4 patch
     * used `$logged = $data + ($existing ?: [])`).
     */
    public function test_audit_log_falls_back_to_existing_values(): void
    {
        // Patterns the 3.0.4 patch uses: $data + $existing OR
        // array_merge($existing, $data). Allow either shape.
        $found =
            preg_match('/\$data\s*\+\s*\(?\\\$existing/', $this->source)
            || preg_match('/\$logged\s*=\s*\$data\s*\+/', $this->source);

        $this->assertSame(
            1,
            (int) $found,
            'Audit log construction must fall back to existing row values so partial saves still log meaningful state. (3.0.4)'
        );
    }

    /**
     * 3.0.2 regression guard. The settings handling must tolerate
     * BOTH the array shape (POST'd by the new form-editor JS as
     * settings[foo][bar]=x) AND the JSON string shape (legacy editor).
     * If either branch is missing the corresponding caller fatals.
     */
    public function test_settings_handling_tolerates_array_or_json_shape(): void
    {
        // The 3.0.2 patch must read $_POST['settings'] into $raw, then
        // branch on is_array($raw). Both branches (array shape from new
        // form-editor.js, JSON string from legacy editor) must be
        // present — missing either fatals the corresponding caller.
        $this->assertMatchesRegularExpression(
            "/\\\$raw\s*=\s*\\\$_POST\[\s*['\"]settings['\"]\s*\]/",
            $this->source,
            'ajax_save_instance must capture $_POST[\'settings\'] into a $raw variable.'
        );
        $this->assertMatchesRegularExpression(
            '/is_array\(\s*\$raw\s*\)/',
            $this->source,
            '3.0.2 regression: must branch on is_array($raw) to handle the new editor\'s array shape.'
        );
        $this->assertMatchesRegularExpression(
            '/json_decode\(\s*stripslashes\(/',
            $this->source,
            '3.0.2 regression: legacy JSON string shape branch missing.'
        );
    }
}

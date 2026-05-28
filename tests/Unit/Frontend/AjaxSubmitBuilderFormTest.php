<?php
/**
 * AjaxSubmitBuilderFormTest — protects the patches that landed in
 * 3.1.1, 3.1.4, and 3.1.5.
 *
 * Today's regressions:
 *   - 3.1.4: get_instance was called with (int, bool) but signature is (int).
 *   - 3.1.5: FORM_COMPLETED was called with three args but expects one.
 *   - 3.1.1: The method existed at all (no submit handler before).
 *
 * Strategy: static-analyze the production source. We grep the handler's
 * method body for the exact call shapes today's hotfixes settled on,
 * and verify the relevant Database / Hooks contracts by Reflection.
 * Cheap, fast, fails loud if anyone reintroduces the bugs we already
 * paid for in production hours.
 */

namespace ISF\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AjaxSubmitBuilderFormTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = file_get_contents(ISF_PLUGIN_DIR . 'public/class-public.php');
    }

    public function test_handler_method_exists(): void
    {
        require_once ISF_PLUGIN_DIR . 'public/class-public.php';
        $this->assertTrue(
            method_exists(\ISF\Frontend\Frontend::class, 'isf_submit_builder_form'),
            'isf_submit_builder_form was deleted? It is the ONLY persistence path for form_type=custom.'
        );
    }

    /**
     * 3.1.4 regression guard. Database::get_instance must remain
     * (int $id) and the handler must call it with a single int.
     */
    public function test_database_get_instance_signature_is_single_int(): void
    {
        require_once ISF_PLUGIN_DIR . 'includes/database/class-database.php';
        $method = new ReflectionMethod(\ISF\Database\Database::class, 'get_instance');
        $params = $method->getParameters();

        $this->assertCount(1, $params, 'Database::get_instance signature changed; isf_submit_builder_form will break.');
        $this->assertSame('int', (string) $params[0]->getType(), 'Database::get_instance first arg should be int.');
    }

    /**
     * 3.1.4 regression guard at the call site.
     * Handler should call $this->db->get_instance(<one arg>), not two.
     */
    public function test_handler_calls_get_instance_with_one_arg(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$this->db->get_instance\(\s*\$instance_id\s*\)/',
            $this->source,
            'isf_submit_builder_form must call get_instance with a single argument (3.1.4 hotfix).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$this->db->get_instance\(\s*\$instance_id\s*,/',
            $this->source,
            '3.1.4 regression: get_instance must not be called with a second argument.'
        );
    }

    /**
     * 3.1.5 regression guard. FORM_COMPLETED must fire with the
     * single-associative-array signature (PeanutIntegration::forward_form_completed
     * expects array $submission_data).
     */
    public function test_form_completed_fires_with_single_array_arg(): void
    {
        // Find the FORM_COMPLETED fire and capture its args.
        $this->assertMatchesRegularExpression(
            '/do_action\(\s*\\\\ISF\\\\Hooks::FORM_COMPLETED\s*,\s*\$submission_data\s*\)\s*;/',
            $this->source,
            'FORM_COMPLETED must fire with $submission_data as the single argument (3.1.5 hotfix).'
        );

        // And $submission_data itself must be built as an associative array.
        $this->assertMatchesRegularExpression(
            '/\$submission_data\s*=\s*\[/',
            $this->source,
            'isf_submit_builder_form must build a $submission_data array before firing FORM_COMPLETED.'
        );
    }

    /**
     * 3.1.5 also fires the legacy ENROLLMENT_COMPLETED with its three
     * positional args. Removing that would break IntelliSOURCE.
     */
    public function test_enrollment_completed_fires_with_three_positional_args(): void
    {
        $this->assertMatchesRegularExpression(
            '/do_action\(\s*\\\\ISF\\\\Hooks::ENROLLMENT_COMPLETED\s*,\s*\(int\)\s*\$submission_id\s*,\s*\$instance_id\s*,\s*\$form_data\s*\)/',
            $this->source,
            'ENROLLMENT_COMPLETED fire must remain (id, instance_id, form_data) — legacy integrations depend on it.'
        );
    }

    /**
     * Handler must persist before firing hooks — flipping the order
     * would mean FORM_COMPLETED receives an invalid submission_id and
     * downstream destinations would fire on a non-existent row.
     */
    public function test_persistence_precedes_hook_fires(): void
    {
        $persist_pos = strpos($this->source, '$this->db->create_submission(');
        $hook_pos    = strpos($this->source, 'Hooks::FORM_COMPLETED');

        $this->assertNotFalse($persist_pos, 'create_submission call missing from isf_submit_builder_form');
        $this->assertNotFalse($hook_pos, 'FORM_COMPLETED fire missing from isf_submit_builder_form');
        $this->assertLessThan(
            $hook_pos,
            $persist_pos,
            'create_submission must run before FORM_COMPLETED so downstream destinations get a real submission_id.'
        );
    }

    /**
     * The handler must be registered under both isf_* and formflow_*
     * action names (F5 ASM mirror, see memory 2026-05-09).
     */
    public function test_handler_registered_under_both_action_prefixes(): void
    {
        $plugin_src = file_get_contents(ISF_PLUGIN_DIR . 'includes/class-plugin.php');
        $this->assertStringContainsString(
            "'isf_submit_builder_form'",
            $plugin_src,
            'isf_submit_builder_form must be in the $ajax_actions array.'
        );
        $this->assertMatchesRegularExpression(
            '/formflow_\'\s*\.\s*substr\(\$action,\s*4\)/',
            $plugin_src,
            'Plugin must mirror every isf_* AJAX action under formflow_* for F5 ASM compatibility.'
        );
    }
}

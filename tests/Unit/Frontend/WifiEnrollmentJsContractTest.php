<?php
/**
 * WifiEnrollmentJsContractTest — source contract for the client-side gate.
 *
 * This repo has no JS test runner, and adding one is out of scope for this
 * change, so this follows the existing convention (see AjaxSubmitBuilderFormTest)
 * and statically analyses the shipped bundle.
 *
 * Be honest about what that buys: this proves the handlers are wired and the
 * required branches exist. It does NOT execute them, so it cannot prove the
 * interaction actually behaves correctly in a browser. The server-side gate in
 * WifiGateEnforcementTest is the guarantee that matters; this is a guard
 * against the wiring being deleted or drifting.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class WifiEnrollmentJsContractTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = (string) file_get_contents(
            ISF_PLUGIN_DIR . 'public/assets/js/enrollment.js'
        );
    }

    /**
     * Isolate the Step 1 submit handler so a match elsewhere in the bundle
     * cannot satisfy these assertions.
     */
    private function stepOneHandler(): string
    {
        $found = preg_match(
            '/function handleStep1Submit\s*\([^)]*\)\s*\{(.*?)\n    \}/s',
            $this->source,
            $m
        );

        $this->assertSame(1, $found, 'Could not isolate handleStep1Submit.');

        return $m[1];
    }

    public function test_step_one_blocks_thermostat_without_wifi(): void
    {
        $handler = $this->stepOneHandler();

        $this->assertMatchesRegularExpression(
            '/has_wifi/',
            $handler,
            'Step 1 never inspects the WiFi answer, so the browser advances a customer '
            . 'the server will then reject — a dead end instead of an offer.'
        );
        $this->assertMatchesRegularExpression(
            '/return;/',
            $handler,
            'Step 1 must be able to stop rather than fall through to goToStep(2).'
        );
    }

    public function test_conversion_handler_is_bound(): void
    {
        $this->assertMatchesRegularExpression(
            '/isf-convert-to-dcu/',
            $this->source,
            'The conversion button has no handler bound, so clicking it does nothing.'
        );
    }

    /**
     * The conversion has to change the answer the API receives, not just the
     * visible screen.
     */
    public function test_conversion_sets_device_type_to_dcu(): void
    {
        $this->assertMatchesRegularExpression(
            "/device_type\s*=\s*['\"]dcu['\"]/",
            $this->source,
            'Conversion never sets device_type to dcu, so the customer is shown the '
            . 'switch flow but still enrolled for a thermostat.'
        );
    }

    /**
     * Without this the enrollment cannot be distinguished from someone who
     * chose the switch outright, and the reporting the feature was justified
     * on is silently empty.
     */
    public function test_conversion_records_that_the_gate_caused_it(): void
    {
        $this->assertMatchesRegularExpression(
            '/device_converted/',
            $this->source,
            'Conversion never flags itself, so gate-driven conversions are invisible '
            . 'in reporting.'
        );
    }

    /**
     * The question is only relevant to the thermostat; leaving it visible and
     * required after switching devices would strand the customer.
     */
    public function test_wifi_question_visibility_follows_device_selection(): void
    {
        $this->assertMatchesRegularExpression(
            '/isf-wifi-check/',
            $this->source,
            'Nothing toggles the WiFi fieldset, so it is either always hidden '
            . '(question never asked) or always shown (asked of switch customers too).'
        );
    }

    public function test_callout_visibility_is_driven_by_the_answer(): void
    {
        $this->assertMatchesRegularExpression(
            '/isf-wifi-callout/',
            $this->source,
            'Nothing toggles the callout, so it never appears.'
        );
    }
}

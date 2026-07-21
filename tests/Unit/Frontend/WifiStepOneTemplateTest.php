<?php
/**
 * WifiStepOneTemplateTest — renders the real Step 1 template.
 *
 * Fragment-string tests can pass while the shipped file is broken, so this
 * includes `step-1-program.php` itself and asserts against its actual output.
 *
 * The accessibility assertions are not decoration. The callout is the moment a
 * customer is told they cannot have the thing they just chose, and WCAG AA is
 * this codebase's baseline rather than a feature: meaning carried only by the
 * colour red is invisible to a screen reader and to anyone who cannot
 * distinguish it, so the callout has to say what is wrong in words and
 * announce itself.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Unit\Frontend;

use ISF\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

final class WifiStepOneTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('checked')->justReturn('');
        Functions\when('esc_html_e')->echoArg();
        Functions\when('esc_attr_e')->echoArg();
        require_once ISF_PLUGIN_DIR . 'public/class-public.php';
    }

    /**
     * Render the shipped template with the given instance settings.
     */
    private function render(array $settings = [], array $form_data = []): string
    {
        $instance = ['id' => 1, 'settings' => $settings];

        ob_start();
        include ISF_PLUGIN_DIR . 'includes/intellisource/templates/enrollment/step-1-program.php';
        return (string) ob_get_clean();
    }

    public function test_gated_instance_renders_the_wifi_question(): void
    {
        $html = $this->render(['require_wifi' => true]);

        $this->assertStringContainsString('name="has_wifi"', $html, 'The WiFi question is missing entirely.');
        $this->assertStringContainsString('value="yes"', $html);
        $this->assertStringContainsString('value="no"', $html);
    }

    /**
     * An instance that never opted in must render exactly what it rendered
     * before this feature existed.
     */
    public function test_ungated_instance_renders_no_wifi_question(): void
    {
        $html = $this->render([]);

        $this->assertStringNotContainsString(
            'name="has_wifi"',
            $html,
            'A utility that never asked for this gate is now being shown it.'
        );
    }

    public function test_ungated_instance_still_renders_both_device_options(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('value="thermostat"', $html);
        $this->assertStringContainsString('value="dcu"', $html);
    }

    public function test_callout_announces_itself_to_assistive_technology(): void
    {
        $html = $this->render(['require_wifi' => true]);

        $this->assertMatchesRegularExpression(
            '/role=["\']alert["\']/',
            $html,
            'The conversion callout must be announced. A silent colour change tells a '
            . 'screen-reader user nothing about why they cannot continue.'
        );
    }

    /**
     * The WCAG AA requirement in practice: strip the styling and the callout
     * must still state the problem.
     */
    public function test_callout_states_the_problem_in_words_not_only_colour(): void
    {
        $html = $this->render(['require_wifi' => true]);

        $this->assertMatchesRegularExpression(
            '/WiFi is required/i',
            $html,
            'The callout carries no text explaining the requirement, so its meaning '
            . 'depends entirely on being able to see that it is red.'
        );
    }

    public function test_callout_offers_the_switch_conversion_action(): void
    {
        $html = $this->render(['require_wifi' => true]);

        $this->assertStringContainsString(
            'isf-convert-to-dcu',
            $html,
            'There is no conversion button, so a customer without WiFi has nowhere to go.'
        );
    }

    /**
     * The reassurance the client explicitly asked for. Participation-level
     * parity is verifiable in the code (Step 2 renders the same 50/75/100
     * options for both devices), so it must actually appear.
     */
    public function test_callout_reassures_that_the_program_is_the_same(): void
    {
        $html = $this->render(['require_wifi' => true]);

        $this->assertMatchesRegularExpression(
            '/same participation levels/i',
            $html,
            'The client asked customers to be told the program is unchanged. Without it '
            . 'the callout reads as a downgrade and people abandon.'
        );
    }

    /**
     * The callout must start hidden. Rendering it open would tell every
     * customer they are ineligible before they have answered anything.
     */
    public function test_callout_is_hidden_until_the_customer_answers_no(): void
    {
        $html = $this->render(['require_wifi' => true]);

        $this->assertMatchesRegularExpression(
            '/id=["\']isf-wifi-callout["\'][^>]*hidden/i',
            $html,
            'The callout is not hidden on load, so everyone sees the ineligibility '
            . 'warning before answering.'
        );
    }

    /**
     * All customer-facing strings must be instance-editable, which is what
     * makes the unverified "same bill credits" claim a settings change rather
     * than a code release if the client cannot confirm it.
     */
    public function test_callout_copy_is_instance_overridable(): void
    {
        $html = $this->render([
            'require_wifi' => true,
            'content'      => [
                'wifi_question'        => 'Custom question text',
                'wifi_callout_heading' => 'Custom heading',
                'wifi_callout_body'    => 'Custom body',
                'wifi_convert_button'  => 'Custom button',
            ],
        ]);

        foreach (['Custom question text', 'Custom heading', 'Custom body', 'Custom button'] as $custom) {
            $this->assertStringContainsString(
                $custom,
                $html,
                "Instance content override '{$custom}' was ignored; the copy is hardcoded."
            );
        }
    }
}

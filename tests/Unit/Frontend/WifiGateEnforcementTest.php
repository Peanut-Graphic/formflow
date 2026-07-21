<?php
/**
 * WifiGateEnforcementTest — the gate must actually be reached.
 *
 * validateStep1() knows how to reject a thermostat with no WiFi, but that is
 * worth nothing if the final-submission path never turns the flag on. This is
 * the seam where a correct-looking feature silently does nothing: the rule
 * exists, the tests for the rule pass, and every real submission sails through
 * because the caller kept passing the default.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Unit\Frontend;

use ISF\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use ReflectionMethod;

final class WifiGateEnforcementTest extends TestCase
{
    private const GATED_INSTANCE   = ['id' => 1, 'settings' => ['require_wifi' => true]];
    private const UNGATED_INSTANCE = ['id' => 2, 'settings' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockWpdb([]);
        Functions\when('is_email')->alias(
            fn($email) => (bool) filter_var($email, FILTER_VALIDATE_EMAIL)
        );
        require_once ISF_PLUGIN_DIR . 'public/class-public.php';
    }

    /**
     * Invoke the private final-submission validator directly.
     */
    private function validateAll(array $form_data, array $instance): array
    {
        $frontend = new \ISF\Frontend\Frontend();

        $method = new ReflectionMethod($frontend, 'validate_all_form_steps');

        return $method->invoke($frontend, $form_data, $instance);
    }

    private function completeFormData(array $overrides = []): array
    {
        return array_merge([
            'has_ac'       => 'yes',
            'device_type'  => 'thermostat',
            'utility_no'   => '1234567890',
            'zip'          => '20852',
            'first_name'   => 'Ada',
            'last_name'    => 'Lovelace',
            'email'        => 'ada@example.com',
            'phone'        => '2025550123',
            'street'       => '1 Main St',
            'city'         => 'Rockville',
            'state'        => 'MD',
            'agree_terms'  => true,
            'schedule_later' => true,
        ], $overrides);
    }

    /**
     * The bypass this whole feature exists to close: a request that skips the
     * browser entirely and posts thermostat + no WiFi straight at the endpoint.
     */
    public function test_final_submission_is_rejected_for_thermostat_without_wifi(): void
    {
        $errors = $this->validateAll(
            $this->completeFormData(['has_wifi' => 'no']),
            self::GATED_INSTANCE
        );

        $this->assertArrayHasKey(
            'has_wifi',
            $errors,
            'A hand-crafted POST bypassing the browser gate reached the API. The '
            . 'server-side check is not wired into the submission path.'
        );
    }

    public function test_final_submission_is_rejected_when_wifi_answer_is_missing(): void
    {
        $errors = $this->validateAll($this->completeFormData(), self::GATED_INSTANCE);

        $this->assertArrayHasKey('has_wifi', $errors, 'Omitting the field must not bypass the gate.');
    }

    public function test_final_submission_succeeds_for_thermostat_with_wifi(): void
    {
        $errors = $this->validateAll(
            $this->completeFormData(['has_wifi' => 'yes']),
            self::GATED_INSTANCE
        );

        $this->assertArrayNotHasKey('has_wifi', $errors, 'The happy path must not be blocked.');
    }

    /**
     * The destination of the conversion flow has to pass cleanly, or the
     * feature steers people into a dead end.
     */
    public function test_converted_switch_enrollment_passes_the_gate(): void
    {
        $errors = $this->validateAll(
            $this->completeFormData(['device_type' => 'dcu', 'has_wifi' => 'no']),
            self::GATED_INSTANCE
        );

        $this->assertArrayNotHasKey('has_wifi', $errors, 'Converting to the switch must resolve the gate.');
    }

    /**
     * Utilities that never opted in must be completely unaffected.
     */
    public function test_ungated_instance_still_accepts_thermostat_without_wifi(): void
    {
        $errors = $this->validateAll($this->completeFormData(), self::UNGATED_INSTANCE);

        $this->assertArrayNotHasKey(
            'has_wifi',
            $errors,
            'An instance that never enabled the gate must behave exactly as it did before.'
        );
    }

    /**
     * Callers that predate the gate pass one argument; they must not fatal.
     */
    public function test_instance_argument_is_optional(): void
    {
        $method = new ReflectionMethod(\ISF\Frontend\Frontend::class, 'validate_all_form_steps');
        $params = $method->getParameters();

        $this->assertCount(2, $params, 'validate_all_form_steps must accept the instance to read its gate setting.');
        $this->assertTrue(
            $params[1]->isOptional(),
            'The instance argument must be optional so pre-existing callers keep working.'
        );
    }
}

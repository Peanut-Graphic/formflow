<?php
/**
 * WifiGateSettingTest — the gate is opt-in, per instance.
 *
 * FormFlow serves several Itron utilities off one enrollment flow. Only the
 * PHI instances (Pepco MD/DC, Delmarva DE/MD) asked for a WiFi requirement, so
 * the gate must be inert everywhere it has not been explicitly switched on.
 * A setting that defaults to "on", or that reads a truthy-ish string as
 * consent, would silently change live forms for clients who never asked.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class WifiGateSettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once ISF_PLUGIN_DIR . 'public/class-public.php';
    }

    public function test_gate_is_off_when_the_setting_is_absent(): void
    {
        $this->assertFalse(
            \ISF\Frontend\isf_requires_wifi(['settings' => []]),
            'An instance that never opted in must not be gated.'
        );
    }

    public function test_gate_is_off_for_an_instance_with_no_settings_at_all(): void
    {
        $this->assertFalse(
            \ISF\Frontend\isf_requires_wifi([]),
            'A malformed instance must fail safe: no gate, not a crash.'
        );
    }

    public function test_gate_is_on_when_explicitly_enabled(): void
    {
        $this->assertTrue(
            \ISF\Frontend\isf_requires_wifi(['settings' => ['require_wifi' => true]])
        );
    }

    /**
     * WordPress settings round-trip through forms and JSON, so a stored "1"
     * or "yes" is the same intent as boolean true.
     *
     * @dataProvider truthyStoredValues
     */
    public function test_gate_is_on_for_stored_truthy_values($stored): void
    {
        $this->assertTrue(
            \ISF\Frontend\isf_requires_wifi(['settings' => ['require_wifi' => $stored]]),
            'A stored truthy value must enable the gate.'
        );
    }

    public static function truthyStoredValues(): array
    {
        return [
            'boolean true' => [true],
            'integer one'  => [1],
            'string one'   => ['1'],
            'string yes'   => ['yes'],
            'string true'  => ['true'],
            'string on'    => ['on'],
        ];
    }

    /**
     * The dangerous case. An unchecked checkbox commonly persists as "0" or
     * "false" — strings that are truthy in PHP. Reading either as consent
     * would switch the gate on for a utility that turned it off.
     *
     * @dataProvider falsyStoredValues
     */
    public function test_gate_is_off_for_stored_falsy_values($stored): void
    {
        $this->assertFalse(
            \ISF\Frontend\isf_requires_wifi(['settings' => ['require_wifi' => $stored]]),
            'A stored falsy value must leave the gate off, including the truthy-in-PHP strings.'
        );
    }

    public static function falsyStoredValues(): array
    {
        return [
            'boolean false' => [false],
            'integer zero'  => [0],
            'string zero'   => ['0'],
            'string false'  => ['false'],
            'string off'    => ['off'],
            'string no'     => ['no'],
            'empty string'  => [''],
            'null'          => [null],
        ];
    }
}

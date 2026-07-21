<?php
/**
 * WiFi Eligibility Gate — Step 1 validation
 *
 * A Web-Programmable Thermostat cannot be installed in a home without WiFi.
 * When an instance opts into the gate, Step 1 must reject that combination
 * server-side. A front-end-only check is bypassable, and the whole point of
 * the feature is preventing installs that cannot succeed.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Unit\Frontend;

use ISF\Tests\Unit\TestCase;
use ISF\Forms\FormHandler;
use ISF\Database\Database;
use ISF\Security;
use Mockery;

class WifiEligibilityTest extends TestCase
{
    private FormHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new FormHandler(
            Mockery::mock(Database::class),
            Mockery::mock(Security::class)
        );
    }

    /**
     * The case the client reported: someone picks the thermostat, says they
     * have no WiFi, and today sails straight through to a scheduled install.
     */
    public function testRejectsThermostatWhenCustomerHasNoWifi(): void
    {
        $valid = $this->handler->validateStep1([
            'has_ac'      => 'yes',
            'device_type' => 'thermostat',
            'has_wifi'    => 'no',
        ], true);

        $this->assertFalse($valid, 'Thermostat with no WiFi must not validate.');
        $this->assertArrayHasKey('has_wifi', $this->handler->getErrors());
    }

    public function testAcceptsThermostatWhenCustomerHasWifi(): void
    {
        $valid = $this->handler->validateStep1([
            'has_ac'      => 'yes',
            'device_type' => 'thermostat',
            'has_wifi'    => 'yes',
        ], true);

        $this->assertTrue($valid, 'Thermostat with WiFi is the normal happy path.');
    }

    /**
     * The gate must not let an unanswered question through. Omitting the field
     * is the simplest possible bypass of a front-end-only check.
     */
    public function testRejectsThermostatWhenWifiQuestionUnanswered(): void
    {
        $valid = $this->handler->validateStep1([
            'has_ac'      => 'yes',
            'device_type' => 'thermostat',
        ], true);

        $this->assertFalse($valid, 'Thermostat with no WiFi answer must not validate.');
        $this->assertArrayHasKey('has_wifi', $this->handler->getErrors());
    }

    /**
     * The switch has no WiFi requirement, so the question is never asked and
     * its absence must never block enrollment.
     */
    public function testAcceptsSwitchWithoutAnyWifiAnswer(): void
    {
        $valid = $this->handler->validateStep1([
            'has_ac'      => 'yes',
            'device_type' => 'dcu',
        ], true);

        $this->assertTrue($valid, 'The Outdoor Switch does not require WiFi.');
    }

    /**
     * This is the destination of the conversion flow: no WiFi, switch selected.
     * It must be the most valid combination in the system, not an edge case.
     */
    public function testAcceptsSwitchWhenCustomerHasNoWifi(): void
    {
        $valid = $this->handler->validateStep1([
            'has_ac'      => 'yes',
            'device_type' => 'dcu',
            'has_wifi'    => 'no',
        ], true);

        $this->assertTrue($valid, 'No WiFi plus switch is exactly what the gate steers people toward.');
    }

    /**
     * Utilities that never asked for this gate must be untouched. Their forms
     * do not render the question, so their submissions carry no answer.
     */
    public function testGateIsInertWhenInstanceHasNotOptedIn(): void
    {
        $valid = $this->handler->validateStep1([
            'has_ac'      => 'yes',
            'device_type' => 'thermostat',
        ], false);

        $this->assertTrue($valid, 'Instances without the gate enabled must behave exactly as before.');
    }

    /**
     * Callers that predate the gate pass one argument. They must keep working.
     */
    public function testGateDefaultsToDisabledForLegacyCallers(): void
    {
        $valid = $this->handler->validateStep1([
            'has_ac'      => 'yes',
            'device_type' => 'thermostat',
            'has_wifi'    => 'no',
        ]);

        $this->assertTrue($valid, 'The gate must be opt-in, never a surprise for existing callers.');
    }

    /**
     * A junk value is not consent. Anything that is not an explicit "yes"
     * fails closed.
     */
    public function testRejectsUnrecognisedWifiValueForThermostat(): void
    {
        $valid = $this->handler->validateStep1([
            'has_ac'      => 'yes',
            'device_type' => 'thermostat',
            'has_wifi'    => 'maybe',
        ], true);

        $this->assertFalse($valid, 'Only an explicit "yes" clears the WiFi gate.');
        $this->assertArrayHasKey('has_wifi', $this->handler->getErrors());
    }

    /**
     * The gate must not mask the checks that already existed.
     */
    public function testExistingDeviceTypeValidationStillApplies(): void
    {
        $valid = $this->handler->validateStep1([
            'has_ac'      => 'yes',
            'device_type' => 'space_heater',
            'has_wifi'    => 'yes',
        ], true);

        $this->assertFalse($valid);
        $this->assertArrayHasKey('device_type', $this->handler->getErrors());
    }

    public function testExistingAcValidationStillApplies(): void
    {
        $valid = $this->handler->validateStep1([
            'device_type' => 'thermostat',
            'has_wifi'    => 'yes',
        ], true);

        $this->assertFalse($valid);
        $this->assertArrayHasKey('has_ac', $this->handler->getErrors());
    }
}

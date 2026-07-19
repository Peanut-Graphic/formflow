<?php
/**
 * The shared base connector is utility-agnostic: everything is driven by the
 * configured api_endpoint, and it ships no named presets. A utility is a thin
 * subclass (DominionPtrConnector). These prove the base stands alone and that
 * the Dominion specialization still overrides id/prefix/preset correctly.
 *
 * Ported from FormFlow Lite PR #24 alongside the connector. The base exists so
 * a future PHI (Pepco/Delmarva) XML->JSON migration has a home; it deliberately
 * ships NO utility presets, so nothing here implies PHI is validated.
 *
 * Lives in the REGRESSION suite (blocking Net 6 gate), not `unit` — CI runs
 * `unit` with `continue-on-error: true`, so these would have been unenforced.
 *
 * @package FormFlow\Tests\Regression
 */

namespace ISF\Tests\Regression;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ISF\Connectors\DominionPtr\DominionPtrConnector;
use ISF\Connectors\PowerportalJson\PowerportalJsonConnector;
use PHPUnit\Framework\TestCase;

require_once ISF_PLUGIN_DIR . 'includes/api/interface-api-connector.php';
require_once ISF_PLUGIN_DIR . 'includes/api/class-scheduling-result.php';
require_once ISF_PLUGIN_DIR . 'connectors/powerportal-json/class-powerportal-json-connector.php';
require_once ISF_PLUGIN_DIR . 'connectors/dominion-ptr/class-dominion-ptr-connector.php';

final class PowerportalJsonConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function baseReturning(array $byPath): PowerportalJsonConnector
    {
        return new class($byPath) extends PowerportalJsonConnector
        {
            private array $byPath;

            public function __construct(array $byPath)
            {
                $this->byPath = $byPath;
            }

            protected function http_get_json(string $url, array $query = []): array
            {
                foreach ($this->byPath as $needle => $resp) {
                    if (strpos($url, $needle) !== false) {
                        return $resp;
                    }
                }
                throw new \Exception("no fixture for {$url}");
            }
        };
    }

    public function test_base_is_utility_agnostic(): void
    {
        $c = new PowerportalJsonConnector();

        $this->assertSame('powerportal-json', $c->get_id());
        $this->assertSame([], $c->get_presets(), 'base must ship no utility presets — no unvalidated facades');
        $this->assertSame(['enrollment'], $c->get_supported_features());
    }

    public function test_validate_works_against_any_endpoint(): void
    {
        $c = $this->baseReturning([
            'prospect/validate' => ['status' => 'found', 'data' => [
                'prospect_id' => 12, 'first_name' => 'A', 'last_name' => 'B', 'name' => 'A B',
                'email' => 'a@b.com', 'utility_no' => '999',
                'enrollable_premises' => [['id' => 1, 'address' => 'X', 'zip' => '00000']],
            ]],
            'portal_user_emails' => ['available' => true, 'has_login_history' => false],
        ]);

        $r = $c->validate_account(
            ['account_number' => '999', 'zip' => '00000', 'email' => 'a@b.com'],
            ['api_endpoint' => 'https://some-other-utility.powerportal.com/x/api']
        );

        $this->assertTrue($r->is_valid());
        $this->assertSame(12, $r->get_customer_data()['prospect_id']);
    }

    public function test_base_demo_enroll_uses_generic_prefix(): void
    {
        $c = new PowerportalJsonConnector();
        $r = $c->submit_enrollment(
            ['account_number' => '999', 'email' => 'a@b.com'],
            ['api_endpoint' => 'https://x/api', 'test_mode' => true]
        );

        $this->assertTrue($r->is_successful());
        $this->assertStringStartsWith('DEMO-', $r->get_confirmation_number());
    }

    public function test_base_live_enrollment_is_not_implemented(): void
    {
        $c = new PowerportalJsonConnector();
        $r = $c->submit_enrollment(
            ['account_number' => '999', 'email' => 'a@b.com'],
            ['api_endpoint' => 'https://x/api', 'test_mode' => false]
        );

        $this->assertFalse($r->is_successful());
        $this->assertSame('not_implemented', $r->get_error_code());
    }

    public function test_dominion_specialization_overrides_id_prefix_and_preset(): void
    {
        $d = new DominionPtrConnector();

        $this->assertInstanceOf(PowerportalJsonConnector::class, $d);
        $this->assertSame('dominion-ptr', $d->get_id(), 'id must stay dominion-ptr — the seeded instance depends on it');
        $this->assertArrayHasKey('dominion_ptr', $d->get_presets());

        $r = $d->submit_enrollment(
            ['account_number' => '210010506231', 'email' => 'x@gmail.com'],
            ['api_endpoint' => 'https://x/api', 'test_mode' => true]
        );
        $this->assertStringStartsWith('PTR-DEMO-', $r->get_confirmation_number());
    }
}

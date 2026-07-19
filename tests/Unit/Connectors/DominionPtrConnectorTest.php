<?php
/**
 * Characterization tests for the Dominion PTR connector, ported into Pro
 * alongside the connector itself.
 *
 * Why these live here: dominionenergyptr.com runs FormFlow Pro, but the
 * dominion-ptr connector shipped only in FormFlow Lite. The connector and the
 * live site were in different plugins, so nothing Pro shipped could serve
 * Dominion. Porting the code without its tests would have moved the capability
 * and left the proof behind.
 *
 * PTR is a rate/bill-credit program: no device, no install, no scheduling.
 * The unsupported scheduling paths are pinned deliberately so a future change
 * cannot quietly turn them into "not implemented yet".
 *
 * @package FormFlow\Tests\Unit
 */

namespace ISF\Tests\Unit\Connectors;

use Brain\Monkey\Functions;
use ISF\Api\ApiClient;
use ISF\Connectors\DominionPtr\DominionPtrConnector;
use ISF\Connectors\DominionPtr\Seeder;
use ISF\Tests\Unit\TestCase;

require_once ISF_PLUGIN_DIR . 'includes/api/interface-api-connector.php';
require_once ISF_PLUGIN_DIR . 'includes/api/class-scheduling-result.php';
require_once ISF_PLUGIN_DIR . 'connectors/dominion-ptr/class-dominion-ptr-connector.php';
require_once ISF_PLUGIN_DIR . 'connectors/dominion-ptr/class-dominion-ptr-seeder.php';

/**
 * Test double: swaps the HTTP layer for canned fixtures so connector parsing
 * is exercised without a network. Mirrors the seam the Lite suite used.
 */
final class FakeDominionPtrConnector extends DominionPtrConnector
{
    /** @var array<string,array> path fragment => canned decoded JSON */
    public array $responses = [];

    /** @var array<int,string> every URL the connector attempted */
    public array $requested = [];

    /** @var string|null path fragment that should throw instead of return */
    public ?string $throwOn = null;

    protected function http_get_json(string $url, array $query = []): array
    {
        $this->requested[] = $url;

        foreach ($this->responses as $fragment => $payload) {
            if (str_contains($url, $fragment)) {
                if ($this->throwOn !== null && str_contains($url, $this->throwOn)) {
                    throw new \Exception('simulated transport failure');
                }

                return $payload;
            }
        }

        throw new \Exception("no fixture for {$url}");
    }
}

final class DominionPtrConnectorTest extends TestCase
{
    private DominionPtrConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg(1);
        $this->connector = new DominionPtrConnector();
    }

    // ---------------------------------------------------------------- identity

    public function test_exposes_stable_connector_id_and_features(): void
    {
        $this->assertSame('dominion-ptr', $this->connector->get_id());
        $this->assertSame(['enrollment'], $this->connector->get_supported_features());
        $this->assertTrue($this->connector->supports('enrollment'));
        $this->assertFalse($this->connector->supports('scheduling'));
    }

    public function test_exposes_dominion_ptr_preset_pointing_at_the_live_json_api(): void
    {
        $presets = $this->connector->get_presets();

        $this->assertArrayHasKey('dominion_ptr', $presets);
        $this->assertSame(
            'https://www.dominionenergyptr.com/ptr/residential/api',
            $presets['dominion_ptr']['api_endpoint']
        );
        // PTR is device-less; these flags drive the reduced step list.
        $this->assertTrue($presets['dominion_ptr']['disable_device']);
        $this->assertTrue($presets['dominion_ptr']['disable_scheduling']);
    }

    // ------------------------------------------------------------- flow shape

    public function test_ptr_settings_yield_the_device_less_step_list(): void
    {
        $steps = $this->connector->enrollment_steps([
            'disable_device' => true,
            'disable_scheduling' => true,
        ]);

        $this->assertSame(['validate', 'address_confirm', 'terms', 'enroll'], $steps);
    }

    public function test_device_programs_reinstate_device_and_scheduling_steps(): void
    {
        $steps = $this->connector->enrollment_steps([]);

        $this->assertSame(
            ['validate', 'device', 'address_confirm', 'scheduling', 'terms', 'enroll'],
            $steps
        );
    }

    public function test_scheduling_is_unsupported_not_merely_unimplemented(): void
    {
        // PTR has no install appointment. This must stay 'unsupported' so it is
        // never mistaken for Stage 2 work that someone still owes.
        $this->assertSame('unsupported', $this->connector->get_schedule_slots([], [])->get_error_code());
        $this->assertSame('unsupported', $this->connector->book_appointment([], [])->get_error_code());
    }

    // ---------------------------------------------------------- field mapping

    public function test_map_fields_normalises_account_zip_and_email(): void
    {
        $mapped = $this->connector->map_fields([
            'account_number' => '  210010506231 ',
            'zip' => '23219-1234',
            'email' => '  Person@Example.com ',
        ]);

        $this->assertSame('210010506231', $mapped['utility_no']);
        $this->assertSame('232191234', $mapped['zip']); // non-digits stripped
        $this->assertSame('Person@Example.com', $mapped['email']);
    }

    public function test_map_fields_accepts_utility_no_as_an_alias_for_account_number(): void
    {
        $mapped = $this->connector->map_fields(['utility_no' => '999', 'zip' => '', 'email' => '']);

        $this->assertSame('999', $mapped['utility_no']);
    }

    // -------------------------------------------------------------- validate

    public function test_validate_account_maps_a_found_prospect_to_customer_data(): void
    {
        $fake = new FakeDominionPtrConnector();
        $fake->responses = [
            '/prospect/validate' => [
                'status' => 'found',
                'data' => [
                    'prospect_id' => 42,
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'name' => 'Ada Lovelace',
                    'email' => 'ada@example.com',
                    'utility_no' => '210010506231',
                    'enrollable_premises' => [['id' => 7, 'address' => '1 Main St', 'zip' => '23219']],
                ],
            ],
            '/portal_user_emails' => ['available' => false, 'has_login_history' => true],
        ];

        $result = $fake->validate_account(
            ['account_number' => '210010506231', 'zip' => '23219', 'email' => 'ada@example.com'],
            ['api_endpoint' => 'https://www.dominionenergyptr.com/ptr/residential/api']
        );

        $this->assertTrue($result->is_valid());
        $data = $result->get_customer_data();
        $this->assertSame(42, $data['prospect_id']);
        $this->assertSame('Ada Lovelace', $data['name']);
        $this->assertCount(1, $data['enrollable_premises']);
        // Portal enrichment is folded in.
        $this->assertFalse($data['portal_available']);
        $this->assertTrue($data['has_login_history']);
    }

    public function test_validate_account_reports_not_found_without_customer_data(): void
    {
        $fake = new FakeDominionPtrConnector();
        $fake->responses = ['/prospect/validate' => ['status' => 'not found', 'data' => []]];

        $result = $fake->validate_account(
            ['account_number' => '000', 'zip' => '00000', 'email' => 'nobody@example.com'],
            ['api_endpoint' => 'https://www.dominionenergyptr.com/ptr/residential/api']
        );

        $this->assertFalse($result->is_valid());
        $this->assertSame('not found', $result->get_error_code());
    }

    public function test_portal_lookup_failure_is_non_fatal_and_leaves_nulls(): void
    {
        $fake = new FakeDominionPtrConnector();
        $fake->responses = [
            '/prospect/validate' => [
                'status' => 'found',
                'data' => ['prospect_id' => 1, 'email' => 'ada@example.com', 'utility_no' => '1'],
            ],
            '/portal_user_emails' => ['available' => true],
        ];
        $fake->throwOn = '/portal_user_emails';

        $result = $fake->validate_account(
            ['account_number' => '1', 'zip' => '23219', 'email' => 'ada@example.com'],
            ['api_endpoint' => 'https://www.dominionenergyptr.com/ptr/residential/api']
        );

        // The enrollment path must survive a portal-lookup outage.
        $this->assertTrue($result->is_valid());
        $this->assertNull($result->get_customer_data()['portal_available']);
    }

    public function test_transport_failure_on_validate_is_reported_as_connection_error(): void
    {
        $fake = new FakeDominionPtrConnector(); // no fixtures => always throws

        $result = $fake->validate_account(
            ['account_number' => '1', 'zip' => '1', 'email' => 'a@b.com'],
            ['api_endpoint' => 'https://www.dominionenergyptr.com/ptr/residential/api']
        );

        $this->assertFalse($result->is_valid());
        $this->assertSame('connection_error', $result->get_error_code());
    }

    // --------------------------------------------------------------- enroll

    public function test_test_mode_returns_a_deterministic_demo_confirmation(): void
    {
        $config = ['test_mode' => true];
        $data = ['account_number' => '210010506231', 'zip' => '23219', 'email' => 'ada@example.com'];

        $first = $this->connector->submit_enrollment($data, $config);
        $second = $this->connector->submit_enrollment($data, $config);

        $this->assertTrue($first->is_successful());
        $this->assertStringStartsWith('PTR-DEMO-', $first->get_confirmation_number());
        // Deterministic: same input, same confirmation. Demos must be repeatable.
        $this->assertSame($first->get_confirmation_number(), $second->get_confirmation_number());
    }

    public function test_live_enrollment_is_explicitly_not_implemented(): void
    {
        // Stage 2 is blocked on Itron (enroll endpoint, verification, IP
        // allowlist). This assertion is the tripwire that flips when Stage 2
        // lands — if it starts failing, the live path went in and this test
        // should be replaced, not deleted.
        $result = $this->connector->submit_enrollment(
            ['account_number' => '1', 'zip' => '1', 'email' => 'a@b.com'],
            ['test_mode' => false]
        );

        $this->assertFalse($result->is_successful());
        $this->assertSame('not_implemented', $result->get_error_code());
    }

    // --------------------------------------------------------------- config

    public function test_validate_config_rejects_a_missing_or_malformed_endpoint(): void
    {
        $this->assertNotEmpty($this->connector->validate_config([]));
        $this->assertNotEmpty($this->connector->validate_config(['api_endpoint' => 'not-a-url']));
        $this->assertSame([], $this->connector->validate_config([
            'api_endpoint' => 'https://www.dominionenergyptr.com/ptr/residential/api',
        ]));
    }

    // --------------------------------------------------------------- seeder

    public function test_seeder_builds_the_ptr_instance_row_in_test_mode(): void
    {
        $row = Seeder::build_instance_row();

        $this->assertSame('dominion-ptr', $row['slug']);
        $this->assertSame('dominion', $row['utility']);
        $this->assertSame('enrollment', $row['form_type']);
        $this->assertSame(1, $row['test_mode'], 'seeded instances must not go live by default');
        $this->assertSame('dominion-ptr', $row['settings']['connector']);
        $this->assertTrue($row['settings']['disable_scheduling']);
        $this->assertSame('GTM-KG937MGX', $row['settings']['analytics']['gtmContainerId']);
    }

    // ----------------------------------------------------- shared SSRF guard

    /**
     * The connector routes every outbound call through
     * ApiClient::is_safe_outbound_url(), which was ported into Pro with it.
     * These pin the guard on IP literals so the check is deterministic and
     * needs no DNS.
     *
     * @dataProvider blockedUrlProvider
     */
    public function test_ssrf_guard_blocks_non_public_targets(string $url): void
    {
        $this->assertFalse(ApiClient::is_safe_outbound_url($url));
    }

    /** @return array<string,array{0:string}> */
    public static function blockedUrlProvider(): array
    {
        return [
            'loopback v4' => ['http://127.0.0.1/prospect/validate'],
            'loopback name' => ['http://localhost/prospect/validate'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'rfc1918 10/8' => ['https://10.29.84.71/ptr/residential/api'],
            'rfc1918 192.168' => ['https://192.168.1.1/'],
            'loopback v6' => ['http://[::1]/'],
            'file scheme' => ['file:///etc/passwd'],
            'no host' => ['not-a-url'],
        ];
    }

    public function test_ssrf_guard_allows_a_public_literal(): void
    {
        // 8.8.8.8 is public and needs no resolution, keeping this test hermetic.
        $this->assertTrue(ApiClient::is_safe_outbound_url('https://8.8.8.8/ptr/residential/api'));
    }
}

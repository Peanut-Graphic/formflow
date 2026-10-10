<?php
/**
 * ValidateAccountExposureTest — account-validation PII oracle (audit 2026-10, LOW).
 *
 * BEFORE: isf_validate_account answered any account number + ZIP pair with the
 * account holder's full name, email and service address, throttled only by the
 * shared 120 requests/minute limit — an enumeration oracle for anyone holding
 * (or guessing) account/ZIP pairs.
 *
 * AFTER: the response carries only a masked summary (first name, last-name
 * initial, masked email, city/state/ZIP — no street), the full values stay in
 * the server-side session that pre-fills later steps, the JS no longer copies
 * customer details out of the response into its form data, and the handler has
 * its own, much tighter per-IP throttle that runs before the utility API call.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Regression;

use Brain\Monkey\Functions;
use ISF\Database\Database;
use ISF\Frontend\Frontend;
use ISF\Security;
use ISF\SessionGuard;
use ISF\Tests\Helpers\JsonResponseSent;
use ISF\Tests\Unit\TestCase;

final class ValidateAccountExposureTest extends TestCase
{
    private const INSTANCE = [
        'id' => 7, 'slug' => 'ewr', 'form_type' => 'enrollment', 'test_mode' => 0, 'is_active' => 1,
        'settings' => ['demo_mode' => true],
    ];

    private array $rows = [];
    private array $transients = [];
    private Frontend $frontend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockWpdb(['insert' => 1, 'insert_id' => 1, 'get_var' => null, 'get_row' => null, 'get_results' => [], 'query' => 1]);
        $_POST = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $this->rows = [];
        $this->transients = [];

        Functions\when('wp_unslash')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) { $this->transients[$k] = $v; return true; });
        Functions\when('wp_send_json_success')->alias(function ($d = null) { throw new JsonResponseSent(true, $d); });
        Functions\when('wp_send_json_error')->alias(function ($d = null) { throw new JsonResponseSent(false, $d); });

        require_once ISF_PLUGIN_DIR . 'public/class-public.php';
        $this->frontend = (new \ReflectionClass(Frontend::class))->newInstanceWithoutConstructor();

        $db = \Mockery::mock(Database::class);
        $db->shouldReceive('get_instance_by_slug')->andReturn(self::INSTANCE);
        $db->shouldReceive('get_submission_by_session')->andReturnUsing(function ($sid) {
            foreach ($this->rows as $row) { if ($row['session_id'] === $sid) { return $row; } }
            return null;
        });
        $db->shouldReceive('create_submission')->andReturnUsing(function (array $d) {
            $this->rows[] = array_merge(['id' => count($this->rows) + 1, 'status' => 'in_progress'], $d);
            return count($this->rows);
        });
        $db->shouldReceive('update_submission')->andReturn(true);
        $db->shouldReceive('mark_session_as_test')->andReturnNull();
        $db->shouldReceive('log')->andReturn(1);
        $db->shouldReceive('get_webhooks_for_event')->andReturn([]);
        (new \ReflectionProperty(Frontend::class, 'db'))->setValue($this->frontend, $db);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    private function validate(string $account = '1234567890', string $zip = '20001'): JsonResponseSent
    {
        $session = SessionGuard::issue(self::INSTANCE['id']);
        $_POST = [
            'nonce' => 'n', 'instance' => self::INSTANCE['slug'],
            'session_id' => $session['session_id'], 'session_token' => $session['session_token'],
            'utility_no' => $account, 'zip' => $zip,
        ];
        try {
            $this->frontend->isf_validate_account();
        } catch (JsonResponseSent $sent) {
            return $sent;
        }
        $this->fail('isf_validate_account sent no JSON.');
    }

    public function test_response_carries_only_a_masked_customer_summary(): void
    {
        $sent = $this->validate();
        $this->assertTrue($sent->ok, json_encode($sent->payload));

        $json = json_encode($sent->payload);
        $this->assertStringNotContainsString('john.smith@example.com', $json, 'Full email must not be returned.');
        $this->assertStringNotContainsString('Smith', $json, 'Full last name must not be returned.');
        $this->assertStringNotContainsString('123 Main Street', $json, 'Street address must not be returned.');

        $customer = $sent->payload['customer'];
        $this->assertSame('John', $customer['first_name']);
        $this->assertSame('S.', $customer['last_name']);
        $this->assertMatchesRegularExpression('/^j\*+@example\.com$/', $customer['email']);
        $this->assertSame(['city' => 'Washington', 'state' => 'DC', 'zip' => '20001'], $customer['address']);
    }

    public function test_full_values_stay_in_the_server_session(): void
    {
        $this->validate();
        $stored = $this->rows[0]['form_data'];
        $this->assertSame('Smith', $stored['last_name']);
        $this->assertSame('john.smith@example.com', $stored['email']);
        $this->assertSame('123 Main Street', $stored['address']['street']);
    }

    public function test_validation_has_its_own_tight_per_ip_throttle(): void
    {
        $limit = Security::VALIDATE_RATE_LIMIT_DEFAULT;
        $this->assertLessThanOrEqual(30, $limit);

        for ($i = 0; $i < $limit; $i++) {
            $this->assertTrue($this->validate()->ok, "attempt {$i} should pass");
        }
        $blocked = $this->validate();
        $this->assertFalse($blocked->ok, 'Validation must be throttled well below the shared 120/min limit.');
        $this->assertSame('rate_limited', $blocked->payload['code'] ?? null);
    }

    public function test_masking_helpers(): void
    {
        $this->assertSame('j*********@example.com', Security::mask_email('john.smith@example.com'));
        $this->assertSame('', Security::mask_email('not-an-email'));
        $this->assertSame('', Security::mask_email(''));
    }

    public function test_frontend_does_not_copy_customer_details_into_form_data(): void
    {
        $js = file_get_contents(ISF_PLUGIN_DIR . 'public/assets/js/enrollment.js');
        $this->assertDoesNotMatchRegularExpression('/formData\.[a-z_]+\s*=\s*response\.data\.customer\./', $js,
            'Masked customer values must never be written back into formData (they would overwrite the session).');
    }
}

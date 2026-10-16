<?php
/**
 * ProviderPrivacyFlowTest — account-validation PII oracle (audit 2026-10, LOW).
 *
 * BEFORE: isf_validate_account answered any account number + ZIP pair with the
 * account holder's full name, email and service address, throttled only by the
 * shared 120 requests/minute limit — an enumeration oracle for anyone holding
 * (or guessing) account/ZIP pairs.
 *
 * AFTER: the response carries only a masked summary (first name, last-name
 * initial, masked email, city/state/ZIP — no street), the full values stay in
 * private server storage and never pre-fill public steps, the JS no longer copies
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

final class ProviderPrivacyFlowTest extends TestCase {
    private const INSTANCE = ['id'=>7,'slug'=>'ewr','form_type'=>'enrollment','test_mode'=>0,'is_active'=>1,'settings'=>['demo_mode'=>true]];
    private array $instance;
    private array $rows=[];
    private array $transients=[];
    private array $session=[];
    private array $resumeTokens=[];
    private array $emails=[];
    private Frontend $frontend;
    private ?array $response=null;
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockWpdb(['insert' => 1, 'insert_id' => 1, 'get_var' => null, 'get_row' => null, 'get_results' => [], 'query' => 1]);
        $this->instance = self::INSTANCE;
        $this->session = SessionGuard::issue(7);
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
        Functions\when('wp_send_json_success')->alias(function ($d = null) { $this->response=['success'=>true,'data'=>$d]; });
        Functions\when('wp_send_json_error')->alias(function ($d = null) { $this->response=['success'=>false,'data'=>$d]; });

        Functions\when('esc_html_e')->echoArg();
        Functions\when('esc_attr_e')->echoArg();
        Functions\when('esc_textarea')->returnArg();
        Functions\when('selected')->justReturn('');
        Functions\when('checked')->justReturn('');
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('is_email')->alias(fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);
        Functions\when('wp_generate_password')->justReturn('synthetic-resume-token');
        Functions\when('wp_mail')->alias(function ($to, $subject, $message, $headers=[]) { $this->emails[] = compact('to','subject','message','headers'); return true; });
        Functions\when('get_current_user_id')->justReturn(0);
        require_once ISF_PLUGIN_DIR . 'public/class-public.php';
        require_once ISF_PLUGIN_DIR . 'includes/class-utm-tracker.php';
        $this->frontend = (new \ReflectionClass(Frontend::class))->newInstanceWithoutConstructor();

        $db = \Mockery::mock(Database::class);
        $db->shouldReceive('get_instance_by_slug')->andReturnUsing(fn() => $this->instance);
        $db->shouldReceive('get_submission_by_session')->andReturnUsing(function ($sid) {
            foreach ($this->rows as $row) { if ($row['session_id'] === $sid) { return $row; } }
            return null;
        });
        $db->shouldReceive('create_submission')->andReturnUsing(function (array $d) {
            $this->rows[] = array_merge(['id' => count($this->rows) + 1, 'status' => 'in_progress'], $d);
            return count($this->rows);
        });
        $db->shouldReceive('update_submission')->andReturnUsing(function ($id, array $data) {
            foreach ($this->rows as &$row) { if ($row['id'] === $id) { $row = array_merge($row, $data); } }
            return true;
        });
        $db->shouldReceive('save_resume_token')->andReturnUsing(function ($sid, $iid, $token, $email) {
            $this->resumeTokens[$token] = ['session_id'=>$sid, 'instance_id'=>$iid, 'email'=>$email]; return true;
        });
        $db->shouldReceive('get_resume_token')->andReturnUsing(fn($token) => $this->resumeTokens[$token] ?? null);
        $db->shouldReceive('mark_resume_token_used')->andReturn(true);
        $db->shouldReceive('mark_session_as_test')->andReturnNull();
        $db->shouldReceive('log')->andReturnUsing(function ($level,$message) { if ($level === 'error') { fwrite(STDERR, $message."\n"); } return 1; });
        $db->shouldReceive('get_webhooks_for_event')->andReturn([]);
        (new \ReflectionProperty(Frontend::class, 'db'))->setValue($this->frontend, $db);
        (new \ReflectionProperty(Frontend::class, 'form_handler'))->setValue($this->frontend, new \ISF\Forms\FormHandler($db, new \ISF\Security()));
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }


    private function call(string $handler, array $post=[]): array {
        $_POST = array_merge($this->session, ['nonce'=>'n','instance'=>'ewr'], $post);
        $this->response=null;
        $this->frontend->{$handler}();
        $this->assertNotNull($this->response, $handler.' did not send JSON');
        return $this->response;
    }
    private function validate(): void {
        $r=$this->call('isf_validate_account',['utility_no'=>'1234567890','zip'=>'20001']);
        $this->assertTrue($r['success'],json_encode($r));
    }
    private function assertNoProviderContact(array $response): void {
        $json=json_encode($response);
        foreach (['Smith','john.smith@example.com','123 Main Street','validation_result','_provider_validation','_public_input','_legacy_form_data'] as $secret) {
            $this->assertStringNotContainsString($secret,$json);
        }
    }
    public function test_provider_details_are_absent_from_every_enrollment_and_scheduler_render(): void {
        foreach (['enrollment' => [1, 2, 3, 4, 5, 'success'], 'scheduler' => [1, 2, 'success']] as $type => $steps) {
            $this->instance['form_type'] = $type;
            $this->session = SessionGuard::issue(7);
            $this->validate();
            foreach ($steps as $step) {
                $response = $this->call('isf_load_step', ['step' => $step]);
                $this->assertTrue($response['success'], $type . '/' . $step . ': ' . json_encode($response));
                $this->assertNoProviderContact($response);
            }
        }
    }

    public function test_save_email_and_resume_cannot_disclose_provider_contact_to_another_mailbox(): void {
        $this->validate();
        $saved = $this->call('isf_save_and_email', ['step' => 3, 'email' => 'caller@example.com', 'form_data' => '{}']);
        $this->assertTrue($saved['success'], json_encode($saved));
        $token = array_key_first($this->resumeTokens);
        $this->assertNotNull($token);
        $resumed = $this->call('isf_resume_form', ['resume_token' => $token]);
        $this->assertTrue($resumed['success']);
        $this->assertNoProviderContact($resumed);
        foreach (['ca_no', 'comverge_no', 'fsr_no', 'enrollment_response', 'scheduling_result'] as $private) {
            $this->assertArrayNotHasKey($private, $resumed['data']['form_data']);
        }
    }

    private function callerInput(): array {
        return ['first_name' => 'Alice', 'last_name' => 'Tester', 'email' => 'alice.input@example.com',
            'email_confirm' => 'alice.input@example.com', 'phone' => '2025550101', 'street' => '9 Caller Lane',
            'city' => 'Washington', 'state' => 'DC', 'zip' => '20001', 'zip_confirm' => '20001',
            'device_type' => 'thermostat', 'thermostat_count' => '1', 'cycling_level' => '100',
            'ownership' => 'own', 'has_ac' => 'yes', 'agree_terms' => true, 'agree_adult' => true,
            'agree_contact' => true, 'schedule_later' => true];
    }

    public function test_caller_input_survives_save_navigation_resume_and_enrollment_mapping(): void {
        $this->validate();
        $input = $this->callerInput();
        $saved = $this->call('isf_save_progress', ['step' => 3, 'form_data' => json_encode($input)]);
        $this->assertTrue($saved['success']);
        $rendered = $this->call('isf_load_step', ['step' => 3]);
        $this->assertTrue($rendered['success']);
        $this->assertStringContainsString('alice.input@example.com', $rendered['data']['html']);
        $this->assertNoProviderContact($rendered);
        $saved = $this->call('isf_save_and_email', ['step' => 3, 'email' => 'caller@example.com', 'form_data' => '{}']);
        $this->assertTrue($saved['success']);
        $resumed = $this->call('isf_resume_form', ['resume_token' => array_key_first($this->resumeTokens)]);
        $this->assertSame('Alice', $resumed['data']['form_data']['first_name']);
        $this->assertSame('alice.input@example.com', $resumed['data']['form_data']['email']);
        $this->assertNoProviderContact($resumed);
        $mapped = \ISF\Api\FieldMapper::mapEnrollmentData(reset($this->rows)['form_data']);
        $this->assertSame('Alice', $mapped['fname']);
        $this->assertSame('Tester', $mapped['lname']);
        $this->assertSame('alice.input@example.com', $mapped['email']);
        $this->assertSame('1234567890', $mapped['utility_no']);
    }

    public function test_legacy_contact_requires_explicit_reentry_and_injected_provenance_does_not_help(): void {
        $this->rows[0] = ['id' => 1, 'status' => 'in_progress', 'instance_id' => 7,
            'session_id' => $this->session['session_id'], 'step' => 3, 'form_data' => [
                'first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john.smith@example.com',
                'address' => ['street' => '123 Main Street'], 'validation_result' => ['email' => 'john.smith@example.com'],
                '_public_input' => ['version' => 1, 'fields' => ['email' => 'john.smith@example.com'], 'signature' => 'forged'],
            ]];
        $response = $this->call('isf_load_step', ['step' => 3, 'form_data' => json_encode([
            '_public_input' => ['version' => 1, 'fields' => ['email' => 'john.smith@example.com'], 'signature' => 'forged'],
            '_provider_validation' => ['email' => 'john.smith@example.com'],
        ])]);
        $this->assertTrue($response['success']);
        $this->assertNoProviderContact($response);
        $this->call('isf_save_progress', ['step' => 3, 'form_data' => json_encode(['email' => 'caller@example.com'])]);
        $rendered = $this->call('isf_load_step', ['step' => 3]);
        $this->assertStringContainsString('caller@example.com', $rendered['data']['html']);
        $this->assertNoProviderContact($rendered);
    }

    public function test_scheduler_still_gets_slots_books_and_renders_a_private_confirmation(): void {
        $this->instance['form_type'] = 'scheduler';
        $this->validate();
        $slots = $this->call('isf_get_schedule_slots');
        $this->assertTrue($slots['success'], json_encode($slots));
        $this->assertNotEmpty($slots['data']['slots']);
        srand(1);
        try {
            $booked = $this->call('isf_book_appointment', ['schedule_date' => '2026-11-02', 'schedule_time' => 'AM']);
        } finally {
            srand();
        }
        $this->assertTrue($booked['success'], json_encode($booked));
        $emails = $this->emails;
        $this->assertCount(1, $emails);
        $this->assertSame('john.smith@example.com', $emails[0]['to']);
        $confirmation = $this->call('isf_load_step', ['step' => 'success']);
        $this->assertTrue($confirmation['success']);
        $this->assertStringContainsString('2026-11-02', $confirmation['data']['html']);
        $this->assertNoProviderContact($confirmation);
    }
    public function test_revalidation_clears_old_scheduling_references_and_requires_fresh_slots(): void {
        $this->instance['form_type'] = 'scheduler';
        $this->validate();
        $this->assertTrue($this->call('isf_get_schedule_slots')['success']);
        $this->assertNotEmpty($this->rows[0]['form_data']['fsr_no']);
        $this->validate();
        $this->assertArrayNotHasKey('fsr_no', $this->rows[0]['form_data']);
        $this->assertArrayNotHasKey('scheduling_result', $this->rows[0]['form_data']);
        $this->assertFalse($this->call('isf_book_appointment', ['schedule_date' => '2026-11-02', 'schedule_time' => 'AM'])['success']);
    }

    public function test_enrolled_session_cannot_rebind_its_account(): void {
        $this->validate();
        $this->rows[0]['status'] = 'completed';
        $before = $this->rows[0]['form_data'];
        $response = $this->call('isf_validate_account', ['utility_no' => '9999999999', 'zip' => '20002']);
        $this->assertFalse($response['success']);
        $this->assertSame(\ISF\SessionGuard::ERROR_COMPLETED, $response['data']['code']);
        $this->assertSame($before, $this->rows[0]['form_data']);
    }

    public function test_full_enrollment_uses_only_caller_contact_for_mail_and_saved_integrations(): void {
        $this->validate();
        $input=$this->callerInput();
        $this->call('isf_save_progress',['step'=>3,'form_data'=>json_encode($input)]);
        $response=$this->call('isf_submit_enrollment',['form_data'=>json_encode($input)]);
        $this->assertTrue($response['success'],json_encode($response));
        $this->assertSame('completed',$this->rows[0]['status']);
        $this->assertSame('alice.input@example.com',$this->emails[0]['to']);
        $this->assertStringNotContainsString('Smith',$this->emails[0]['message']);
        $this->assertStringNotContainsString('123 Main Street',$this->emails[0]['message']);
        $this->assertSame('Alice',$this->rows[0]['form_data']['first_name']);
        $this->assertSame('2025550101',$this->rows[0]['form_data']['phone']);
        $this->assertSame('john.smith@example.com',$this->rows[0]['form_data']['_provider_validation']['customer']['email']);
        $this->assertNoProviderContact($response);
    }

}

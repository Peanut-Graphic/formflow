<?php
/**
 * EnrollmentSessionBindingTest — cached-page session sharing (audit 2026-10, HIGH).
 *
 * BEFORE the fix the shortcode rendered a random session id into the page
 * HTML (data-session) and every wizard AJAX handler looked the submission up
 * by that client-sent id alone. Behind a full-page cache every visitor of the
 * cached copy shared one id, so visitor B loaded — and overwrote — visitor A's
 * name, email, phone, address and account number. Sessions also stayed
 * writable after completion.
 *
 * AFTER the fix:
 *   - no session material is rendered into HTML;
 *   - sessions are issued by the uncached isf_start_session bootstrap as an
 *     (id, HMAC token) pair, and every handler refuses a pair that does not
 *     verify (hash_equals) before touching the database;
 *   - a completed session refuses further writes.
 *
 * These tests drive the real handlers with an in-memory submissions store.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Regression;

use Brain\Monkey\Functions;
use ISF\Database\Database;
use ISF\Frontend\Frontend;
use ISF\SessionGuard;
use ISF\Tests\Helpers\HaltAtWrite;
use ISF\Tests\Helpers\JsonResponseSent;
use ISF\Tests\Unit\TestCase;

final class EnrollmentSessionBindingTest extends TestCase
{
    private const INSTANCE = [
        'id'         => 7,
        'slug'       => 'ewr',
        'form_type'  => 'enrollment',
        'test_mode'  => 0,
        'is_active'  => 1,
        'settings'   => ['demo_mode' => true],
    ];

    /** @var array<int, array> In-memory isf_submissions rows keyed by id. */
    private array $rows = [];

    private Frontend $frontend;

    /** @var string|null When set, update_submission halts (HaltAtWrite) on a status change to this value. */
    private ?string $haltOnStatus = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockWpdb(['insert' => 1, 'get_var' => null, 'get_row' => null, 'query' => 1]);

        $_POST   = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $this->rows = [];

        Functions\when('wp_unslash')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('is_email')->alias(fn($e) => (bool) filter_var($e, FILTER_VALIDATE_EMAIL));
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('do_action')->justReturn(null);
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            throw new JsonResponseSent(true, $data);
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            throw new JsonResponseSent(false, $data);
        });

        require_once ISF_PLUGIN_DIR . 'public/class-public.php';

        $this->frontend = (new \ReflectionClass(Frontend::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(Frontend::class, 'db');
        $db = $this->fakeDatabase();
        $prop->setValue($this->frontend, $db);

        $handler = new \ReflectionProperty(Frontend::class, 'form_handler');
        $handler->setValue($this->frontend, new \ISF\Forms\FormHandler($db, new \ISF\Security()));
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    private function fakeDatabase(): Database
    {
        $db = \Mockery::mock(Database::class);
        $db->shouldReceive('get_instance_by_slug')->andReturnUsing(
            fn($slug) => $slug === self::INSTANCE['slug'] ? self::INSTANCE : null
        );
        $db->shouldReceive('get_instance')->andReturnUsing(
            fn($id) => (int) $id === self::INSTANCE['id'] ? self::INSTANCE : null
        );
        $db->shouldReceive('get_submission_by_session')->andReturnUsing(function ($session_id, $instance_id) {
            foreach (array_reverse($this->rows, true) as $row) {
                if ($row['session_id'] === $session_id && (int) $row['instance_id'] === (int) $instance_id) {
                    return $row;
                }
            }
            return null;
        });
        $db->shouldReceive('create_submission')->andReturnUsing(function (array $data) {
            $id = count($this->rows) + 1;
            $this->rows[$id] = array_merge(['id' => $id, 'status' => 'in_progress', 'form_data' => []], $data);
            return $id;
        });
        $db->shouldReceive('update_submission')->andReturnUsing(function ($id, array $data) {
            if ($this->haltOnStatus !== null && ($data['status'] ?? null) === $this->haltOnStatus) {
                throw new HaltAtWrite($data);
            }
            $this->rows[$id] = array_merge($this->rows[$id], $data);
            return true;
        });
        $db->shouldReceive('mark_session_as_test')->andReturnNull();
        $db->shouldReceive('log')->andReturn(1);
        $db->shouldReceive('add_to_retry_queue')->andReturnNull();

        return $db;
    }

    /**
     * Run a public handler with the given POST body and return the JSON it sent.
     */
    private function call(string $handler, array $post): JsonResponseSent
    {
        $_POST = array_merge(['nonce' => 'n', 'instance' => self::INSTANCE['slug']], $post);
        try {
            $this->frontend->{$handler}();
        } catch (JsonResponseSent $sent) {
            return $sent;
        }
        $this->fail("{$handler} returned without sending a JSON response.");
    }

    /** Visitor bootstrap: what the browser does once the (possibly cached) page loads. */
    private function startSession(): array
    {
        $sent = $this->call('isf_start_session', []);
        $this->assertTrue($sent->ok, 'isf_start_session must succeed for an active instance.');
        $this->assertArrayHasKey('session_id', $sent->payload);
        $this->assertArrayHasKey('session_token', $sent->payload);
        return $sent->payload;
    }

    private function seedRow(string $session_id, array $form_data, string $status = 'in_progress'): int
    {
        $id = count($this->rows) + 1;
        $this->rows[$id] = [
            'id'          => $id,
            'instance_id' => self::INSTANCE['id'],
            'session_id'  => $session_id,
            'status'      => $status,
            'step'        => 3,
            'form_data'   => $form_data,
        ];
        return $id;
    }

    private function assertSessionRefused(JsonResponseSent $sent, string $why): void
    {
        $this->assertFalse($sent->ok, $why);
        $this->assertSame(SessionGuard::ERROR_INVALID, $sent->payload['code'] ?? null, $why);
    }

    // ------------------------------------------------------------------
    // Cached HTML carries no session
    // ------------------------------------------------------------------

    public function test_shortcode_does_not_render_session_material_into_html(): void
    {
        $source = file_get_contents(ISF_PLUGIN_DIR . 'public/class-public.php');
        preg_match('/function render_form_shortcode\(.*?\n    }\n/s', $source, $m);
        $this->assertNotEmpty($m, 'render_form_shortcode not found');

        $this->assertStringNotContainsString('data-session', $m[0],
            'The wizard container must not carry a session id: a page cache would hand it to every visitor.');
        $this->assertStringNotContainsString('generate_session_id', $m[0],
            'Sessions must be issued by the uncached isf_start_session call, never while rendering cacheable HTML.');
    }

    public function test_shortcode_marks_page_uncacheable(): void
    {
        $source = file_get_contents(ISF_PLUGIN_DIR . 'public/class-public.php');
        $this->assertMatchesRegularExpression('/SessionGuard::mark_page_uncacheable\(\)/', $source,
            'Rendering the form must ask page caches not to store the page (DONOTCACHEPAGE + nocache_headers).');

        $plugin = file_get_contents(ISF_PLUGIN_DIR . 'includes/class-plugin.php');
        $this->assertMatchesRegularExpression("/'template_redirect'\s*,\s*\[\s*\\\$this->public\s*,\s*'maybe_disable_page_cache'\s*\]/", $plugin,
            'Cache headers must be sent from template_redirect, before output starts.');
        $this->assertStringContainsString("'isf_start_session'", $plugin,
            'isf_start_session must be registered as a public AJAX action (and mirrored as formflow_start_session).');
    }

    public function test_frontend_js_bootstraps_session_and_sends_token(): void
    {
        $js = file_get_contents(ISF_PLUGIN_DIR . 'public/assets/js/enrollment.js');

        $this->assertStringContainsString("action: 'formflow_start_session'", $js,
            'enrollment.js must obtain its session from the uncached bootstrap call.');
        $this->assertDoesNotMatchRegularExpression("/sessionId\s*=\s*\\\$container\.data\('session'\)/", $js,
            'enrollment.js must not read a session id out of (cacheable) page markup.');

        $ids    = preg_match_all('/(?<![a-z_])session_id: ISFEnrollment\.sessionId,/', $js);
        $tokens = preg_match_all('/session_token: ISFEnrollment\.sessionToken,/', $js);
        $this->assertGreaterThan(0, $ids);
        $this->assertSame($ids, $tokens, 'Every AJAX payload that sends session_id must also send session_token.');

        $this->assertMatchesRegularExpression('/isf_session_id: ISFEnrollment\.sessionId,\s*\n\s*isf_step/', $js,
            'The session token must never be pushed to the GTM dataLayer.');

        $autosave = file_get_contents(ISF_PLUGIN_DIR . 'public/assets/js/auto-save.js');
        $this->assertStringContainsString("session_token: \$container.data('sessionToken')", $autosave);
    }

    // ------------------------------------------------------------------
    // Bootstrap issues distinct, verifiable, uncached sessions
    // ------------------------------------------------------------------

    public function test_two_visitors_of_the_same_cached_page_get_different_sessions(): void
    {
        $a = $this->startSession();
        $b = $this->startSession();

        $this->assertNotSame($a['session_id'], $b['session_id']);
        $this->assertTrue(SessionGuard::verify($a['session_id'], $a['session_token'], self::INSTANCE['id']));
        $this->assertTrue(SessionGuard::verify($b['session_id'], $b['session_token'], self::INSTANCE['id']));
        $this->assertFalse(SessionGuard::verify($a['session_id'], $b['session_token'], self::INSTANCE['id']),
            "B's token must not unlock A's session.");
        $this->assertFalse(SessionGuard::verify($a['session_id'], $a['session_token'], 8),
            'A token is bound to its instance.');
    }

    public function test_start_session_refuses_unknown_instance(): void
    {
        $sent = $this->call('isf_start_session', ['instance' => 'nope']);
        $this->assertFalse($sent->ok);
    }

    // ------------------------------------------------------------------
    // Visitor B cannot read or write visitor A's session
    // ------------------------------------------------------------------

    public function test_visitor_b_cannot_read_visitor_a_session_via_load_step(): void
    {
        $a = $this->startSession();
        $b = $this->startSession();
        $this->seedRow($a['session_id'], ['first_name' => 'Ada', 'email' => 'ada@example.com']);

        // Old-world attack: only the shared id (what used to be in the cached HTML).
        $this->assertSessionRefused(
            $this->call('isf_load_step', ['session_id' => $a['session_id'], 'step' => 3]),
            'load_step must refuse a session id presented without its token.'
        );
        // B pairs A's id with B's own valid token.
        $this->assertSessionRefused(
            $this->call('isf_load_step', ['session_id' => $a['session_id'], 'session_token' => $b['session_token'], 'step' => 3]),
            "load_step must refuse A's session id with B's token."
        );
    }

    public function test_visitor_b_cannot_overwrite_visitor_a_session(): void
    {
        $a = $this->startSession();
        $b = $this->startSession();
        $rowId = $this->seedRow($a['session_id'], ['first_name' => 'Ada', 'email' => 'ada@example.com']);

        foreach (['isf_save_progress', 'isf_save_and_email', 'isf_validate_account', 'isf_get_schedule_slots', 'isf_submit_enrollment', 'isf_book_appointment', 'isf_track_step'] as $handler) {
            $this->assertSessionRefused(
                $this->call($handler, [
                    'session_id'    => $a['session_id'],
                    'session_token' => $b['session_token'],
                    'step'          => 3,
                    'email'         => 'mallory@example.com',
                    'utility_no'    => '1234567890',
                    'zip'           => '20001',
                    'schedule_date' => '2026-11-02',
                    'schedule_time' => 'AM',
                    'form_data'     => json_encode(['email' => 'mallory@example.com']),
                ]),
                "{$handler} must refuse A's session id with B's token."
            );
        }

        $this->assertSame('ada@example.com', $this->rows[$rowId]['form_data']['email'], "A's data must be untouched.");
        $this->assertCount(1, $this->rows, 'No row may be created for a forged session.');
    }

    public function test_client_chosen_session_ids_are_refused(): void
    {
        $this->assertSessionRefused(
            $this->call('isf_save_progress', [
                'session_id' => str_repeat('a', 64),
                'step'       => 2,
                'form_data'  => json_encode(['first_name' => 'Eve']),
            ]),
            'A session id the server never issued must not create or touch a row.'
        );
        $this->assertSame([], $this->rows);
    }

    public function test_owner_with_valid_pair_can_still_save_progress(): void
    {
        $a = $this->startSession();

        $sent = $this->call('isf_save_progress', [
            'session_id'    => $a['session_id'],
            'session_token' => $a['session_token'],
            'step'          => 1,
            'form_data'     => json_encode(['device_type' => 'thermostat']),
        ]);

        $this->assertTrue($sent->ok, 'The legitimate owner must keep working.');
        $this->assertCount(1, $this->rows);
        $this->assertSame('thermostat', reset($this->rows)['form_data']['device_type']);
    }

    // ------------------------------------------------------------------
    // Completed sessions are frozen
    // ------------------------------------------------------------------

    public function test_completed_session_refuses_further_writes(): void
    {
        $a = $this->startSession();
        $rowId = $this->seedRow($a['session_id'], ['email' => 'ada@example.com', 'account_number' => '1234567890'], 'completed');

        foreach (['isf_save_progress', 'isf_save_and_email', 'isf_validate_account', 'isf_get_schedule_slots', 'isf_submit_enrollment', 'isf_book_appointment'] as $handler) {
            $sent = $this->call($handler, [
                'session_id'    => $a['session_id'],
                'session_token' => $a['session_token'],
                'step'          => 3,
                'email'         => 'ada@example.com',
                'utility_no'    => '1234567890',
                'zip'           => '20001',
                'schedule_date' => '2026-11-02',
                'schedule_time' => 'AM',
                'form_data'     => json_encode(['email' => 'changed@example.com']),
            ]);
            $this->assertFalse($sent->ok, "{$handler} must refuse a completed session.");
            $this->assertSame(SessionGuard::ERROR_COMPLETED, $sent->payload['code'] ?? null, $handler);
        }

        $this->assertSame('ada@example.com', $this->rows[$rowId]['form_data']['email']);
    }

    public function test_completed_session_may_not_reopen_earlier_steps(): void
    {
        $a = $this->startSession();
        $this->seedRow($a['session_id'], ['email' => 'ada@example.com'], 'completed');

        $sent = $this->call('isf_load_step', [
            'session_id'    => $a['session_id'],
            'session_token' => $a['session_token'],
            'step'          => 3,
        ]);
        $this->assertFalse($sent->ok, 'A completed session must not re-open earlier (PII-bearing) steps.');
        $this->assertSame(SessionGuard::ERROR_COMPLETED, $sent->payload['code'] ?? null);
    }

    // ------------------------------------------------------------------
    // Final submit trusts only the server-validated account (audit 2026-10, MEDIUM)
    // ------------------------------------------------------------------

    /** Complete, valid step 1-5 data as the browser would post it at submit. */
    private function clientSubmitData(array $overrides = []): array
    {
        return array_merge([
            'has_ac'           => 'yes',
            'device_type'      => 'thermostat',
            'cycling_level'    => '100',
            'utility_no'       => '1234567890',
            'zip'              => '20001',
            'zip_confirm'      => '20001',
            'agree_adult'      => true,
            'first_name'       => 'Ada',
            'last_name'        => 'Lovelace',
            'email'            => 'ada@example.com',
            'phone'            => '2025550123',
            'street'           => '1 Main St',
            'city'             => 'Washington',
            'state'            => 'DC',
            'ownership'        => 'own',
            'thermostat_count' => '1',
            'agree_terms'      => true,
            'schedule_later'   => true,
        ], $overrides);
    }

    /**
     * Submit and return the form_data the handler tried to persist as completed.
     */
    private function submitAndCaptureCompletion(array $session, array $client): ?array
    {
        $this->haltOnStatus = 'completed';
        try {
            $sent = $this->call('isf_submit_enrollment', [
                'session_id'    => $session['session_id'],
                'session_token' => $session['session_token'],
                'form_data'     => json_encode($client),
            ]);
        } catch (HaltAtWrite $halt) {
            return $halt->data['form_data'];
        } finally {
            $this->haltOnStatus = null;
        }
        $this->lastRefusal = $sent;
        return null;
    }

    private ?JsonResponseSent $lastRefusal = null;

    public function test_submit_refuses_a_session_whose_account_was_never_validated(): void
    {
        $a = $this->startSession();

        // Seed everything — including an account number — through save_progress,
        // never calling validate_account.
        $this->call('isf_save_progress', [
            'session_id'    => $a['session_id'],
            'session_token' => $a['session_token'],
            'step'          => 4,
            'form_data'     => json_encode($this->clientSubmitData([
                'account_number'    => '5555555555',
                'account_validated' => true,
                'ca_no'             => 'CA-FORGED',
            ])),
        ]);

        $completed = $this->submitAndCaptureCompletion($a, $this->clientSubmitData(['account_number' => '5555555555']));

        $this->assertNull($completed, 'An enrollment whose account was never validated server-side reached completion.');
        $this->assertFalse($this->lastRefusal->ok);
        $this->assertSame('account_not_validated', $this->lastRefusal->payload['code'] ?? null);
    }

    public function test_save_progress_cannot_seed_server_owned_keys(): void
    {
        $a = $this->startSession();

        $this->call('isf_save_progress', [
            'session_id'    => $a['session_id'],
            'session_token' => $a['session_token'],
            'step'          => 2,
            'form_data'     => json_encode([
                'first_name'        => 'Eve',
                'account_number'    => '5555555555',
                'utility_no'        => '5555555555',
                'account_validated' => true,
                'ca_no'             => 'CA-FORGED',
                'comverge_no'       => 'CV-FORGED',
            ]),
        ]);

        $stored = reset($this->rows)['form_data'];
        $this->assertSame('Eve', $stored['first_name']);
        foreach (['account_number', 'utility_no', 'account_validated', 'ca_no', 'comverge_no'] as $key) {
            $this->assertArrayNotHasKey($key, $stored, "save_progress must not let the client write {$key}.");
        }
    }

    public function test_submit_enrolls_the_validated_account_not_the_posted_one(): void
    {
        $a = $this->startSession();
        $this->seedRow($a['session_id'], [
            'account_validated' => true,
            'account_number'    => '1234567890',
            'utility_no'        => '1234567890',
            'zip_code'          => '20001',
            'ca_no'             => 'CA-REAL',
            'comverge_no'       => 'CV-REAL',
        ]);

        $completed = $this->submitAndCaptureCompletion($a, $this->clientSubmitData([
            'utility_no'     => '9999999999',
            'account_number' => '9999999999',
            'ca_no'          => 'CA-EVIL',
            'comverge_no'    => 'CV-EVIL',
        ]));

        $this->assertNotNull($completed, 'A validated session with complete data must still be able to submit: '
            . json_encode($this->lastRefusal->payload ?? null));
        $this->assertSame('1234567890', $completed['account_number']);
        $this->assertSame('1234567890', $completed['utility_no'],
            'utility_no is what FieldMapper sends to the enroll API first; it must be the validated account.');
        $this->assertSame('CA-REAL', $completed['ca_no']);
        $this->assertSame('CV-REAL', $completed['comverge_no']);

        $mapped = \ISF\Api\FieldMapper::mapEnrollmentData($completed);
        $this->assertSame('1234567890', $mapped['utility_no'] ?? null, 'The enroll API call must carry the validated account.');
    }

    public function test_validate_account_stores_the_validated_account_as_server_owned_fields(): void
    {
        $source = file_get_contents(ISF_PLUGIN_DIR . 'public/traits/trait-ajax-handlers.php');
        preg_match('/function isf_validate_account\(.*?\n    }\n/s', $source, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString("\$form_data['account_validated'] = true;", $m[0]);
        $this->assertStringContainsString("\$form_data['utility_no'] = \$account_number;", $m[0]);
    }
}

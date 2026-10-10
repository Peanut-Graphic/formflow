<?php
/**
 * HandoffCompletionForgeryTest — forged external completions + handoff open
 * redirect (audit 2026-10, MEDIUM x2).
 *
 * BEFORE the fix:
 *   - GET /isf/v1/completions/redirect (public) recorded an
 *     isf_external_completions row for ANY known handoff token, every time it
 *     was hit, with the account_number / email taken from the query string,
 *     marked the handoff completed and fired the Peanut Suite conversion hook.
 *     Tokens were free: POST /isf/v1/handoff (public) minted one for any
 *     instance_id and any destination.
 *   - POST /isf/v1/handoff accepted any http(s) destination and
 *     ?isf_handoff=<token> / GET /handoff/<token> redirected to it — even after
 *     the handoff expired.
 *
 * AFTER the fix:
 *   - redirect completions need isf_sig = HMAC-SHA256(per-instance secret,
 *     token), are rate-limited, are claimed atomically once
 *     (UPDATE ... WHERE status = 'redirected'), and take account/email from
 *     the stored handoff, never the query string;
 *   - handoffs can only target the instance's configured destination host(s),
 *     checked again at redirect time, and expired handoffs do not redirect.
 *
 * @package FormFlow
 */

namespace {
    require_once __DIR__ . '/../Helpers/wp-rest-stubs.php';
}

namespace ISF\Tests\Regression {

    use Brain\Monkey\Functions;
    use ISF\Analytics\CompletionReceiver;
    use ISF\Analytics\CompletionSigner;
    use ISF\Analytics\HandoffTracker;
    use ISF\Analytics\TouchRecorder;
    use ISF\Analytics\VisitorTracker;
    use ISF\Api\HandoffEndpoint;
    use ISF\Database\Database;
    use ISF\Hooks;
    use ISF\Tests\Unit\TestCase;

    final class RedirectIssued extends \RuntimeException
    {
        public function __construct(public string $url)
        {
            parent::__construct('redirect: ' . $url);
        }
    }

    final class HandoffCompletionForgeryTest extends TestCase
    {
        private const TOKEN = '0123456789abcdef0123456789abcdef';

        private const INSTANCE = [
            'id'        => 3,
            'slug'      => 'ptr',
            'is_active' => 1,
            'form_type' => 'external',
            'settings'  => [
                'external_url'  => 'https://enroll.partner.example/start',
                'thank_you_url' => 'https://site.example/thanks',
            ],
        ];

        /** @var array<string, array> handoff rows keyed by token */
        private array $handoffs = [];
        /** @var array<int, array> rows inserted into isf_external_completions */
        private array $completions = [];
        /** @var array<string, mixed> in-memory wp_options */
        private array $options = [];
        private int $conversionHooks = 0;

        protected function setUp(): void
        {
            parent::setUp();
            $_SERVER['REMOTE_ADDR'] = '198.51.100.20';

            $this->handoffs = [
                self::TOKEN => [
                    'id'              => 11,
                    'instance_id'     => self::INSTANCE['id'],
                    'visitor_id'      => 'v1',
                    'handoff_token'   => self::TOKEN,
                    'destination_url' => 'https://enroll.partner.example/start',
                    'attribution'     => '{}',
                    'status'          => 'redirected',
                    'account_number'  => null,
                    'external_id'     => null,
                    'completion_data' => null,
                    'created_at'      => date('Y-m-d H:i:s', time() - 3600),
                    'completed_at'    => null,
                ],
            ];
            $this->completions = [];
            $this->options = [];
            $this->conversionHooks = 0;

            $this->fakeWpdb();

            Functions\when('get_option')->alias(fn($k, $d = false) => $this->options[$k] ?? $d);
            Functions\when('update_option')->alias(function ($k, $v) { $this->options[$k] = $v; return true; });
            Functions\when('add_option')->alias(function ($k, $v) {
                if (array_key_exists($k, $this->options)) { return false; }
                $this->options[$k] = $v; return true;
            });
            Functions\when('apply_filters')->alias(fn($hook, $value = null) => $value);
            Functions\when('do_action')->alias(function ($hook) {
                if ($hook === Hooks::EXTERNAL_COMPLETION) {
                    $this->conversionHooks++;
                }
            });
            Functions\when('wp_safe_redirect')->alias(function ($url) { throw new RedirectIssued((string) $url); });
            Functions\when('wp_redirect')->alias(function ($url) { throw new RedirectIssued((string) $url); });
            Functions\when('sanitize_key')->alias(fn($k) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)));
            Functions\when('wp_parse_url')->alias(fn($u, $c = -1) => parse_url($u, $c));
        }

        private function fakeWpdb(): void
        {
            $wpdb = $this->mockWpdb(['insert_id' => 0]);
            $wpdb->shouldReceive('get_row')->andReturnUsing(function ($sql) {
                foreach ($this->handoffs as $token => $row) {
                    if (strpos((string) $sql, $token) !== false) {
                        return $row;
                    }
                }
                return null;
            })->byDefault();
            $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) {
                // Atomic claim: UPDATE ... SET status = 'completed' ... WHERE handoff_token = 'x' AND status IN (...)
                if (preg_match("/UPDATE .*handoffs.*handoff_token = '([a-f0-9]{32})' AND status IN \\(([^)]*)\\)/s", (string) $sql, $m)) {
                    $allowed = array_map(fn($s) => trim($s, " '"), explode(',', $m[2]));
                    $row = $this->handoffs[$m[1]] ?? null;
                    if ($row && in_array($row['status'], $allowed, true)) {
                        $this->handoffs[$m[1]]['status'] = 'completed';
                        return 1;
                    }
                    return 0;
                }
                return 0;
            })->byDefault();
            $wpdb->shouldReceive('update')->andReturnUsing(function ($table, $data, $where) {
                // Legacy unconditional mark_completed.
                if (isset($where['handoff_token'], $this->handoffs[$where['handoff_token']])) {
                    $this->handoffs[$where['handoff_token']] = array_merge($this->handoffs[$where['handoff_token']], $data);
                    return 1;
                }
                return 0;
            })->byDefault();
            $wpdb->shouldReceive('insert')->andReturnUsing(function ($table, $data) use ($wpdb) {
                if (str_ends_with((string) $table, 'isf_external_completions')) {
                    $this->completions[] = $data;
                    $wpdb->insert_id = count($this->completions);
                }
                return 1;
            })->byDefault();
        }

        private function database(): Database
        {
            $db = \Mockery::mock(Database::class);
            $db->shouldReceive('log')->andReturn(1);
            $db->shouldReceive('get_instance')->andReturnUsing(
                fn($id) => (int) $id === self::INSTANCE['id'] ? self::INSTANCE : null
            );
            return $db;
        }

        private function tracker(): HandoffTracker
        {
            $visitor = \Mockery::mock(VisitorTracker::class);
            $visitor->shouldReceive('get_visitor_id')->andReturn('v1');
            $visitor->shouldReceive('get_current_attribution')->andReturn([]);
            $touch = \Mockery::mock(TouchRecorder::class);
            $touch->shouldReceive('record_handoff')->andReturn(1);

            $tracker = (new \ReflectionClass(HandoffTracker::class))->newInstanceWithoutConstructor();
            foreach (['db' => $this->database(), 'visitor_tracker' => $visitor, 'touch_recorder' => $touch] as $prop => $val) {
                (new \ReflectionProperty(HandoffTracker::class, $prop))->setValue($tracker, $val);
            }
            return $tracker;
        }

        private function receiver(): CompletionReceiver
        {
            $r = (new \ReflectionClass(CompletionReceiver::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(CompletionReceiver::class, 'db'))->setValue($r, $this->database());
            (new \ReflectionProperty(CompletionReceiver::class, 'handoff_tracker'))->setValue($r, $this->tracker());
            return $r;
        }

        /** Hit the public return URL; returns where the visitor was sent. */
        private function hitReturnUrl(array $query): string
        {
            try {
                $this->receiver()->receive_redirect(new \WP_REST_Request($query));
            } catch (RedirectIssued $r) {
                return $r->url;
            }
            $this->fail('receive_redirect must always redirect the visitor.');
        }

        private function validSig(string $token = self::TOKEN): string
        {
            return hash_hmac('sha256', $token, CompletionSigner::secret_for(self::INSTANCE['id']));
        }

        // --------------------------------------------------------------
        // Forged completions
        // --------------------------------------------------------------

        public function test_unsigned_return_url_records_no_completion(): void
        {
            $to = $this->hitReturnUrl(['isf_ref' => self::TOKEN, 'status' => 'completed']);

            $this->assertSame([], $this->completions, 'An unsigned public GET must not record a completion.');
            $this->assertSame(0, $this->conversionHooks, 'An unsigned public GET must not fire the conversion hook.');
            $this->assertSame('redirected', $this->handoffs[self::TOKEN]['status']);
            $this->assertSame('https://site.example/thanks', $to, 'The visitor still lands on the thank-you page.');
        }

        public function test_forged_signature_records_no_completion(): void
        {
            $this->hitReturnUrl(['isf_ref' => self::TOKEN, 'status' => 'completed', 'isf_sig' => str_repeat('0', 64)]);
            $this->assertSame([], $this->completions);
        }

        public function test_signed_return_url_completes_exactly_once(): void
        {
            $query = ['isf_ref' => self::TOKEN, 'status' => 'completed', 'isf_sig' => $this->validSig(), 'confirmation' => 'C-1'];

            $this->hitReturnUrl($query);
            $this->hitReturnUrl($query);
            $this->hitReturnUrl($query);

            $this->assertCount(1, $this->completions, 'A handoff may complete once; replays must not add rows.');
            $this->assertSame(1, $this->conversionHooks, 'The conversion hook fires once per handoff.');
            $this->assertSame('completed', $this->handoffs[self::TOKEN]['status']);
            $this->assertSame('C-1', $this->completions[0]['external_id']);
        }

        public function test_query_string_account_and_email_are_ignored(): void
        {
            $this->handoffs[self::TOKEN]['account_number'] = '7777777777';

            $this->hitReturnUrl([
                'isf_ref'        => self::TOKEN,
                'status'         => 'completed',
                'isf_sig'        => $this->validSig(),
                'account_number' => '0000000001',
                'email'          => 'attacker@evil.example',
            ]);

            $this->assertCount(1, $this->completions);
            $this->assertSame('7777777777', $this->completions[0]['account_number'], 'account_number comes from the stored handoff.');
            $this->assertNull($this->completions[0]['customer_email'], 'email is never taken from the query string.');
            $this->assertStringNotContainsString('attacker@evil.example', (string) $this->completions[0]['raw_data']);
        }

        public function test_return_url_is_rate_limited(): void
        {
            Functions\when('get_transient')->justReturn(100000);

            $this->hitReturnUrl(['isf_ref' => self::TOKEN, 'status' => 'completed', 'isf_sig' => $this->validSig()]);
            $this->assertSame([], $this->completions, 'A rate-limited caller must not record completions.');
        }

        public function test_per_instance_secrets_are_distinct_and_stable(): void
        {
            $a = CompletionSigner::secret_for(3);
            $this->assertSame($a, CompletionSigner::secret_for(3));
            $this->assertNotSame($a, CompletionSigner::secret_for(4));
            $this->assertGreaterThanOrEqual(64, strlen($a));
            $this->assertTrue(CompletionSigner::verify(self::TOKEN, hash_hmac('sha256', self::TOKEN, $a), 3));
            $this->assertFalse(CompletionSigner::verify(self::TOKEN, hash_hmac('sha256', self::TOKEN, $a), 4));
        }

        // --------------------------------------------------------------
        // Open redirect
        // --------------------------------------------------------------

        public function test_destination_must_match_the_instance_configured_host(): void
        {
            $this->assertTrue(HandoffEndpoint::is_allowed_destination('https://enroll.partner.example/other?x=1', self::INSTANCE));
            $this->assertTrue(HandoffEndpoint::is_allowed_destination('https://ENROLL.partner.example/start', self::INSTANCE));
            $this->assertFalse(HandoffEndpoint::is_allowed_destination('https://evil.example/phish', self::INSTANCE));
            $this->assertFalse(HandoffEndpoint::is_allowed_destination('https://enroll.partner.example.evil.example/', self::INSTANCE));
            $this->assertFalse(HandoffEndpoint::is_allowed_destination('https://evil.example/?https://enroll.partner.example', self::INSTANCE));
            $this->assertFalse(HandoffEndpoint::is_allowed_destination('javascript:alert(1)', self::INSTANCE));
            $this->assertFalse(HandoffEndpoint::is_allowed_destination('https://enroll.partner.example/', ['settings' => []]),
                'An instance with no configured destination allows nothing.');
        }

        public function test_create_handoff_rejects_foreign_destination(): void
        {
            Functions\when('get_transient')->justReturn(false);
            $endpoint = (new \ReflectionClass(HandoffEndpoint::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(HandoffEndpoint::class, 'db'))->setValue($endpoint, $this->database());

            $result = $endpoint->create_handoff(new \WP_REST_Request([
                'instance_id'     => self::INSTANCE['id'],
                'destination_url' => 'https://evil.example/phish',
            ]));

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('invalid_destination', $result->get_error_code());
        }

        public function test_expired_handoff_does_not_redirect(): void
        {
            $this->handoffs[self::TOKEN]['status'] = 'expired';
            $this->assertNull($this->tracker()->process_redirect(self::TOKEN));

            $this->handoffs[self::TOKEN]['status'] = 'redirected';
            $this->handoffs[self::TOKEN]['created_at'] = date('Y-m-d H:i:s', time() - 8 * 86400);
            $this->assertNull($this->tracker()->process_redirect(self::TOKEN),
                'A handoff older than its TTL must not redirect even before the expiry cron has run.');
        }

        public function test_stored_foreign_destination_is_not_followed(): void
        {
            // A row written before this fix (or after an admin changed the URL).
            $this->handoffs[self::TOKEN]['destination_url'] = 'https://evil.example/phish';

            $this->assertFalse(
                HandoffEndpoint::is_allowed_destination($this->handoffs[self::TOKEN]['destination_url'], self::INSTANCE)
            );

            $src = file_get_contents(ISF_PLUGIN_DIR . 'includes/api/class-handoff-endpoint.php');
            $this->assertSame(2, substr_count($src, 'self::resolve_redirect_destination('),
                'Both redirect entry points (REST + ?isf_handoff) must resolve through the allowlist check.');
        }
    }
}

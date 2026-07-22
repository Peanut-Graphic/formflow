<?php
/**
 * Unit tests for the /embed/validate + /embed/schedule hardening helpers.
 *
 * These public endpoints were unauthenticated (token-only, no nonce) and
 * unthrottled, turning validate into a PII / account-enumeration oracle and an
 * unbounded live-API abuse surface. The handlers now rate-limit, require the
 * per-token nonce (parity with /embed/submit), and trim failed-probe responses.
 */

namespace {
    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            public function __construct(private $data = null, private int $status = 200) {}
            public function get_status(): int { return $this->status; }
            public function get_data() { return $this->data; }
        }
    }
    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            public array $headers = [];
            public function get_header($key) { return $this->headers[$key] ?? null; }
        }
    }
}

namespace ISF\Tests\Unit {

    use Brain\Monkey\Functions;
    use ISF\EmbedHandler;

    final class EmbedEndpointHardeningTest extends TestCase
    {
        private function handler(): EmbedHandler
        {
            // Constructor does `new Database()`; bypass it — we only exercise
            // pure guard helpers here.
            return (new \ReflectionClass(EmbedHandler::class))->newInstanceWithoutConstructor();
        }

        private function call(EmbedHandler $h, string $method, ...$args)
        {
            $m = new \ReflectionMethod($h, $method);
            $m->setAccessible(true);
            return $m->invoke($h, ...$args);
        }

        protected function setUp(): void
        {
            parent::setUp();
            Functions\when('apply_filters')->alias(fn ($hook, $value = null, ...$rest) => $value);
            $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        }

        public function test_rate_limiter_allows_under_the_cap(): void
        {
            Functions\when('get_transient')->justReturn(5);   // below default max 20
            Functions\when('set_transient')->justReturn(true);

            $this->assertNull(
                $this->call($this->handler(), 'embed_rate_limited', 'tok'),
                'Requests under the cap must be allowed through.'
            );
        }

        public function test_rate_limiter_blocks_at_the_cap(): void
        {
            Functions\when('get_transient')->justReturn(20);  // at default max 20
            Functions\when('set_transient')->justReturn(true);

            $resp = $this->call($this->handler(), 'embed_rate_limited', 'tok');

            $this->assertInstanceOf(\WP_REST_Response::class, $resp);
            $this->assertSame(429, $resp->get_status(), 'At the cap the endpoint must return HTTP 429.');
        }

        public function test_nonce_required(): void
        {
            Functions\when('wp_verify_nonce')->alias(fn ($nonce, $action) => $nonce === 'good' && $action === 'isf_embed_tok');

            $req = new \WP_REST_Request();
            $req->headers['X-WP-Nonce'] = 'good';
            $this->assertTrue($this->call($this->handler(), 'embed_nonce_valid', $req, 'tok'));

            $bad = new \WP_REST_Request();
            $bad->headers['X-WP-Nonce'] = 'wrong';
            $this->assertFalse($this->call($this->handler(), 'embed_nonce_valid', $bad, 'tok'));

            $missing = new \WP_REST_Request();
            $this->assertFalse(
                $this->call($this->handler(), 'embed_nonce_valid', $missing, 'tok'),
                'A missing nonce must fail.'
            );
        }

        public function test_failed_validation_response_is_trimmed(): void
        {
            $raw = [
                'is_valid'      => false,
                'error_code'    => 'account_not_found',
                'error_message' => 'RAW UPSTREAM: prospect 210010506231 not in region 42',
                'customer_data' => ['first_name' => 'Jane', 'address' => '123 Main St'],
            ];

            $out = $this->call($this->handler(), 'minimize_validation_response', $raw);

            $this->assertFalse($out['is_valid']);
            $this->assertArrayNotHasKey('customer_data', $out, 'A failed probe must not carry customer PII.');
            $this->assertStringNotContainsString('RAW UPSTREAM', $out['error_message'], 'Raw upstream text must not leak.');
            $this->assertStringNotContainsString('Main St', json_encode($out));
        }

        public function test_successful_validation_response_is_preserved(): void
        {
            $raw = [
                'is_valid'      => true,
                'error_code'    => '',
                'error_message' => '',
                'customer_data' => ['first_name' => 'Jane', 'enrollable_premises' => [['id' => 1]]],
            ];

            $out = $this->call($this->handler(), 'minimize_validation_response', $raw);

            $this->assertTrue($out['is_valid']);
            $this->assertArrayHasKey('customer_data', $out, 'On success the confirm-flow data must be preserved.');
            $this->assertSame($raw, $out);
        }

        public function test_wildcard_cors_reflects_origin_without_credentials(): void
        {
            $headers = $this->call($this->handler(), 'cors_headers_for', 'https://evil.example', ['*']);
            $joined = implode("\n", $headers);

            $this->assertStringContainsString('Access-Control-Allow-Origin: https://evil.example', $joined);
            $this->assertStringNotContainsString(
                'Access-Control-Allow-Credentials',
                $joined,
                'A wildcard (public) embed must NOT pair Allow-Credentials with a reflected-any origin.'
            );
            $this->assertStringContainsString('X-WP-Nonce', $joined, 'The nonce header must stay advertised.');
            $this->assertStringContainsString('Vary: Origin', $joined);
        }

        public function test_explicit_allowlist_reflects_match_with_credentials(): void
        {
            $allowed = ['https://client.example', 'https://portal.example'];
            $headers = $this->call($this->handler(), 'cors_headers_for', 'https://client.example', $allowed);
            $joined = implode("\n", $headers);

            $this->assertStringContainsString('Access-Control-Allow-Origin: https://client.example', $joined);
            $this->assertStringContainsString(
                'Access-Control-Allow-Credentials: true',
                $joined,
                'An exact allowlist match may carry credentials.'
            );
        }

        public function test_unlisted_origin_gets_no_cors_headers(): void
        {
            $headers = $this->call($this->handler(), 'cors_headers_for', 'https://evil.example', ['https://client.example']);
            $this->assertSame([], $headers, 'An origin not on an explicit allowlist gets no CORS headers.');
        }
    }
}

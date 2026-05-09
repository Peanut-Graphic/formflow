<?php

namespace ISF\Tests\Unit;

use ISF\TesterBridge\CanonicalRequest;
use ISF\TesterBridge\HmacRequestVerifier;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/tester-bridge/class-hmac-verifier.php';

/**
 * Verifies the vendored HMAC core matches the upstream contract used by
 * the TESTER worker (canonical-request shape, header names, reason strings).
 *
 * Source-of-truth for the canonical-request shape lives in
 * BLOXY/packages/tester-bridge-php/tests; if upstream changes, mirror here.
 */
class TesterBridgeHmacVerifierTest extends TestCase
{
    public function test_canonical_request_joins_six_lines_in_order(): void
    {
        $canonical = CanonicalRequest::build('post', '/wp-json/formflow/v1/tester/health', '{}', '1700000000', 'nonce', 'tok');

        $lines = explode("\n", $canonical);
        $this->assertCount(6, $lines);
        $this->assertSame('POST', $lines[0]);
        $this->assertSame('/wp-json/formflow/v1/tester/health', $lines[1]);
        $this->assertSame(hash('sha256', '{}'), $lines[2]);
        $this->assertSame('1700000000', $lines[3]);
        $this->assertSame('nonce', $lines[4]);
        $this->assertSame('tok', $lines[5]);
    }

    public function test_verify_accepts_a_correctly_signed_request(): void
    {
        $secret = str_repeat('a', 64);
        $now = 1700000000;
        $iat = $now - 10;
        $exp = $now + 60;
        $token = $this->encodeToken(['iat' => $iat, 'exp' => $exp]);
        $body = '{}';
        $ts = (string) $now;
        $nonce = 'unique-nonce';
        $canonical = CanonicalRequest::build('GET', '/wp-json/formflow/v1/tester/health', $body, $ts, $nonce, $token);
        $sig = hash_hmac('sha256', $canonical, $secret);

        $verifier = new HmacRequestVerifier($secret, fn () => $now);
        $result = $verifier->verify('GET', '/wp-json/formflow/v1/tester/health', $body, [
            'X-Tester-Token' => $token,
            'X-Tester-Signature' => $sig,
            'X-Tester-Timestamp' => $ts,
            'X-Tester-Nonce' => $nonce,
        ]);

        $this->assertTrue($result->ok);
        $this->assertNull($result->reason);
        $this->assertSame(['iat' => $iat, 'exp' => $exp], $result->claims);
    }

    public function test_missing_headers_yields_missing_headers_reason(): void
    {
        $verifier = new HmacRequestVerifier('secret');
        $result = $verifier->verify('GET', '/x', '', []);

        $this->assertFalse($result->ok);
        $this->assertSame('missing_headers', $result->reason);
    }

    public function test_expired_token_is_rejected_with_expired_reason(): void
    {
        $secret = 'secret';
        $token = $this->encodeToken(['iat' => 1, 'exp' => 2]);
        $verifier = new HmacRequestVerifier($secret, fn () => 1_000_000);

        $result = $verifier->verify('GET', '/x', '', [
            'X-Tester-Token' => $token,
            'X-Tester-Signature' => 'irrelevant',
            'X-Tester-Timestamp' => '1',
            'X-Tester-Nonce' => 'n',
        ]);

        $this->assertFalse($result->ok);
        $this->assertSame('expired', $result->reason);
    }

    public function test_signature_mismatch_yields_signature_mismatch_reason(): void
    {
        $secret = 'secret';
        $now = 1700000000;
        $token = $this->encodeToken(['iat' => $now - 1, 'exp' => $now + 60]);
        $verifier = new HmacRequestVerifier($secret, fn () => $now);

        $result = $verifier->verify('GET', '/x', '', [
            'X-Tester-Token' => $token,
            'X-Tester-Signature' => 'deadbeef',
            'X-Tester-Timestamp' => (string) $now,
            'X-Tester-Nonce' => 'n',
        ]);

        $this->assertFalse($result->ok);
        $this->assertSame('signature_mismatch', $result->reason);
    }

    private function encodeToken(array $claims): string
    {
        return rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    }
}

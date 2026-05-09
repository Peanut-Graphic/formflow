<?php

namespace ISF\TesterBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Vendored HMAC verifier — byte-for-byte equivalent to
 * peanutgraphic/bloxy-tester-bridge's `Auth\HmacRequestVerifier`. Vendored
 * (not composer-required) because the upstream package brings illuminate/*
 * runtime deps that don't belong in a WordPress plugin.
 *
 * Source of truth: BLOXY/packages/tester-bridge-php/src/Auth/.
 * If the upstream changes the canonical request shape or token decode rules,
 * mirror the change here.
 */

class CanonicalRequest
{
    public static function build(
        string $method,
        string $path,
        string $body,
        string $timestamp,
        string $nonce,
        string $tokenB64,
    ): string {
        $bodyHash = hash('sha256', $body);

        return implode("\n", [
            strtoupper($method),
            $path,
            $bodyHash,
            $timestamp,
            $nonce,
            $tokenB64,
        ]);
    }
}

class VerifyResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $reason = null,
        public readonly array $claims = [],
    ) {
    }
}

class HmacRequestVerifier
{
    /** @var \Closure */
    private $clock;

    public function __construct(
        private readonly string $sharedSecret,
        ?\Closure $clock = null,
        private readonly int $clockSkewSeconds = 60,
    ) {
        $this->clock = $clock ?? fn () => time();
    }

    public function verify(string $method, string $path, string $body, array $headers): VerifyResult
    {
        $token = $headers['X-Tester-Token'] ?? null;
        $sig = $headers['X-Tester-Signature'] ?? null;
        $ts = $headers['X-Tester-Timestamp'] ?? null;
        $nonce = $headers['X-Tester-Nonce'] ?? null;

        if (! $token || ! $sig || ! $ts || ! $nonce) {
            return new VerifyResult(false, 'missing_headers');
        }

        $claims = $this->decodeToken($token);
        if ($claims === null || ! isset($claims['exp'], $claims['iat'])) {
            return new VerifyResult(false, 'malformed_token');
        }

        $now = ($this->clock)();
        if ($now > $claims['exp']) {
            return new VerifyResult(false, 'expired');
        }
        if ($now < ($claims['iat'] - $this->clockSkewSeconds)) {
            return new VerifyResult(false, 'expired');
        }

        $canonical = CanonicalRequest::build($method, $path, $body, $ts, $nonce, $token);
        $expected = hash_hmac('sha256', $canonical, $this->sharedSecret);

        if (! hash_equals($expected, $sig)) {
            return new VerifyResult(false, 'signature_mismatch');
        }

        return new VerifyResult(true, null, $claims);
    }

    private function decodeToken(string $b64): ?array
    {
        $padded = $b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $json = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}

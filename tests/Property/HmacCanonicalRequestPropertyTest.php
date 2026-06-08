<?php
/**
 * Net 6 (property) — tester-bridge HMAC canonical-request builder.
 *
 * Targets the PURE static function that the TESTER worker and the bridge both
 * use to construct the string that gets HMAC-signed:
 *   ISF\TesterBridge\CanonicalRequest::build($method,$path,$body,$ts,$nonce,$tok)
 *
 * The canonical string is the heart of request authentication: if the builder
 * is non-deterministic, or drops/reorders a field, or fails to bind the body,
 * signatures stop matching (auth breaks) OR — worse — two materially different
 * requests could produce the same canonical string (a forgery seam). These
 * properties pin both halves: stable+complete shape, and body-binding.
 *
 * Seam: build() is a pure static — no WP, no I/O, no globals, no clock. The
 * verifier class file is required directly.
 *
 * Determinism: seeded generator (fixed seed); no wall-clock, network, or order
 * dependence.
 */

namespace ISF\Tests\Property;

use PHPUnit\Framework\TestCase;
use ISF\TesterBridge\CanonicalRequest;

require_once ISF_PLUGIN_DIR . 'includes/tester-bridge/class-hmac-verifier.php';

final class HmacCanonicalRequestPropertyTest extends TestCase
{
    /**
     * Generate a deterministic batch of request tuples.
     *
     * @return array<int, array{0:string,1:string,2:string,3:string,4:string,5:string}>
     */
    private function requestCorpus(): array
    {
        mt_srand(1337);
        $methods = ['get', 'post', 'put', 'delete', 'patch', 'GET', 'PoSt'];
        $rows = [];
        for ($i = 0; $i < 250; $i++) {
            $rows[] = [
                $methods[mt_rand(0, count($methods) - 1)],
                '/wp-json/formflow/v1/' . $this->randToken(mt_rand(0, 20)),
                $this->randToken(mt_rand(0, 64)),               // body
                (string) mt_rand(1_600_000_000, 1_800_000_000), // timestamp
                $this->randToken(16),                           // nonce
                $this->randToken(24),                           // token b64
            ];
        }
        return $rows;
    }

    private function randToken(int $len): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789{}":,[]/ ';
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }
        return $s;
    }

    /**
     * INVARIANT (shape): the canonical request is ALWAYS exactly six
     * newline-joined lines, in the fixed order
     *   METHOD, path, sha256(body), timestamp, nonce, token.
     */
    public function test_canonical_request_always_has_six_ordered_lines(): void
    {
        foreach ($this->requestCorpus() as [$m, $p, $b, $ts, $nonce, $tok]) {
            $canonical = CanonicalRequest::build($m, $p, $b, $ts, $nonce, $tok);
            $lines = explode("\n", $canonical);

            $this->assertCount(6, $lines, "canonical for {$p} did not have 6 lines");
            $this->assertSame(strtoupper($m), $lines[0]);
            $this->assertSame($p, $lines[1]);
            $this->assertSame(hash('sha256', $b), $lines[2]);
            $this->assertSame($ts, $lines[3]);
            $this->assertSame($nonce, $lines[4]);
            $this->assertSame($tok, $lines[5]);
        }
    }

    /**
     * INVARIANT (method case-folding): the HTTP method is normalized to
     * uppercase, so 'post' and 'POST' produce byte-identical canonical
     * strings (the verifier on the other side does the same).
     */
    public function test_method_is_case_folded(): void
    {
        foreach ($this->requestCorpus() as [$m, $p, $b, $ts, $nonce, $tok]) {
            $lower = CanonicalRequest::build(strtolower($m), $p, $b, $ts, $nonce, $tok);
            $upper = CanonicalRequest::build(strtoupper($m), $p, $b, $ts, $nonce, $tok);
            $this->assertSame($lower, $upper, "method case-folding broke for {$m}");
        }
    }

    /**
     * INVARIANT (body binding): the body is bound via its sha256. Changing
     * ONLY the body must change the canonical string. If it didn't, a signed
     * request could be replayed with a swapped body — a forgery seam.
     */
    public function test_body_is_bound_into_canonical_request(): void
    {
        foreach ($this->requestCorpus() as [$m, $p, $b, $ts, $nonce, $tok]) {
            $mutated = $b . 'X'; // any change
            $a = CanonicalRequest::build($m, $p, $b, $ts, $nonce, $tok);
            $z = CanonicalRequest::build($m, $p, $mutated, $ts, $nonce, $tok);
            $this->assertNotSame(
                $a,
                $z,
                "body change did not alter canonical request (body not bound) for path {$p}"
            );
            // And specifically: only the body-hash line differs.
            $la = explode("\n", $a);
            $lz = explode("\n", $z);
            $this->assertNotSame($la[2], $lz[2], 'body-hash line should differ');
            unset($la[2], $lz[2]);
            $this->assertSame($la, $lz, 'changing the body altered a non-body line');
        }
    }

    /**
     * INVARIANT (determinism): build() is pure — identical inputs yield a
     * byte-identical canonical request every call.
     */
    public function test_build_is_deterministic(): void
    {
        foreach ($this->requestCorpus() as [$m, $p, $b, $ts, $nonce, $tok]) {
            $first  = CanonicalRequest::build($m, $p, $b, $ts, $nonce, $tok);
            $second = CanonicalRequest::build($m, $p, $b, $ts, $nonce, $tok);
            $this->assertSame($first, $second);
        }
    }
}

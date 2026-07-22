<?php
/**
 * Regression guard: the public embed read-endpoints must stay gated.
 *
 * /embed/validate and /embed/schedule were token-only (the token is public) and
 * unthrottled — a PII/enumeration oracle over the live IntelliSource API. They
 * now rate-limit AND require the per-token nonce, and CORS must advertise the
 * nonce header or the browser preflight strips it. This pins that wiring so a
 * later refactor can't silently drop a guard; the helper behaviour itself is
 * covered by tests/Unit/EmbedEndpointHardeningTest.
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class EmbedEndpointGuardsTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/includes/class-embed-handler.php');
    }

    /**
     * Isolate a single handler method body by brace matching from its signature.
     */
    private function methodBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        $this->assertNotFalse($start, "method {$signature} must exist");

        $brace = strpos($src, '{', $start);
        $depth = 0;
        for ($i = $brace, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') { $depth++; }
            elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { return substr($src, $brace, $i - $brace + 1); } }
        }
        $this->fail("could not brace-match {$signature}");
    }

    public function test_validation_handler_rate_limits_and_requires_nonce(): void
    {
        $body = $this->methodBody($this->source(), 'function handle_embed_validation(');
        $this->assertStringContainsString('embed_rate_limited(', $body, 'validate must be rate-limited');
        $this->assertStringContainsString('embed_nonce_valid(', $body, 'validate must require the nonce');
        $this->assertStringContainsString('minimize_validation_response(', $body, 'validate response must be minimized');
    }

    public function test_schedule_handler_rate_limits_and_requires_nonce(): void
    {
        $body = $this->methodBody($this->source(), 'function handle_embed_schedule(');
        $this->assertStringContainsString('embed_rate_limited(', $body, 'schedule must be rate-limited');
        $this->assertStringContainsString('embed_nonce_valid(', $body, 'schedule must require the nonce');
    }

    public function test_cors_allows_the_nonce_header(): void
    {
        $body = $this->methodBody($this->source(), 'function cors_headers_for(');
        $this->assertMatchesRegularExpression(
            '/Access-Control-Allow-Headers:[^\']*X-WP-Nonce/i',
            $body,
            'CORS must advertise X-WP-Nonce so the nonce survives the cross-origin preflight.'
        );
    }

    public function test_cors_does_not_pair_credentials_with_wildcard(): void
    {
        $body = $this->methodBody($this->source(), 'function cors_headers_for(');

        // Allow-Credentials must appear only in the explicit-allowlist (else)
        // branch, i.e. after the wildcard Allow-Origin line — never in the
        // wildcard branch.
        $wildcardPos = strpos($body, "Access-Control-Allow-Origin: ' . (\$origin ?: '*')");
        $credsPos    = strpos($body, 'Access-Control-Allow-Credentials: true');

        $this->assertNotFalse($wildcardPos, 'the wildcard origin branch must exist');
        $this->assertNotFalse($credsPos, 'credentials must still be sent for explicit allowlists');
        $this->assertGreaterThan(
            $wildcardPos,
            $credsPos,
            'Allow-Credentials must live in the explicit-allowlist branch, not the wildcard one.'
        );
    }
}

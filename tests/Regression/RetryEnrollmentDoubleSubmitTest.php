<?php
/**
 * Regression guard: never auto-retry an enrollment whose outcome is ambiguous.
 *
 * The IntelliSource enroll POST is NOT idempotent. When RetryProcessor retried
 * a failed enrollment, it re-sent the POST on ANY failure — including a read
 * timeout, a connection dropped after the request was sent, or a 2xx response
 * body it couldn't parse. In every one of those cases the request may already
 * have reached IntelliSource and enrolled the customer, so the retry creates a
 * DUPLICATE enrollment — the exact corruption the utility cannot tolerate.
 *
 * The fix classifies the failure before retrying: retry is permitted ONLY when
 * we can prove the request never left the box (DNS failure, refused/failed TCP
 * connection). Every ambiguous outcome is parked for manual review instead.
 *
 * This exercises the real private classifier via reflection. Self-contained:
 * no WordPress, database, or network.
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use ISF\RetryProcessor;

final class RetryEnrollmentDoubleSubmitTest extends TestCase
{
    private function isTransmissionSafe(int $status, string $message): bool
    {
        // RetryProcessor's constructor does `new Database()`, which we cannot
        // build without WordPress. Construct without the constructor and invoke
        // the pure classifier directly.
        $processor = (new \ReflectionClass(RetryProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($processor, 'enroll_failure_is_transmission_safe');
        $method->setAccessible(true);

        return $method->invoke($processor, $status, $message);
    }

    /**
     * @dataProvider ambiguousFailures
     */
    public function test_ambiguous_failures_are_never_auto_retried(int $status, string $message): void
    {
        $this->assertFalse(
            $this->isTransmissionSafe($status, $message),
            "A '{$message}' (status {$status}) failure may have reached IntelliSource and MUST NOT be auto-retried."
        );
    }

    public static function ambiguousFailures(): array
    {
        return [
            'read timeout' => [0, 'cURL error 28: Operation timed out after 30001 milliseconds'],
            'plain timeout text' => [0, 'Operation timed out'],
            'max retries exhausted' => [0, 'Max retries exceeded'],
            'connection reset after send' => [0, 'cURL error 56: Connection reset by peer'],
            'unparseable 2xx body' => [0, 'Failed to parse API response: malformed XML'],
            'server rejected 400' => [400, 'HTTP 400: bad request'],
            'conflict already enrolled 409' => [409, 'HTTP 409: already enrolled'],
            'server error 500' => [500, 'HTTP 500: internal error'],
            'server error 503' => [503, 'HTTP 503: service unavailable'],
            'unknown transport error' => [0, 'cURL error 35: SSL connect error'],
        ];
    }

    /**
     * @dataProvider preTransmissionFailures
     */
    public function test_pre_transmission_failures_are_safe_to_retry(int $status, string $message): void
    {
        $this->assertTrue(
            $this->isTransmissionSafe($status, $message),
            "A '{$message}' failure provably never reached IntelliSource and is safe to retry."
        );
    }

    public static function preTransmissionFailures(): array
    {
        return [
            'dns resolve failure' => [0, 'cURL error 6: Could not resolve host: ph.powerportal.com'],
            'dns name or service' => [0, 'php_network_getaddresses: Name or service not known'],
            'connection refused' => [0, 'cURL error 7: Failed to connect to host port 443: Connection refused'],
            'could not connect' => [0, "cURL error 7: couldn't connect to server"],
        ];
    }
}

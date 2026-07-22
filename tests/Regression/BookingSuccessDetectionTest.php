<?php
/**
 * Regression guard: a booking response must not be misread as success.
 *
 * RetryProcessor::retry_booking() detected success in a plain-string response
 * with `stripos($r,'success') || stripos($r,'confirmed')`. That substring
 * match fires inside "unsuccessful", so "Booking unsuccessful" was recorded as
 * a SUCCESS — the failed booking was marked completed, enrollment.completed
 * fired, and the appointment was silently lost. The fix rejects any response
 * carrying a negative marker before accepting a positive token.
 *
 * Exercises the real private classifier via reflection. Self-contained.
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use ISF\RetryProcessor;

final class BookingSuccessDetectionTest extends TestCase
{
    private function readsAsSuccess(string $response): bool
    {
        $processor = (new \ReflectionClass(RetryProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($processor, 'booking_response_reads_as_success');
        $method->setAccessible(true);

        return $method->invoke($processor, $response);
    }

    /**
     * @dataProvider notSuccess
     */
    public function test_negative_responses_are_not_success(string $response): void
    {
        $this->assertFalse(
            $this->readsAsSuccess($response),
            "'{$response}' must NOT be treated as a successful booking."
        );
    }

    public static function notSuccess(): array
    {
        return [
            'unsuccessful' => ['Booking unsuccessful'],
            'was not successful' => ['The request was not successful'],
            'unable to book' => ['Unable to confirm appointment'],
            'failed' => ['Scheduling failed'],
            'error' => ['Error: slot no longer available'],
            'denied' => ['Request denied'],
            'invalid' => ['Invalid FSR number'],
            'rejected' => ['Appointment rejected'],
            'empty' => [''],
            'noise' => ['pending review'],
        ];
    }

    /**
     * @dataProvider isSuccess
     */
    public function test_positive_responses_are_success(string $response): void
    {
        $this->assertTrue(
            $this->readsAsSuccess($response),
            "'{$response}' should be treated as a successful booking."
        );
    }

    public static function isSuccess(): array
    {
        return [
            'success' => ['Booking success'],
            'successful' => ['Appointment successful'],
            'confirmed' => ['Appointment confirmed for 05/01'],
            'mixed case' => ['SUCCESS - reference 12345'],
        ];
    }
}

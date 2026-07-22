<?php
/**
 * Regression guard: a successful retry sends the customer their confirmation.
 *
 * The normal AJAX success handler emails the customer, but the retry path
 * bypassed it — so an enrollment that only succeeded on a retry left the
 * customer with no confirmation despite the form promising "check your email".
 * customer_confirmation_args() is the pure decision behind the new send: it
 * returns the email args for an enrollment retry, and null when a send would be
 * wrong (a booking, confirmations disabled, or no email on file).
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use ISF\RetryProcessor;

final class RetryCustomerNotifyTest extends TestCase
{
    private function args(array $submission, array $instance, array $result)
    {
        $p = (new \ReflectionClass(RetryProcessor::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($p, 'customer_confirmation_args');
        $m->setAccessible(true);
        return $m->invoke($p, $submission, $instance, $result);
    }

    public function test_enrollment_retry_sends_confirmation_with_number(): void
    {
        $args = $this->args(
            ['form_data' => ['email' => 'a@example.com', 'first_name' => 'Jane']],
            ['settings' => []],
            ['success' => true, 'response' => ['confirmation_number' => 'PTR-12345']]
        );

        $this->assertIsArray($args);
        $this->assertSame('a@example.com', $args['form_data']['email']);
        $this->assertSame('PTR-12345', $args['confirmation']);
    }

    public function test_booking_retry_does_not_send_enrollment_confirmation(): void
    {
        $args = $this->args(
            ['form_data' => ['email' => 'a@example.com', 'schedule_date' => '2026-08-01', 'schedule_time' => 'AM']],
            ['settings' => []],
            ['success' => true, 'response' => ['confirmation' => 'BOOK-1']]
        );

        $this->assertNull($args, 'A booking is not an enrollment confirmation.');
    }

    public function test_respects_disabled_confirmation_setting(): void
    {
        $args = $this->args(
            ['form_data' => ['email' => 'a@example.com']],
            ['settings' => ['send_confirmation_email' => false]],
            ['success' => true, 'response' => ['confirmation_number' => 'X']]
        );

        $this->assertNull($args, 'The instance opted out of confirmation emails.');
    }

    public function test_no_email_means_no_send(): void
    {
        $args = $this->args(
            ['form_data' => ['first_name' => 'Jane']],
            ['settings' => []],
            ['success' => true, 'response' => ['confirmation_number' => 'X']]
        );

        $this->assertNull($args);
    }

    public function test_missing_confirmation_number_still_sends_with_empty_string(): void
    {
        $args = $this->args(
            ['form_data' => ['email' => 'a@example.com']],
            ['settings' => []],
            ['success' => true, 'response' => []]
        );

        $this->assertIsArray($args);
        $this->assertSame('', $args['confirmation']);
    }
}

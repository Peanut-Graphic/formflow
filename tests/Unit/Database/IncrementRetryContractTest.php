<?php
/**
 * Guards Database::increment_retry()'s documented return contract.
 *
 * "@return bool Success (false if max retries exceeded)". RetryProcessor relies
 * on this exactly: `$maxed = !increment_retry(...)`. When the max was reached
 * the method used to `return update_retry_status(...)`, which is TRUE on a
 * successful write — so the signal was inverted and a permanent failure was
 * treated as "still retrying". The permanent-failure branch and the
 * enrollment.failed webhook therefore never fired.
 *
 * These tests pin the contract: FALSE when the retry ceiling is hit, TRUE
 * while retries remain.
 */

namespace ISF\Tests\Unit\Database;

use ISF\Tests\Unit\TestCase;
use ISF\Database\Database;

final class IncrementRetryContractTest extends TestCase
{
    public function test_returns_false_when_max_retries_reached(): void
    {
        // One more attempt takes retry_count (2) to max_retries (3).
        $this->mockWpdb([
            'get_row' => ['id' => 7, 'retry_count' => 2, 'max_retries' => 3],
            // update() succeeds (truthy) — the pre-fix bug returned THIS.
            'update' => 1,
        ]);

        $db = new Database();

        $this->assertFalse(
            $db->increment_retry(7, 'giving up'),
            'increment_retry() must return FALSE once the retry ceiling is reached, so the caller detects a permanent failure.'
        );
    }

    public function test_returns_true_while_retries_remain(): void
    {
        $this->mockWpdb([
            'get_row' => ['id' => 7, 'retry_count' => 0, 'max_retries' => 3],
            'update' => 1,
        ]);

        $db = new Database();

        $this->assertTrue(
            $db->increment_retry(7, 'transient'),
            'increment_retry() must return TRUE while retries remain so the item is re-queued.'
        );
    }

    public function test_returns_false_when_queue_item_missing(): void
    {
        $this->mockWpdb([
            'get_row' => null,
        ]);

        $db = new Database();

        $this->assertFalse(
            $db->increment_retry(999, 'gone'),
            'A missing queue item cannot be retried and must report a terminal (false) result.'
        );
    }
}

<?php
/**
 * Guards the retry-processor concurrency + stuck-row safety primitives.
 *
 * - acquire_retry_lock()/release_retry_lock() serialize retry runs via a MySQL
 *   advisory lock so overlapping passes can't both send the non-idempotent
 *   enroll POST (a duplicate-enrollment vector).
 * - reclaim_stuck_retries() moves rows abandoned in 'processing' to the
 *   terminal 'failed' status for manual review — never silently lost, never
 *   blindly re-enrolled.
 */

namespace ISF\Tests\Unit\Database;

use ISF\Tests\Unit\TestCase;
use ISF\Database\Database;

final class RetryConcurrencySafetyTest extends TestCase
{
    /**
     * Capturing prepare() closure for the mockWpdb() methods array.
     */
    private function capturingPrepare(&$captured): callable
    {
        return function ($q, ...$a) use (&$captured) {
            $captured = vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $q), $a);
            return $captured;
        };
    }

    private function db(\Mockery\MockInterface $wpdb, string $dbname = 'wp'): Database
    {
        // dbname is a wpdb PROPERTY, not a method.
        $wpdb->dbname = $dbname;
        return new Database();
    }

    public function test_acquire_lock_true_when_granted(): void
    {
        $wpdb = $this->mockWpdb(['get_var' => '1']);
        $this->assertTrue($this->db($wpdb)->acquire_retry_lock());
    }

    public function test_acquire_lock_false_when_held_by_another_run(): void
    {
        $wpdb = $this->mockWpdb(['get_var' => '0']);
        $this->assertFalse(
            $this->db($wpdb)->acquire_retry_lock(),
            'A contended lock (GET_LOCK returns 0) must report failure so the second run skips.'
        );
    }

    public function test_acquire_lock_false_on_error(): void
    {
        $wpdb = $this->mockWpdb(['get_var' => null]);
        $this->assertFalse(
            $this->db($wpdb)->acquire_retry_lock(),
            'A NULL result (lock error) must fail closed.'
        );
    }

    public function test_lock_uses_get_lock_scoped_by_db_and_prefix(): void
    {
        $captured = null;
        $wpdb = $this->mockWpdb([
            'get_var' => '1',
            'prepare' => $this->capturingPrepare($captured),
        ]);

        $this->db($wpdb, 'utilitydb')->acquire_retry_lock();

        $this->assertNotNull($captured, 'prepare() was never called');
        $this->assertStringContainsString('GET_LOCK', $captured);
        $this->assertStringContainsString('utilitydb_wp_isf_retry', $captured, 'Lock name must be scoped by database + prefix.');
    }

    public function test_reclaim_marks_stuck_processing_rows_failed(): void
    {
        $captured = null;
        $wpdb = $this->mockWpdb([
            'query' => 2,
            'prepare' => $this->capturingPrepare($captured),
        ]);

        $reclaimed = $this->db($wpdb)->reclaim_stuck_retries(15);

        $this->assertSame(2, $reclaimed, 'Returns the number of rows reclaimed.');
        $this->assertNotNull($captured, 'prepare() was never called');
        $this->assertMatchesRegularExpression('/UPDATE.*isf_retry_queue/is', $captured);
        $this->assertMatchesRegularExpression("/SET\s+status\s*=\s*'failed'/is", $captured, 'Stuck rows go to terminal failed, not back to pending.');
        $this->assertMatchesRegularExpression("/WHERE\s+status\s*=\s*'processing'/is", $captured);
        $this->assertMatchesRegularExpression('/INTERVAL\s+15\s+MINUTE/i', $captured, 'Only rows past the staleness floor are reclaimed.');
    }

    public function test_reclaim_floor_is_at_least_one_minute(): void
    {
        $captured = null;
        $wpdb = $this->mockWpdb([
            'query' => 0,
            'prepare' => $this->capturingPrepare($captured),
        ]);

        $this->db($wpdb)->reclaim_stuck_retries(0);

        $this->assertNotNull($captured, 'prepare() was never called');
        $this->assertMatchesRegularExpression('/INTERVAL\s+1\s+MINUTE/i', $captured, 'A zero/negative floor is clamped to 1 minute so live rows are never reclaimed.');
    }
}

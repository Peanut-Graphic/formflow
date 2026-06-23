<?php
/**
 * VisitorTrackerThrottleTest — guards the per-pageview write throttle and the
 * missing-table guard added in 4.0.6.
 *
 * VisitorTracker is hooked on init@5, so update_visitor_seen() ran an UPDATE on
 * EVERY front-end pageview for a returning visitor — needless write load. It
 * also wrote blindly, so a missing/drifted visitors table became per-request
 * "Table doesn't exist" error spam. The fix:
 *   - throttle the "seen" write to once per visitor per window via a transient
 *   - short-circuit every write when the visitors table is absent
 */

namespace ISF\Tests\Unit\Analytics;

use ISF\Tests\Unit\TestCase;
use ISF\Analytics\VisitorTracker;
use Brain\Monkey\Functions;

final class VisitorTrackerThrottleTest extends TestCase
{
    private const VISITOR_ID = 'abc123def456789012345678901234ab';

    protected function setUp(): void
    {
        parent::setUp();
        $_COOKIE = [];
        $_GET = [];
        $_SERVER = [
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/',
        ];
    }

    public function test_seen_write_is_skipped_when_throttled(): void
    {
        // Table exists, but the throttle transient is already set.
        Functions\when('get_transient')->alias(function ($key) {
            // visitors_table_ready cache miss; throttle gate HIT.
            return strpos($key, 'isf_vseen_') === 0;
        });

        $wpdb = $this->mockWpdb([
            'get_var' => 'wp_' . ISF_TABLE_VISITORS,
        ]);
        // The UPDATE must NOT run when throttled.
        $wpdb->shouldReceive('query')->never();

        $this->invokeSeen();
        $this->assertTrue(true); // Mockery 'never()' is the real assertion.
    }

    public function test_seen_write_runs_when_not_throttled(): void
    {
        Functions\when('get_transient')->justReturn(false); // every gate misses
        $wpdb = $this->mockWpdb([
            'get_var' => 'wp_' . ISF_TABLE_VISITORS,
        ]);
        $wpdb->shouldReceive('query')->once()->andReturn(1);

        $this->invokeSeen();
        $this->assertTrue(true);
    }

    public function test_seen_write_is_skipped_when_table_missing(): void
    {
        Functions\when('get_transient')->justReturn(false);
        // get_var returns null => SHOW TABLES LIKE found nothing.
        $wpdb = $this->mockWpdb([
            'get_var' => null,
        ]);
        $wpdb->shouldReceive('query')->never();

        $this->invokeSeen();
        $this->assertTrue(true);
    }

    private function invokeSeen(): void
    {
        $tracker = new VisitorTracker();
        $ref = new \ReflectionMethod($tracker, 'update_visitor_seen');
        $ref->invoke($tracker, self::VISITOR_ID);
    }
}

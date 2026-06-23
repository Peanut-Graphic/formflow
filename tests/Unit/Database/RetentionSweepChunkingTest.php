<?php
/**
 * RetentionSweepChunkingTest — guards the daily retention sweep against the
 * unbounded-SELECT OOM/timeout risk.
 *
 * apply_retention_policy() (daily cron) used to call get_old_submissions(),
 * which did `SELECT ... WHERE created_at < ...` with NO LIMIT and then a
 * per-row foreach. On a large table that pulls every matching row into memory
 * at once — an OOM and PHP-timeout risk inside cron. The fix bounds each pass
 * with a LIMIT and loops until the query drains.
 *
 * Two complementary checks:
 *   - behavioral: get_old_submissions($days, $limit) actually emits a LIMIT
 *     clause when a positive limit is given (and none when it isn't).
 *   - structural: apply_retention_policy iterates get_old_submissions in a
 *     bounded loop rather than fetching everything once.
 */

namespace ISF\Tests\Unit\Database;

use ISF\Tests\Unit\TestCase;
use ISF\Database\Database;

final class RetentionSweepChunkingTest extends TestCase
{
    public function test_get_old_submissions_emits_limit_when_limit_given(): void
    {
        $captured = null;
        $this->mockWpdb([
            'get_results' => [],
            'prepare' => function ($query, ...$args) use (&$captured) {
                $captured = vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
                return $captured;
            },
        ]);

        $db = new Database();
        $db->get_old_submissions(30, 500);

        $this->assertNotNull($captured, 'prepare() was never called');
        $this->assertMatchesRegularExpression(
            '/LIMIT\s+500/i',
            $captured,
            'get_old_submissions($days, 500) must bound the query with LIMIT 500'
        );
    }

    public function test_get_old_submissions_has_no_limit_by_default(): void
    {
        $captured = null;
        $this->mockWpdb([
            'get_results' => [],
            'prepare' => function ($query, ...$args) use (&$captured) {
                $captured = vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
                return $captured;
            },
        ]);

        $db = new Database();
        $db->get_old_submissions(30);

        $this->assertNotNull($captured);
        $this->assertDoesNotMatchRegularExpression(
            '/LIMIT/i',
            $captured,
            'get_old_submissions($days) with no limit should not emit a LIMIT clause'
        );
    }

    public function test_apply_retention_policy_loops_in_bounded_chunks(): void
    {
        // Structural guard: the submissions branch must fetch via a do/while
        // (or while) loop that re-calls get_old_submissions with a batch size,
        // not a single unbounded fetch-then-foreach.
        $src = (string) file_get_contents(
            ISF_PLUGIN_DIR . 'includes/database/class-database.php'
        );

        // Isolate the apply_retention_policy method body.
        $this->assertMatchesRegularExpression(
            '/RETENTION_BATCH_SIZE/',
            $src,
            'apply_retention_policy must use a bounded batch size constant'
        );
        $this->assertMatchesRegularExpression(
            '/get_old_submissions\s*\(\s*\$days\s*,\s*\$batch_size\s*\)/',
            $src,
            'apply_retention_policy must call get_old_submissions with a batch-size limit'
        );
        $this->assertMatchesRegularExpression(
            '/\}\s*while\s*\(\s*count\(\$old_submissions\)\s*===\s*\$batch_size\s*\)/',
            $src,
            'apply_retention_policy must loop until a short batch signals the table is drained'
        );
    }
}

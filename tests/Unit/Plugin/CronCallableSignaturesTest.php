<?php
/**
 * CronCallableSignaturesTest — protects against 3.2.0 class of bugs.
 *
 * Today's pain: send_scheduled_reports() (the isf_send_scheduled_reports
 * hourly cron callback) called Database::get_due_reports() with no
 * arguments, but the method's signature was (string $frequency) —
 * required, no default. Result: every cron tick fatal'd silently.
 *
 * Strategy: parse class-plugin.php for every add_action('isf_*' / ...)
 * that registers a cron callback. For each, use Reflection to confirm
 * the target method exists and the database calls inside that method
 * pass the required arg count to their targets.
 *
 * This is a focused arity check on the specific footgun. A broader
 * "every hook" version is out of scope per the Phase 5 spec.
 */

namespace ISF\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CronCallableSignaturesTest extends TestCase
{
    /**
     * 3.2.0 regression guard. The hourly callback must be able to
     * invoke get_due_reports() with no arguments — either because
     * the method's first parameter has a default value, or because
     * the parameter is nullable.
     */
    public function test_get_due_reports_can_be_called_with_no_args(): void
    {
        require_once ISF_PLUGIN_DIR . 'includes/database/class-database.php';
        $method = new ReflectionMethod(\ISF\Database\Database::class, 'get_due_reports');
        $params = $method->getParameters();

        if (empty($params)) {
            $this->addToAssertionCount(1);
            return;
        }

        $first = $params[0];
        $this->assertTrue(
            $first->isOptional() || $first->allowsNull(),
            sprintf(
                'Database::get_due_reports() has a required first parameter %s. ' .
                'The hourly cron callback send_scheduled_reports() calls it with no args; ' .
                'this will fatal on every cron tick. (3.2.0 regression class.)',
                $first->getName()
            )
        );
    }

    /**
     * The cron callback must call get_due_reports() in a way that
     * matches its current signature. Static-check the body.
     */
    public function test_send_scheduled_reports_call_site_matches_signature(): void
    {
        $plugin_src = file_get_contents(ISF_PLUGIN_DIR . 'includes/class-plugin.php');

        // Find the send_scheduled_reports method.
        $matches = [];
        $found = preg_match(
            '/function\s+send_scheduled_reports\s*\([^)]*\)\s*[:\w\s]*\{(.*?)^\s{4}\}/sm',
            $plugin_src,
            $matches
        );

        $this->assertSame(1, $found, 'Could not locate send_scheduled_reports() in class-plugin.php.');

        $body = $matches[1];

        // Must call get_due_reports at least once. The arity is verified
        // by the test above — here we just confirm the call site is
        // still wired so changing the cron callback target loudly fails.
        $this->assertMatchesRegularExpression(
            '/get_due_reports\s*\(/',
            $body,
            'send_scheduled_reports() no longer calls get_due_reports(); cron is unwired.'
        );
    }

    /**
     * The cron action itself must remain registered. If someone removes
     * the schedule_event call by accident, scheduled reports stop
     * running silently — same outcome as today's fatal.
     */
    public function test_isf_send_scheduled_reports_cron_action_is_registered(): void
    {
        $plugin_src = file_get_contents(ISF_PLUGIN_DIR . 'includes/class-plugin.php');

        $this->assertMatchesRegularExpression(
            "/add_action\(\s*'isf_send_scheduled_reports'\s*,/",
            $plugin_src,
            'The isf_send_scheduled_reports hourly cron handler is not registered.'
        );
    }

    /**
     * The retry-queue cron action is the other hourly job. Same
     * arity check shape — if a future refactor breaks this, jobs
     * silently stop processing.
     */
    public function test_process_retry_queue_method_exists_and_is_callable_with_no_args(): void
    {
        require_once ISF_PLUGIN_DIR . 'includes/class-plugin.php';

        // The handler lives on Plugin; if/when it's extracted, this
        // test should be updated to point at the new home.
        $this->assertTrue(
            method_exists(\ISF\Plugin::class, 'process_retry_queue'),
            'Plugin::process_retry_queue removed; the isf_process_retry_queue cron will silently fail.'
        );

        $method = new ReflectionMethod(\ISF\Plugin::class, 'process_retry_queue');
        $required = 0;
        foreach ($method->getParameters() as $p) {
            if (!$p->isOptional()) { $required++; }
        }
        $this->assertSame(
            0,
            $required,
            'process_retry_queue() has required parameters; the cron callback passes none and will fatal.'
        );
    }
}

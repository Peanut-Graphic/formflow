<?php
/**
 * Guards uninstall.php against destroying stored data by default.
 *
 * uninstall.php used to DROP every isf_* table unconditionally — encrypted
 * enrollment PII, the audit log, GDPR requests — the moment an admin clicked
 * Delete on the Plugins screen. For a utility program site of record that is an
 * irreversible data-loss landmine. Data must now be preserved unless the admin
 * has explicitly opted in via isf_settings['delete_data_on_uninstall'].
 *
 * These tests actually include uninstall.php against a recording $wpdb double
 * and assert whether DROP TABLE was issued.
 */

namespace ISF\Tests\Unit;

use Brain\Monkey\Functions;

final class UninstallDataPreservationTest extends TestCase
{
    /**
     * Include uninstall.php with the opt-in flag set (or not) and return every
     * SQL string the recording $wpdb saw.
     *
     * @return string[]
     */
    private function runUninstall(bool $optIn): array
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/');
        }
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }

        $queries = [];

        $wpdb = $this->mockWpdb([
            'query'    => function ($sql) use (&$queries) {
                $queries[] = $sql;
                return 1;
            },
            'esc_like' => function ($s) {
                return $s;
            },
            'prepare'  => function ($q, ...$args) {
                foreach ($args as $arg) {
                    $q = preg_replace('/%[sdi]/', (string) $arg, $q, 1);
                }
                return $q;
            },
        ]);
        $wpdb->options  = 'wp_options';
        $wpdb->usermeta = 'wp_usermeta';

        Functions\when('get_option')->alias(function ($key, $default = false) use ($optIn) {
            if ($key === 'isf_settings') {
                return $optIn ? ['delete_data_on_uninstall' => true] : [];
            }
            return $default; // license key etc. -> empty, so the remote call is skipped
        });
        Functions\when('delete_option')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);

        include dirname(__DIR__, 2) . '/uninstall.php';

        return $queries;
    }

    public function test_data_is_preserved_by_default(): void
    {
        $queries = $this->runUninstall(false);

        $drops = array_filter($queries, static fn ($q) => stripos($q, 'DROP TABLE') !== false);

        $this->assertCount(
            0,
            $drops,
            'With no opt-in, uninstall must NOT drop any table — a Delete click cannot destroy enrollment data.'
        );
    }

    public function test_data_is_dropped_only_when_opted_in(): void
    {
        $queries = $this->runUninstall(true);

        $drops = array_filter($queries, static fn ($q) => stripos($q, 'DROP TABLE') !== false);

        $this->assertNotEmpty($drops, 'With the opt-in set, the drop path must run.');
        $this->assertTrue(
            (bool) array_filter($drops, static fn ($q) => stripos($q, 'isf_submissions') !== false),
            'The submissions table is among those dropped when opted in.'
        );
    }
}

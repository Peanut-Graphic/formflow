<?php
/**
 * WiFi gate schema guard.
 *
 * The WiFi answer cannot live in `form_data`: that column is encrypted at rest
 * (Database::encrypt_array), so it is unreadable to reporting queries. The
 * answer and the conversion flag therefore need real columns, which means both
 * halves of this plugin's schema contract have to agree:
 *
 *   1. the CREATE TABLE must declare them, so fresh installs have them
 *   2. run_migrations() must add them, so existing installs get them
 *
 * Getting only half of that right is precisely the drift that destroyed
 * wp_isf_api_keys in 4.0.6. This guard is regex-only: no DB, no WordPress.
 *
 * @package FormFlow
 */

namespace ISF\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

final class WifiColumnsSchemaTest extends TestCase
{
    private const ACTIVATOR = 'includes/class-activator.php';

    /** Columns the WiFi eligibility gate introduces on the submissions table. */
    private const NEW_COLUMNS = ['has_wifi', 'device_converted'];

    private function activatorSource(): string
    {
        $path = ISF_PLUGIN_DIR . self::ACTIVATOR;
        if (!file_exists($path)) {
            $this->fail('Expected activator missing: ' . self::ACTIVATOR);
        }

        return (string) file_get_contents($path);
    }

    /**
     * Isolate the submissions CREATE TABLE so a column defined on some other
     * table cannot accidentally satisfy this test.
     */
    private function submissionsCreateTable(): string
    {
        $src = $this->activatorSource();

        $found = preg_match(
            '/\$sql_submissions\s*=\s*"CREATE TABLE(.*?)";/s',
            $src,
            $m
        );

        $this->assertSame(1, $found, 'Could not locate the submissions CREATE TABLE block.');

        return $m[1];
    }

    public function test_create_table_declares_the_wifi_columns(): void
    {
        $create = $this->submissionsCreateTable();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($column, '/') . '\b/',
                $create,
                "Column '{$column}' is not declared in the submissions CREATE TABLE. "
                . 'Fresh installs would be missing it while the code writes to it.'
            );
        }
    }

    public function test_has_wifi_is_nullable_so_never_asked_stays_distinguishable(): void
    {
        $create = $this->submissionsCreateTable();

        $this->assertMatchesRegularExpression(
            "/has_wifi\s+ENUM\(\s*'yes'\s*,\s*'no'\s*\)\s+(DEFAULT\s+)?NULL/i",
            $create,
            'has_wifi must be a nullable yes/no enum. NULL is meaningful: it is how a '
            . 'switch-first enrollment (never asked) stays distinguishable from an answer.'
        );
    }

    public function test_device_converted_defaults_to_zero(): void
    {
        $create = $this->submissionsCreateTable();

        $this->assertMatchesRegularExpression(
            '/device_converted\s+TINYINT\(1\)\s+NOT NULL\s+DEFAULT\s+0/i',
            $create,
            'device_converted must default to 0 so pre-existing rows read as "not converted" '
            . 'rather than NULL, which would skew the conversion figures.'
        );
    }

    public function test_a_migration_adds_the_wifi_columns_to_existing_installs(): void
    {
        $src = $this->activatorSource();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertMatchesRegularExpression(
                '/ALTER TABLE \{\$table\} ADD COLUMN ' . preg_quote($column, '/') . '\b/',
                $src,
                "run_migrations() never adds '{$column}'. Existing installs would upgrade into "
                . 'a schema the new code writes to but the table does not have.'
            );
        }
    }

    public function test_the_migration_is_guarded_so_it_can_run_twice_safely(): void
    {
        $src = $this->activatorSource();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertStringContainsString(
                "COLUMN_NAME = '{$column}'",
                $src,
                "The migration adding '{$column}' has no INFORMATION_SCHEMA existence check. "
                . 'Migrations here re-run on version drift and must be idempotent.'
            );
        }
    }
}

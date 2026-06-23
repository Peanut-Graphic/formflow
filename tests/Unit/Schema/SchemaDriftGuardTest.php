<?php
/**
 * SchemaDriftGuardTest — catches the "writes a column that the CREATE TABLE
 * never defines" class of bug in CI.
 *
 * The corrupted wp_isf_api_keys schema (fixed in 4.0.6) is the motivating
 * case: the CREATE lost its `api_key` column while the code kept doing
 * `$wpdb->insert(..., ['api_key' => ...])` and `WHERE api_key = %s`. That is a
 * pure schema drift — the writer and the schema disagreed — and nothing in the
 * suite caught it.
 *
 * This guard parses, per file:
 *   - every `CREATE TABLE ... ( ... )` block and the column names it declares
 *   - every `$wpdb->insert($table, [ 'col' => ... ])` and
 *     `$wpdb->update($table, [ 'col' => ... ], ...)` literal-array call
 * and asserts every written column name is declared by SOME CREATE TABLE in
 * the same file. It is intentionally scoped to files that own and write their
 * own isf_* table in one place (the API platform at minimum), which is exactly
 * where the api_key bug lived.
 *
 * Self-contained: filesystem + regex only, no DB, no WordPress.
 */

namespace ISF\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

final class SchemaDriftGuardTest extends TestCase
{
    /**
     * Files where a class both CREATEs and writes its own isf_* table(s).
     */
    private const SELF_CONTAINED_SCHEMA_FILES = [
        'includes/platform/class-api-platform.php',
    ];

    public function test_written_columns_exist_in_create_table_schema(): void
    {
        foreach (self::SELF_CONTAINED_SCHEMA_FILES as $rel) {
            $path = ISF_PLUGIN_DIR . $rel;
            if (!file_exists($path)) {
                $this->fail("Expected schema file missing: $rel");
            }
            $src = (string) file_get_contents($path);

            $declared = $this->declaredColumns($src);
            $this->assertNotEmpty(
                $declared,
                "$rel: no CREATE TABLE columns parsed — test cannot guard this file."
            );

            $written = $this->writtenColumns($src);
            $this->assertNotEmpty(
                $written,
                "$rel: no \$wpdb->insert/update literal columns parsed — test cannot guard this file."
            );

            foreach ($written as $col) {
                $this->assertContains(
                    $col,
                    $declared,
                    "$rel: column '$col' is written via \$wpdb->insert/update but is not declared by any CREATE TABLE in this file. This is schema drift (the api_key class of bug)."
                );
            }
        }
    }

    public function test_api_key_specifically_is_declared(): void
    {
        // Targeted assertion for the exact column that was destroyed.
        $src = (string) file_get_contents(
            ISF_PLUGIN_DIR . 'includes/platform/class-api-platform.php'
        );
        $declared = $this->declaredColumns($src);
        $this->assertContains(
            'api_key',
            $declared,
            "api_key column must be declared by the CREATE TABLE — it is what auth queries with WHERE api_key = %s."
        );
    }

    /**
     * Parse all column names declared across every CREATE TABLE block in $src.
     *
     * @return string[]
     */
    private function declaredColumns(string $src): array
    {
        $columns = [];

        // Grab each CREATE TABLE (...) body. Bodies are delimited by the first
        // "(" after CREATE TABLE and a closing "){$charset_collate}" or ");".
        if (!preg_match_all('/CREATE\s+TABLE[^(]*\((.*?)\)\s*\{?\$?charset/is', $src, $matches)) {
            return $columns;
        }

        foreach ($matches[1] as $body) {
            foreach (preg_split('/\r?\n/', $body) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // Skip key/constraint lines.
                if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX|FOREIGN\s+KEY|CONSTRAINT)\b/i', $line)) {
                    continue;
                }
                // A column definition starts with an identifier then a type.
                if (preg_match('/^`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+[A-Za-z]/', $line, $m)) {
                    $columns[] = $m[1];
                }
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Parse column names written via $wpdb->insert(...) / $wpdb->update(...)
     * with an inline associative-array literal.
     *
     * @return string[]
     */
    private function writtenColumns(string $src): array
    {
        $columns = [];

        // Match $wpdb->insert(   $table,   [ ... ]  ) and ->update similarly.
        // We only need the array literal's quoted keys (=> follows a key).
        if (!preg_match_all('/->(?:insert|update)\s*\((.*?)\)\s*;/is', $src, $calls)) {
            return $columns;
        }

        foreach ($calls[1] as $args) {
            // 'col' => ...  OR  "col" => ...
            if (preg_match_all('/[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*=>/', $args, $keys)) {
                foreach ($keys[1] as $k) {
                    $columns[] = $k;
                }
            }
        }

        return array_values(array_unique($columns));
    }
}

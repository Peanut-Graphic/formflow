<?php

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the corrupted `wp_isf_api_keys` CREATE TABLE.
 *
 * Before 4.0.6 the column list in TWO files was mangled:
 *
 *     description TEXT,
 *     api_PRIMARY KEY  (id),      <-- "api_" + "PRIMARY KEY" fused
 *     key VARCHAR(64) NOT NULL,   <-- the leftover, a reserved word
 *
 * The intended `api_key VARCHAR(64) NOT NULL` column was destroyed, so the
 * table was created WITHOUT an `api_key` column while `UNIQUE KEY api_key
 * (api_key)` referenced a column that did not exist. API-key authentication
 * (`WHERE api_key = %s`) and key creation (INSERT ... api_key) were broken on
 * every install.
 *
 * The same corruption appeared in the activator's `isf_tenants` table.
 *
 * This test is self-contained (filesystem scan only) so it runs in CI without
 * a database.
 */
final class ApiKeysSchemaCorruptionTest extends TestCase
{
    /**
     * Files that build a CREATE TABLE containing an api_key column.
     */
    private const FILES = [
        'includes/class-activator.php',
        'includes/platform/class-api-platform.php',
    ];

    public function test_no_fused_api_primary_key_token(): void
    {
        foreach (self::FILES as $rel) {
            $src = $this->read($rel);
            $this->assertStringNotContainsString(
                'api_PRIMARY KEY',
                $src,
                "$rel: 'api_PRIMARY KEY' is the fused-token corruption. The 'api_key VARCHAR(64) NOT NULL' column must be intact and PRIMARY KEY on its own line."
            );
        }
    }

    public function test_no_orphaned_reserved_word_key_column(): void
    {
        foreach (self::FILES as $rel) {
            $src = $this->read($rel);
            // The corruption left a bare `key VARCHAR(64) NOT NULL,` line —
            // `key` is a MySQL reserved word and was never a real column.
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*key\s+VARCHAR\(64\)\s+NOT\s+NULL\s*,/mi',
                $src,
                "$rel: orphaned 'key VARCHAR(64) NOT NULL,' line (reserved word) is the corruption leftover. It should read 'api_key VARCHAR(64) NOT NULL,'."
            );
        }
    }

    public function test_api_key_column_is_defined_intact(): void
    {
        foreach (self::FILES as $rel) {
            $src = $this->read($rel);
            $this->assertMatchesRegularExpression(
                '/\bapi_key\s+VARCHAR\(64\)\s+NOT\s+NULL/i',
                $src,
                "$rel: the 'api_key VARCHAR(64) NOT NULL' column must exist — it is what `WHERE api_key = %s` auth queries."
            );
        }
    }

    public function test_activator_tenants_table_api_key_intact(): void
    {
        // The Activator's isf_tenants table carried the same corruption.
        $src = $this->read('includes/class-activator.php');
        // The whole file must be free of the fused token (covered above) AND
        // free of the orphaned reserved-word column (covered above). Here we
        // additionally assert the tenants block no longer references a bare
        // `key VARCHAR` anywhere.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*key\s+VARCHAR/mi',
            $src,
            "includes/class-activator.php: a bare 'key VARCHAR' column (reserved word) survives — the isf_tenants / isf_api_keys corruption is not fully repaired."
        );
    }

    private function read(string $rel): string
    {
        $path = $this->repoRoot() . '/' . $rel;
        $this->assertFileExists($path, "Expected schema file missing: $rel");
        return (string) file_get_contents($path);
    }

    private function repoRoot(): string
    {
        // tests/Regression/ -> repo root is two levels up.
        return dirname(__DIR__, 2);
    }
}

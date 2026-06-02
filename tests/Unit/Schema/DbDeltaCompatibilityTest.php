<?php
/**
 * DbDeltaCompatibilityTest — pins the schema-as-string contract that
 * keeps dbDelta from spewing "Multiple primary key defined" /
 * "ADD COLUMN CONSTRAINT" noise on every activation.
 *
 * 4.0.3 cleaned every CREATE TABLE statement to dbDelta-compliant form
 * after the Dominion incident (4.0.0/4.0.1 install) made the noise
 * visible in debug.log and surfaced 15+ tables of latent breakage.
 *
 * dbDelta requires:
 *   - PRIMARY KEY on its own line, NOT inline on the id column
 *   - TWO spaces between "PRIMARY KEY" and the column-list parenthesis
 *   - No FOREIGN KEY / CONSTRAINT clauses (unsupported; mangled into
 *     invalid ADD COLUMN ALTERs)
 *   - KEY (not INDEX) for non-unique indexes
 */

namespace ISF\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

final class DbDeltaCompatibilityTest extends TestCase
{
    /**
     * Every file in the plugin that builds a CREATE TABLE string and
     * hands it to dbDelta. Add new schemas here when introduced.
     */
    private const SCHEMA_FILES = [
        'includes/class-activator.php',
        'includes/class-document-upload.php',
        'includes/class-capacity-manager.php',
        'includes/destinations/class-delivery-log.php',
        'includes/programs/class-program-manager.php',
        'includes/programs/class-appointment-bundler.php',
        'includes/platform/class-marketplace.php',
        'includes/platform/class-white-label.php',
        'includes/platform/class-api-platform.php',
    ];

    public function test_no_inline_auto_increment_primary_key(): void
    {
        foreach (self::SCHEMA_FILES as $rel) {
            $path = ISF_PLUGIN_DIR . $rel;
            if (!file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression(
                '/AUTO_INCREMENT\s+PRIMARY\s+KEY/i',
                $src,
                "$rel: inline 'AUTO_INCREMENT PRIMARY KEY' breaks dbDelta. Split into 'NOT NULL AUTO_INCREMENT' + a separate 'PRIMARY KEY  (id)' line with TWO spaces."
            );
        }
    }

    public function test_no_foreign_key_constraints(): void
    {
        foreach (self::SCHEMA_FILES as $rel) {
            $path = ISF_PLUGIN_DIR . $rel;
            if (!file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression(
                '/FOREIGN\s+KEY/i',
                $src,
                "$rel: FOREIGN KEY is unsupported by dbDelta. It gets mangled into 'ADD COLUMN CONSTRAINT' ALTERs that fail every activation. Enforce referential integrity in app code instead."
            );
        }
    }

    public function test_no_index_keyword_for_non_unique_keys(): void
    {
        // dbDelta wants `KEY name (col)`, not `INDEX name (col)`. The
        // table is created either way, but dbDelta then synthesizes
        // mismatched ALTERs on every load.
        foreach (self::SCHEMA_FILES as $rel) {
            $path = ISF_PLUGIN_DIR . $rel;
            if (!file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression(
                '/^\s+INDEX\s+\w+\s*\(/m',
                $src,
                "$rel: dbDelta requires 'KEY name (...)', not 'INDEX name (...)'."
            );
        }
    }

    public function test_primary_key_uses_two_spaces(): void
    {
        // The two-space requirement between PRIMARY KEY and ( is a
        // documented dbDelta quirk — single-space `PRIMARY KEY (id)`
        // makes dbDelta think the table doesn't have a PK and emit a
        // duplicate ALTER on every load.
        foreach (self::SCHEMA_FILES as $rel) {
            $path = ISF_PLUGIN_DIR . $rel;
            if (!file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            // Match any "PRIMARY KEY" followed by exactly one whitespace
            // char and then a paren. That's the single-space violation.
            $this->assertDoesNotMatchRegularExpression(
                '/PRIMARY\s+KEY\s\(/',
                $src,
                "$rel: dbDelta requires TWO spaces between 'PRIMARY KEY' and '('. Use 'PRIMARY KEY  (id)'."
            );
        }
    }
}

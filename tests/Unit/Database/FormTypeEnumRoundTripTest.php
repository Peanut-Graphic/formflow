<?php
/**
 * FormTypeEnumRoundTripTest — protects against 3.0.6 class of bugs.
 *
 * The form_type column is an ENUM. When the allowed values get out of
 * sync with what the application writes, MySQL silently coerces the
 * write to '' and reports 0 rows changed. Today's pain: 'custom' was
 * missing from the ENUM for weeks. wpdb->update returned int(0) which
 * the calling code treated as success. Every imported custom-template
 * instance had form_type='' and the public renderer fell through to
 * the IntelliSOURCE wizard.
 *
 * Strategy: parse the create_tables SQL and the migration SQL to verify
 * every form_type value the application uses is in the ENUM.
 */

namespace ISF\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

final class FormTypeEnumRoundTripTest extends TestCase
{
    private const REQUIRED_FORM_TYPES = ['enrollment', 'scheduler', 'external', 'custom'];

    private string $activator_source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activator_source = file_get_contents(ISF_PLUGIN_DIR . 'includes/class-activator.php');
    }

    public function test_create_tables_includes_all_required_form_types(): void
    {
        // Find every form_type ENUM declaration in the source. The CREATE
        // TABLE in create_tables() is the canonical schema; older
        // migration ALTER statements are historical. Require that at
        // least one declaration includes every required value (the
        // current schema), and that the LAST declaration (the canonical
        // CREATE TABLE) is the up-to-date one.
        $matches = [];
        preg_match_all(
            "/form_type\s+ENUM\(([^)]+)\)/i",
            $this->activator_source,
            $matches
        );

        $this->assertNotEmpty($matches[1], 'No form_type ENUM declaration found in class-activator.php.');

        $canonical = $this->parse_enum_values(end($matches[1]));

        foreach (self::REQUIRED_FORM_TYPES as $required) {
            $this->assertContains(
                $required,
                $canonical,
                "Canonical form_type ENUM is missing '{$required}'. Inserts/updates with this value will be silently coerced to ''."
            );
        }
    }

    /**
     * Each ENUM value used by the application must have a migration
     * that adds it to the column for existing installs. Otherwise an
     * upgrade-install never gets the new value and the original bug
     * recurs for sites that don't reinstall from scratch.
     */
    public function test_each_form_type_has_a_migration_path(): void
    {
        $migration_pattern = '/MODIFY COLUMN\s+form_type\s+ENUM\(([^)]+)\)/i';
        $matches = [];
        preg_match_all($migration_pattern, $this->activator_source, $matches);

        $this->assertNotEmpty(
            $matches[1],
            'No ALTER TABLE migrations for form_type ENUM found in run_migrations(). Adding a value to create_tables() alone leaves existing sites broken.'
        );

        // The latest migration should contain every required value.
        $latest_values = $this->parse_enum_values(end($matches[1]));

        foreach (self::REQUIRED_FORM_TYPES as $required) {
            $this->assertContains(
                $required,
                $latest_values,
                "Latest form_type ENUM migration does not include '{$required}'. Existing installs will silently coerce this value to ''."
            );
        }
    }

    public function test_public_renderer_dispatches_on_every_required_form_type(): void
    {
        $public_source = file_get_contents(ISF_PLUGIN_DIR . 'public/class-public.php');

        $dispatches_present = [
            'external' => (bool) preg_match("/form_type'\]\s*===\s*'external'/", $public_source),
            'custom'   => (bool) preg_match("/form_type'\]\s*===\s*'custom'/", $public_source),
            // enrollment + scheduler fall through to the legacy wizard
            // path and are matched by $is_scheduler = ... === 'scheduler'.
            'scheduler' => (bool) preg_match("/form_type'\]\s*===\s*'scheduler'/", $public_source),
        ];

        foreach ($dispatches_present as $type => $present) {
            $this->assertTrue(
                $present,
                "Public renderer has no explicit dispatch for form_type='{$type}'."
            );
        }
    }

    /**
     * @param string $raw The ENUM(...) inner text, e.g. "'a','b','c'"
     * @return string[]
     */
    private function parse_enum_values(string $raw): array
    {
        $vals = [];
        preg_match_all("/'([^']+)'/", $raw, $vals);
        return $vals[1] ?? [];
    }
}

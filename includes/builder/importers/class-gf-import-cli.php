<?php
/**
 * WP-CLI command for the Gravity Forms importer.
 *
 * Registered as `wp formflow import-gf` when WP-CLI is available.
 *
 * Usage:
 *   wp formflow import-gf <file.json> [--dry-run] [--activate]
 *
 *   <file.json>   Path to the Gravity Forms JSON export. Required.
 *   --dry-run     Build the FormFlow shape and print the report without
 *                 writing anything to the database.
 *   --activate    Create the imported instance as active. Default is
 *                 inactive so you can review before activation.
 *
 * @package FormFlow
 * @subpackage Builder
 * @since 4.0.0
 */

namespace ISF\Builder\Importers;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class GfImportCli
{
    public static function register(): void
    {
        \WP_CLI::add_command('formflow import-gf', [self::class, 'run']);
    }

    public static function run(array $args, array $assoc_args): void
    {
        if (empty($args[0])) {
            \WP_CLI::error('Path to a Gravity Forms JSON export file is required. Usage: wp formflow import-gf <file.json>');
        }
        $path = (string) $args[0];
        $dry  = !empty($assoc_args['dry-run']);
        $act  = !empty($assoc_args['activate']);

        $importer = new GravityFormsImporter();
        try {
            $results = $importer->import_file($path, ['dry_run' => $dry, 'activate' => $act]);
        } catch (\Throwable $e) {
            \WP_CLI::error('Import failed: ' . $e->getMessage());
        }

        $count = count($results);
        \WP_CLI::log(sprintf(
            '%s %d form(s) from %s%s',
            $dry ? 'Would import' : 'Imported',
            $count,
            basename($path),
            $dry ? ' (DRY RUN)' : ''
        ));

        foreach ($results as $i => $r) {
            \WP_CLI::log('');
            \WP_CLI::log(sprintf(
                '[%d] %s', $i + 1, $r['name'] ?: '(untitled)'
            ));
            \WP_CLI::log(sprintf('    slug:        %s', $r['slug']));
            \WP_CLI::log(sprintf('    fields:      %d', $r['field_count']));
            if (!$dry) {
                \WP_CLI::log(sprintf('    instance_id: %s', (string) $r['instance_id']));
                \WP_CLI::log(sprintf('    active:      %s', $act ? 'yes' : 'no'));
            }
            if (!empty($r['warnings'])) {
                \WP_CLI::log(sprintf('    warnings (%d):', count($r['warnings'])));
                foreach ($r['warnings'] as $w) {
                    \WP_CLI::log('      - ' . $w);
                }
            }
        }

        \WP_CLI::log('');
        if ($dry) {
            \WP_CLI::success('Dry run complete. Re-run without --dry-run to write.');
        } else {
            \WP_CLI::success('Import complete. Review at /wp-admin/admin.php?page=isf-dashboard before activating.');
        }
    }
}

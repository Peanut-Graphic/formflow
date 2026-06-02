<?php
/**
 * GfImporterRegistrationTest — protects the WP-CLI command registration.
 *
 * If a future refactor moves the GF importer files, deletes the
 * GfImportCli::register() call from formflow.php, or drops the
 * `wp formflow import-gf` command name, this test fails before ship.
 *
 * The actual mapping behavior is covered by GravityFormsImporterTest;
 * this test only enforces the CLI surface contract.
 */

namespace ISF\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;

final class GfImporterRegistrationTest extends TestCase
{
    private string $bootstrap_src;
    private string $cli_src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrap_src = file_get_contents(ISF_PLUGIN_DIR . 'formflow.php');
        $this->cli_src       = file_get_contents(ISF_PLUGIN_DIR . 'includes/builder/importers/class-gf-import-cli.php');
    }

    public function test_cli_class_file_exists(): void
    {
        $this->assertFileExists(
            ISF_PLUGIN_DIR . 'includes/builder/importers/class-gf-import-cli.php',
            'GF importer CLI class file missing — wp formflow import-gf command would not register.'
        );
        $this->assertFileExists(
            ISF_PLUGIN_DIR . 'includes/builder/importers/class-gravity-forms-importer.php',
            'GF importer mapper class file missing.'
        );
    }

    public function test_bootstrap_requires_importer_and_cli(): void
    {
        $this->assertStringContainsString(
            "includes/builder/importers/class-gravity-forms-importer.php",
            $this->bootstrap_src,
            'formflow.php must require_once the GF importer.'
        );
        $this->assertStringContainsString(
            "includes/builder/importers/class-gf-import-cli.php",
            $this->bootstrap_src,
            'formflow.php must require_once the GF importer CLI.'
        );
    }

    public function test_bootstrap_calls_cli_register(): void
    {
        // The CLI must be registered via GfImportCli::register() so
        // WP_CLI sees the `wp formflow import-gf` command.
        $this->assertMatchesRegularExpression(
            '/GfImportCli::register\(\)/',
            $this->bootstrap_src,
            'formflow.php must call GfImportCli::register() — without it, the WP-CLI command is invisible.'
        );
    }

    /**
     * 4.0.2 hotfix: the CLI registration must be gated on the WP_CLI
     * constant in formflow.php itself — NOT relied on via a `return`
     * at the top of the CLI file. PHP processes top-level `class`
     * declarations at compile time, so a file with `return` followed
     * by `class X {}` still declares X in web context. Without this
     * gate, GfImportCli::register() runs on every web request and
     * fatals on \WP_CLI::add_command() (the WP_CLI *class* is only
     * loaded under the WP-CLI binary). This took down Dominion.
     */
    public function test_cli_registration_is_gated_on_wp_cli_constant(): void
    {
        $this->assertMatchesRegularExpression(
            "/defined\\(\\s*['\"]WP_CLI['\"]\\s*\\)\\s*&&\\s*WP_CLI/",
            $this->bootstrap_src,
            'formflow.php must gate the GF importer CLI registration on `defined(\'WP_CLI\') && WP_CLI` — otherwise web requests fatal on missing WP_CLI class.'
        );
    }

    public function test_cli_command_is_named_formflow_import_gf(): void
    {
        $this->assertMatchesRegularExpression(
            "/WP_CLI::add_command\(\s*'formflow import-gf'/",
            $this->cli_src,
            'CLI command name must be exactly `formflow import-gf` — the documented invocation across the 8-site GF migration runbook depends on it.'
        );
    }

    public function test_cli_run_method_accepts_dry_run_and_activate_flags(): void
    {
        // Mirror the documented usage:
        //   wp formflow import-gf <file.json> [--dry-run] [--activate]
        $this->assertMatchesRegularExpression(
            "/\\\$assoc_args\\['dry-run'\\]/",
            $this->cli_src,
            'CLI run() must read --dry-run from \$assoc_args.'
        );
        $this->assertMatchesRegularExpression(
            "/\\\$assoc_args\\['activate'\\]/",
            $this->cli_src,
            'CLI run() must read --activate from \$assoc_args.'
        );
    }

    /**
     * The importer must default to creating instances as inactive so
     * a bad import doesn't silently expose a broken form to public
     * traffic on any of the 8 migration sites.
     */
    public function test_importer_defaults_to_inactive(): void
    {
        $mapper_src = file_get_contents(ISF_PLUGIN_DIR . 'includes/builder/importers/class-gravity-forms-importer.php');
        $this->assertMatchesRegularExpression(
            "/'activate'\s*=>\s*false/",
            $mapper_src,
            'GravityFormsImporter::import() must default activate=false so imported forms land inactive for review.'
        );
    }
}

<?php

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;

require_once ISF_PLUGIN_DIR . 'admin/traits/trait-submissions.php';

/**
 * Minimal concrete host so the trait's private static CSV sanitizer can be
 * exercised in isolation. Only the static helper is invoked; no instance state
 * (e.g. $this->db) is touched, so the trait's runtime dependencies are irrelevant.
 */
class CsvTraitHost {
    use \ISF\Admin\Admin_Submissions;
}

/**
 * Regression guard for the P1/P2 admin-export security cluster.
 *
 * Three defects, all driven by UNAUTHENTICATED enrollee input surfacing in a
 * manage_options admin context:
 *
 *   1. (P1) Stored XSS in admin/views/data.php — the submission-detail modal
 *      rendered most submitted fields (and an attacker-controlled email into a
 *      `mailto:` href) WITHOUT the file's escapeHtml() helper, so form_data could
 *      break out of the DOM / attribute and execute in an admin session.
 *
 *   2. (P1) CSV formula injection in admin/traits/trait-submissions.php — the
 *      export escaper only doubled quotes; a cell leading with = + - @ TAB CR
 *      executes as a formula in Excel / Sheets.
 *
 *   3. (P2) Same class in includes/analytics/class-attribution-exporter.php for
 *      anon-seeded UTM fields.
 *
 * The CSV assertions exercise the real production sanitizers via reflection; the
 * XSS assertions are a source scan of the JS render path (no JS runtime in
 * PHPUnit). Self-contained — no database required.
 */
final class AdminExportInjectionAndXssTest extends TestCase
{
    /**
     * @dataProvider dangerousCells
     */
    public function test_trait_csv_cell_is_neutralized(string $input, string $expected): void
    {
        $m = new \ReflectionMethod(CsvTraitHost::class, 'sanitize_csv_cell');
        $this->assertSame($expected, $m->invoke(null, $input), "trait CSV cell not neutralized: $input");
    }

    /**
     * @dataProvider dangerousCells
     */
    public function test_attribution_exporter_cell_is_neutralized(string $input, string $expected): void
    {
        $m = new \ReflectionMethod(\ISF\Analytics\AttributionExporter::class, 'sanitize_csv_cell');
        $this->assertSame($expected, $m->invoke(null, $input), "exporter CSV cell not neutralized: $input");
    }

    /**
     * Leading formula-trigger characters get a single-quote prefix; benign
     * values pass through unchanged.
     */
    public static function dangerousCells(): array
    {
        return [
            'HYPERLINK formula' => ['=HYPERLINK("http://evil","x")', "'=HYPERLINK(\"http://evil\",\"x\")"],
            'leading plus'      => ['+1234567890', "'+1234567890"],
            'leading minus'     => ['-2+3', "'-2+3"],
            'leading at'        => ['@SUM(A1:A9)', "'@SUM(A1:A9)"],
            'leading tab'       => ["\t=1+1", "'\t=1+1"],
            'leading cr'        => ["\r=1+1", "'\r=1+1"],
            'benign name'       => ['Jane Doe', 'Jane Doe'],
            'benign account'    => ['ACC-001 kept as text? no', 'ACC-001 kept as text? no'],
            'empty'             => ['', ''],
            'mid-string equals' => ['a=b', 'a=b'],
        ];
    }

    /**
     * The manual CSV builder must treat a bare CR as a wrap trigger (the pre-fix
     * code omitted \r, so a CR could split a record).
     */
    public function test_wrap_trigger_includes_carriage_return(): void
    {
        $src = $this->read('admin/traits/trait-submissions.php');
        $this->assertMatchesRegularExpression(
            '/strpos\(\$field,\s*"\\\\r"\)\s*!==\s*false/',
            $src,
            'trait-submissions.php CSV wrap-trigger must include a "\\r" check.'
        );
    }

    // ---- XSS render-path source scan (admin/views/data.php) -----------------

    public function test_email_is_not_interpolated_raw_into_mailto_href(): void
    {
        $src = $this->read('admin/views/data.php');
        // The pre-fix sink: mailto href / link text built from the raw value.
        $this->assertStringNotContainsString(
            "'<a href=\"mailto:' + fd.email + '\">' + fd.email + '</a>'",
            $src,
            'data.php still interpolates fd.email raw into a mailto: href (attribute-injection XSS).'
        );
        // The fix: gated on an email match, address encodeURIComponent-encoded.
        $this->assertStringContainsString('isEmail(fd.email)', $src, 'email must be validated before emitting a mailto: href.');
        $this->assertStringContainsString("encodeURIComponent(String(fd.email))", $src, 'mailto address must be encodeURIComponent-encoded.');
    }

    public function test_escapehtml_hardened_for_attribute_contexts(): void
    {
        $src = $this->read('admin/views/data.php');
        // The hardened helper must escape quotes so attribute contexts are safe.
        $this->assertStringContainsString('&quot;', $src, 'escapeHtml must escape double quotes.');
        $this->assertStringContainsString('&#39;', $src, 'escapeHtml must escape single quotes.');
    }

    public function test_submitted_fields_routed_through_escapehtml(): void
    {
        $src = $this->read('admin/views/data.php');
        foreach ([
            'escapeHtml(s.session_id)',
            'escapeHtml(fd.promo_code)',
            'escapeHtml(fd.confirmation_number)',
            'escapeHtml(fd.phone)',
            'escapeHtml(JSON.stringify(fd, null, 2))',
        ] as $needle) {
            $this->assertStringContainsString($needle, $src, "field render not escaped: $needle");
        }
    }

    private function read(string $rel): string
    {
        $path = ISF_PLUGIN_DIR . $rel;
        $this->assertFileExists($path, "missing source file: $rel");
        return (string) file_get_contents($path);
    }
}

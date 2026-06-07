<?php
/**
 * Net 6 (property) — Frontend form_type classification + canonicalization.
 *
 * Targets two PURE, WordPress-free static functions in public/class-public.php:
 *   - Frontend::classify(string): string            — routes a form_type to a subsystem
 *   - Frontend::canonicalize_form_type(string): string — normalizes legacy aliases
 *
 * These run the 4.0 dispatch table that every front-end render depends on. The
 * 3.0.5 / 3.1.1 production incidents all came from inconsistent form_type
 * routing, so the invariants below are real and load-bearing — not decoration.
 *
 * Seam: both functions are `public static`, take/return plain strings, touch no
 * WordPress API, no DB, no globals. The class file is required directly; the
 * Frontend constructor (which DOES need WP) is never invoked.
 *
 * Determinism: the only randomness is a SEEDED input generator (fixed seed), so
 * the same set of cases runs every time. No wall-clock, no network, no order
 * dependence.
 */

namespace ISF\Tests\Property;

use PHPUnit\Framework\TestCase;
use ISF\Frontend\Frontend;

require_once ISF_PLUGIN_DIR . 'public/class-public.php';

final class FormTypeClassificationPropertyTest extends TestCase
{
    /** The complete, closed set of subsystems classify() may return. */
    private const VALID_SUBSYSTEMS = ['external', 'intellisource', 'builder'];

    /** Every form_type value the dispatch table is documented to support. */
    private const KNOWN_FORM_TYPES = [
        'external',
        'enrollment',
        'scheduler',
        'custom',
        'intellisource_wizard',
        'intellisource_scheduler',
        'builder',
    ];

    /**
     * Generate a deterministic corpus of inputs: every known value plus a
     * batch of seeded "junk" strings (unknown values must still be total).
     *
     * @return string[]
     */
    private function inputCorpus(): array
    {
        $corpus = self::KNOWN_FORM_TYPES;

        // Seeded so the run is reproducible (determinism rule).
        mt_srand(424242);
        $alphabet = 'abcdefghijklmnopqrstuvwxyz_-0123456789 ';
        for ($i = 0; $i < 200; $i++) {
            $len = mt_rand(0, 24);
            $s = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            $corpus[] = $s;
        }
        // A few adversarial edge cases.
        $corpus[] = '';
        $corpus[] = 'EXTERNAL';        // case sensitivity (match is case-sensitive)
        $corpus[] = ' enrollment ';    // whitespace padding
        $corpus[] = 'enrollment ';

        return $corpus;
    }

    /**
     * INVARIANT (format / totality): classify() is a total function whose
     * output is ALWAYS one of the three known subsystems — never a leaked
     * input, never an empty string, for ANY string input.
     */
    public function test_classify_output_is_always_a_known_subsystem(): void
    {
        foreach ($this->inputCorpus() as $input) {
            $subsystem = Frontend::classify($input);
            $this->assertContains(
                $subsystem,
                self::VALID_SUBSYSTEMS,
                sprintf('classify(%s) returned out-of-domain value %s', var_export($input, true), var_export($subsystem, true))
            );
        }
    }

    /**
     * INVARIANT (idempotency): canonicalize_form_type() is idempotent —
     * normalizing an already-canonical value must be a fixed point.
     * canon(canon(x)) === canon(x) for all x.
     */
    public function test_canonicalize_is_idempotent(): void
    {
        foreach ($this->inputCorpus() as $input) {
            $once  = Frontend::canonicalize_form_type($input);
            $twice = Frontend::canonicalize_form_type($once);
            $this->assertSame(
                $once,
                $twice,
                sprintf('canonicalize not idempotent for %s: once=%s twice=%s', var_export($input, true), var_export($once, true), var_export($twice, true))
            );
        }
    }

    /**
     * INVARIANT (routing stability under canonicalization): canonicalization
     * is an intra-subsystem normalization — it must NEVER move a value to a
     * different subsystem. classify(x) === classify(canonicalize(x)) for all x.
     *
     * This is the real safety property the dispatch comments promise: legacy
     * sites whose rows hold 'enrollment' must route identically whether the
     * caller canonicalizes first or not. If this ever fails, a legacy form
     * would silently render through the wrong subsystem.
     */
    public function test_canonicalization_preserves_subsystem_routing(): void
    {
        foreach ($this->inputCorpus() as $input) {
            $direct = Frontend::classify($input);
            $viaCanon = Frontend::classify(Frontend::canonicalize_form_type($input));
            $this->assertSame(
                $direct,
                $viaCanon,
                sprintf(
                    'canonicalization changed routing for %s: classify=%s but classify(canon)=%s',
                    var_export($input, true),
                    var_export($direct, true),
                    var_export($viaCanon, true)
                )
            );
        }
    }

    /**
     * INVARIANT (determinism): both functions are pure — identical input
     * yields identical output across repeated calls.
     */
    public function test_functions_are_deterministic(): void
    {
        foreach ($this->inputCorpus() as $input) {
            $this->assertSame(Frontend::classify($input), Frontend::classify($input));
            $this->assertSame(
                Frontend::canonicalize_form_type($input),
                Frontend::canonicalize_form_type($input)
            );
        }
    }
}

<?php

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the cd6f378 "ABSPATH-before-namespace" fatal.
 *
 * Commit cd6f378 ("Add ABSPATH security check to all PHP files") blindly
 * prepended:
 *
 *     if ( ! defined( 'ABSPATH' ) ) { exit; }
 *
 * to the TOP of every PHP file -- including namespaced files, where it landed
 * ABOVE the `namespace ...;` statement. PHP requires `namespace` to be the
 * very first statement in a file, so every affected file fatals with:
 *
 *     PHP Fatal error: Namespace declaration statement has to be the very
 *     first statement or after any declare call in the script
 *
 * Production (peanutgraphic.com) was hand-hotfixed on 2026-05-15; this test
 * makes the repository itself durable so that a redeploy from origin/main
 * cannot silently re-break production.
 *
 * This test is deliberately SELF-CONTAINED: it scans the filesystem and does
 * not load the plugin, WordPress, or any app class. It therefore runs green
 * in CI regardless of the (heavy, WP-dependent) PHPUnit bootstrap state.
 *
 * Both copies of the plugin are scanned: the repo root tree AND the nested
 * `formflow/` duplicate -- whatever ends up packaged must be correct.
 */
final class AbspathGuardOrderTest extends TestCase
{
    /**
     * Directory names that are pruned from the scan entirely.
     */
    private const EXCLUDED_DIRS = [
        'vendor',
        'node_modules',
        '.claude',
        '.git',
    ];

    public function test_no_php_file_declares_abspath_guard_before_its_namespace(): void
    {
        $root = $this->repoRoot();

        $offenders = [];
        foreach ($this->phpFiles($root) as $file) {
            $line = $this->guardBeforeNamespaceLine($file);
            if ($line !== null) {
                $rel = substr($file, strlen($root) + 1);
                $offenders[] = $rel . ':' . $line;
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            sprintf(
                "Found %d PHP file(s) with an ABSPATH guard placed BEFORE the "
                . "namespace declaration (cd6f378 regression). PHP fatals on "
                . "these. Offending file:line pairs:\n%s",
                count($offenders),
                implode("\n", $offenders)
            )
        );
    }

    /**
     * Returns the 1-based line number of the offending ABSPATH guard if the
     * file declares a namespace AND the first ABSPATH guard occurrence is
     * strictly before the first `namespace ...;` line. Returns null otherwise
     * (no namespace, no guard, or guard correctly placed after namespace).
     *
     * Detector mirrors the transform script (fix_abspath_ns.sh): robust to
     * spacing variants, single/double quotes around ABSPATH, single-line and
     * multi-line guards, and an optional leading declare().
     */
    private function guardBeforeNamespaceLine(string $file): ?int
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $lines = preg_split('/\R/', $contents);
        if ($lines === false) {
            return null;
        }

        $namespaceLine = 0;
        $guardLine = 0;

        foreach ($lines as $idx => $text) {
            $lineNo = $idx + 1;

            if ($namespaceLine === 0
                && preg_match('/^[ \t]*namespace[ \t]+[A-Za-z_\\\\]/', $text) === 1
            ) {
                $namespaceLine = $lineNo;
            }

            if ($guardLine === 0
                && preg_match('/defined[ \t]*\([ \t]*[\'"]ABSPATH[\'"]/', $text) === 1
            ) {
                $guardLine = $lineNo;
            }
        }

        if ($namespaceLine === 0 || $guardLine === 0) {
            return null;
        }

        return $guardLine < $namespaceLine ? $guardLine : null;
    }

    /**
     * @return iterable<string> absolute paths to every *.php file under $root,
     *                          excluding vendor/node_modules/.claude/.git.
     */
    private function phpFiles(string $root): iterable
    {
        $dirIterator = new \RecursiveDirectoryIterator(
            $root,
            \FilesystemIterator::SKIP_DOTS
        );

        $filter = new \RecursiveCallbackFilterIterator(
            $dirIterator,
            static function (\SplFileInfo $current): bool {
                if ($current->isDir()) {
                    return !in_array(
                        $current->getFilename(),
                        self::EXCLUDED_DIRS,
                        true
                    );
                }

                return strtolower($current->getExtension()) === 'php';
            }
        );

        $iterator = new \RecursiveIteratorIterator($filter);

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isFile()) {
                yield $fileInfo->getPathname();
            }
        }
    }

    private function repoRoot(): string
    {
        // tests/Regression/AbspathGuardOrderTest.php -> repo root is two dirs up.
        return dirname(__DIR__, 2);
    }
}

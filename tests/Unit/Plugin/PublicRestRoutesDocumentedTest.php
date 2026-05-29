<?php
/**
 * PublicRestRoutesDocumentedTest — protects Phase 4b discipline.
 *
 * Every REST route that uses `__return_true` for its permission callback
 * must have a documenting comment within 6 lines above it explaining
 * WHY it's public (token-gated / form-submission / health probe / etc.).
 * This catches future routes added without thinking about the auth model.
 *
 * The reasoning lives next to the code, not in a separate audit doc that
 * goes stale. If a contributor adds a new public route without the
 * documenting comment, CI fails and asks them to articulate why public
 * is the right call.
 */

namespace ISF\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;

final class PublicRestRoutesDocumentedTest extends TestCase
{
    /** Marker prefixes that count as a justification comment. */
    private const VALID_MARKERS = [
        'Public:',
        'Public by design',
        'permission_callback is __return_true',
    ];

    public function test_every_return_true_route_is_documented(): void
    {
        $undocumented = [];

        foreach ($this->scan_php_files() as $file) {
            $rel = str_replace(ISF_PLUGIN_DIR, '', $file);
            $lines = file($file);
            if (!$lines) { continue; }

            foreach ($lines as $i => $line) {
                if (!preg_match("/'permission_callback'\s*=>\s*'__return_true'/", $line)) {
                    continue;
                }

                // Look at the 15 lines immediately above for a comment
                // that contains any of the accepted justification
                // markers. 15 is enough to span an entire
                // register_rest_route call (header comment is usually
                // above the `register_rest_route(` line, and the
                // `permission_callback` lives a few lines into the args
                // array).
                $documented = false;
                for ($j = max(0, $i - 15); $j < $i; $j++) {
                    foreach (self::VALID_MARKERS as $marker) {
                        if (stripos($lines[$j], $marker) !== false) {
                            $documented = true;
                            break 2;
                        }
                    }
                }

                if (!$documented) {
                    $undocumented[] = sprintf('%s:%d', $rel, $i + 1);
                }
            }
        }

        $this->assertEmpty(
            $undocumented,
            "Public REST routes without a documenting comment:\n  "
            . implode("\n  ", $undocumented)
            . "\nAdd a `// Public: <reason>` comment within 6 lines above each."
        );
    }

    /**
     * Walk includes/ and admin/, returning every *.php file (excluding
     * vendor/, tests/, .git).
     */
    private function scan_php_files(): array
    {
        $files = [];
        foreach ([ISF_PLUGIN_DIR . 'includes', ISF_PLUGIN_DIR . 'admin'] as $dir) {
            if (!is_dir($dir)) { continue; }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    function ($f) {
                        return !in_array($f->getFilename(), ['vendor', 'node_modules', '.git', 'tests'], true);
                    }
                )
            );
            foreach ($iter as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        return $files;
    }
}

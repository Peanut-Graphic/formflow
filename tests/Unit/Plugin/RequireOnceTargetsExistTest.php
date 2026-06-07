<?php
/**
 * RequireOnceTargetsExistTest — protects against the 3.3.0 bug class.
 *
 * Today's pain: 3.3.0 dead-code purge removed 17 files including
 * class-form-prediction.php and class-form-prediction-api.php. The class
 * names were grepped and confirmed unused — but TWO `require_once` calls
 * referenced the file paths directly (not the class names), so the grep
 * missed them. Site fatal'd on every page load with "require_once: failed
 * to open stream".
 *
 * Strategy: scan every active PHP file (formflow.php, includes/, admin/,
 * public/) for `require_once ISF_PLUGIN_DIR . '...'` calls, extract the
 * relative path, and assert the target file exists. Catches dead refs
 * to deleted files BEFORE they fatal in production.
 *
 * Does NOT scan tests/ (test fixtures may legitimately reference
 * imaginary files), connectors/ (per-connector autoload conventions),
 * or vendor/.
 */

namespace ISF\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;

final class RequireOnceTargetsExistTest extends TestCase
{
    public function test_all_require_once_targets_exist(): void
    {
        $root = ISF_PLUGIN_DIR;

        $scan_dirs = [
            $root,
            $root . 'includes',
            $root . 'admin',
            $root . 'public',
        ];

        $broken = [];
        foreach ($scan_dirs as $dir) {
            $files = $this->collect_php_files($dir);
            foreach ($files as $file) {
                $source = file_get_contents($file);
                // Match: require_once ISF_PLUGIN_DIR . 'foo/bar.php'
                preg_match_all(
                    "/require(?:_once)?\s*\(?\s*ISF_PLUGIN_DIR\s*\.\s*['\"]([^'\"]+\.php)['\"]/",
                    $source,
                    $matches
                );

                foreach ($matches[1] as $rel) {
                    $target = $root . $rel;
                    if (!file_exists($target)) {
                        $broken[] = sprintf(
                            '%s references missing file ISF_PLUGIN_DIR . "%s"',
                            str_replace($root, '', $file),
                            $rel
                        );
                    }
                }
            }
        }

        $this->assertEmpty(
            $broken,
            "Broken require_once targets (will fatal at runtime):\n  " . implode("\n  ", $broken)
        );
    }

    /**
     * Collect *.php files under $dir, excluding nested vendor + node_modules.
     */
    private function collect_php_files(string $dir): array
    {
        if (!is_dir($dir)) { return []; }
        $files = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                function ($current) {
                    $name = $current->getFilename();
                    if (in_array($name, ['vendor', 'node_modules', '.git', '.claude', 'tests'], true)) {
                        return false;
                    }
                    return true;
                }
            )
        );
        foreach ($iter as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $files[] = $f->getPathname();
            }
        }
        return $files;
    }
}

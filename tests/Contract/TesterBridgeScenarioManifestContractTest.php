<?php
/**
 * Net 7 (contract) — tester-bridge scenario-manifest shape.
 *
 * FormFlow mounts the shared tester-bridge and exposes
 *   GET/POST /wp-json/formflow/v1/tester/health
 * whose JSON body embeds a `scenarios` array. The external TESTER worker reads
 * that array to discover which behavioral scenarios it may drive against a
 * running preview app. THIS is the provider/consumer seam: the worker depends
 * on the manifest's exact shape (`[{slug, description}, ...]`, slug-sorted).
 *
 * The full WordPress REST harness does NOT boot in this repo's unit toolchain
 * (Brain Monkey only — no MySQL, no WP install, so WP_REST_Request /
 * WP_REST_Response are unavailable). Rather than stub WP's transport classes
 * and pin a hand-rolled shape, this contract pins the part of the health
 * response that is genuinely WordPress-free and load-bearing: the manifest
 * produced by TesterBridge::discover_scenarios(), which only reads the
 * tester/scenarios/*.ts provider files via the filesystem.
 *
 * If the parser drifts, or a scenario file's `slug:` declaration changes shape,
 * the worker's discovery breaks — this test fails first.
 *
 * (The HMAC request-canonicalization half of the bridge contract is pinned by
 * tests/Unit/TesterBridgeHmacVerifierTest.php and the property suite.)
 */

namespace ISF\Tests\Contract;

use PHPUnit\Framework\TestCase;
use ISF\TesterBridge;

require_once ISF_PLUGIN_DIR . 'includes/class-tester-bridge.php';

final class TesterBridgeScenarioManifestContractTest extends TestCase
{
    /**
     * Invoke the private provider that the health endpoint embeds. It only
     * touches the filesystem under ISF_PLUGIN_DIR . 'tester/scenarios' — no
     * WordPress, no DB, no network — so reflection is safe and deterministic.
     *
     * @return array<int,array<string,mixed>>
     */
    private function discoverScenarios(): array
    {
        // Note: no setAccessible() — since PHP 8.1, ReflectionMethod::invoke()
        // can call private methods directly, and setAccessible() is a deprecated
        // no-op on 8.5+ that would print a deprecation notice.
        $bridge = new TesterBridge();
        $ref = new \ReflectionMethod($bridge, 'discover_scenarios');

        return $ref->invoke($bridge);
    }

    /**
     * CONTRACT (entry shape): every manifest entry is an associative array with
     * EXACTLY the keys `slug` and `description`, both non-empty strings. The
     * worker indexes scenarios by `slug` and shows `description`; extra/missing
     * keys or non-string values break discovery.
     */
    public function test_every_manifest_entry_has_exactly_slug_and_description(): void
    {
        $manifest = $this->discoverScenarios();
        $this->assertNotEmpty($manifest, 'scenario manifest is unexpectedly empty');

        foreach ($manifest as $i => $entry) {
            $this->assertIsArray($entry, "entry #{$i} is not an array");
            $this->assertSame(
                ['slug', 'description'],
                array_keys($entry),
                "entry #{$i} must expose exactly [slug, description] in order"
            );
            $this->assertIsString($entry['slug']);
            $this->assertNotSame('', $entry['slug'], "entry #{$i} has empty slug");
            $this->assertIsString($entry['description']);
            $this->assertNotSame('', $entry['description'], "entry #{$i} has empty description");
        }
    }

    /**
     * CONTRACT (ordering): the manifest is sorted by slug ascending. The worker
     * relies on a stable order for deterministic run plans.
     */
    public function test_manifest_is_sorted_by_slug(): void
    {
        $slugs = array_column($this->discoverScenarios(), 'slug');
        $sorted = $slugs;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $slugs, 'scenario manifest is not slug-sorted');
    }

    /**
     * CONTRACT (slug namespace): every FormFlow scenario slug is namespaced
     * under `formflow.` so the aggregating worker can route results to the
     * right consumer.
     */
    public function test_all_slugs_are_namespaced_to_formflow(): void
    {
        foreach ($this->discoverScenarios() as $entry) {
            $this->assertStringStartsWith(
                'formflow.',
                $entry['slug'],
                "scenario slug '{$entry['slug']}' must be namespaced under 'formflow.'"
            );
        }
    }

    /**
     * CONTRACT (known set): the three shipped scenarios are present and
     * discoverable. Pins the provider files against silent removal/rename.
     */
    public function test_known_scenarios_are_discoverable(): void
    {
        $slugs = array_column($this->discoverScenarios(), 'slug');
        foreach ([
            'formflow.attribution-pixel',
            'formflow.form-submit',
            'formflow.license-check',
        ] as $expected) {
            $this->assertContains($expected, $slugs, "expected scenario '{$expected}' missing from manifest");
        }
    }
}

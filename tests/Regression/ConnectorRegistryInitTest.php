<?php
/**
 * Regression guard: the connector registry must actually be initialized.
 *
 * Both registries are first instantiated inside isf_init() on
 * plugins_loaded@10. Registering their initialization callbacks for an earlier
 * priority from their constructors is therefore invalid. These tests guard the
 * explicit, ordered initialization contract:
 *   1. init_connectors() fires the registration action and is idempotent.
 *   2. isf_init() explicitly calls init_connectors() after loading the
 *      bundled connectors — the call site whose absence was the bug.
 *   3. Neither registry reintroduces a late plugins_loaded self-hook.
 *
 * Self-contained: the WordPress hook functions are shimmed in the ISF\Api
 * namespace, so no booted WordPress or Brain Monkey is required.
 */

namespace ISF\Api;

if (!function_exists(__NAMESPACE__ . '\\add_action')) {
    function add_action($hook, $cb, $priority = 10, $args = 1)
    {
        return true;
    }
}

if (!function_exists(__NAMESPACE__ . '\\do_action')) {
    function do_action($hook, ...$args)
    {
        $GLOBALS['__isf_fired_actions'][] = $hook;
    }
}

if (!function_exists(__NAMESPACE__ . '\\apply_filters')) {
    function apply_filters($hook, $value, ...$args)
    {
        return $value;
    }
}

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use ISF\Api\ConnectorRegistry;

final class ConnectorRegistryInitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__isf_fired_actions'] = [];
        // Reset the singleton so each test gets a fresh, uninitialized registry.
        $ref = new \ReflectionProperty(ConnectorRegistry::class, 'instance');
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__isf_fired_actions']);
        parent::tearDown();
    }

    public function test_init_connectors_fires_the_registration_action(): void
    {
        ConnectorRegistry::instance()->init_connectors();

        $this->assertContains(
            'isf_register_connectors',
            $GLOBALS['__isf_fired_actions'],
            'init_connectors() must fire the isf_register_connectors action so loaders can register.'
        );
    }

    public function test_init_connectors_is_idempotent(): void
    {
        $registry = ConnectorRegistry::instance();
        $registry->init_connectors();
        $registry->init_connectors();

        $fired = array_filter(
            $GLOBALS['__isf_fired_actions'],
            static fn ($hook) => $hook === 'isf_register_connectors'
        );

        $this->assertCount(
            1,
            $fired,
            'init_connectors() must be guarded so a second call does not re-register connectors.'
        );
    }

    /**
     * The actual regression: isf_init() must explicitly initialize the
     * connector registry after loading the bundled connectors, exactly as it
     * does for the destination registry. Asserted at the source level because
     * isf_init() itself is not executable in isolation (it require_once's ~30
     * files and touches the database).
     */
    public function test_bootstrap_explicitly_initializes_the_connector_registry(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/formflow.php');
        $this->assertIsString($bootstrap);

        $loadPos = strpos($bootstrap, 'isf_load_bundled_connectors()');
        $initPos = strpos($bootstrap, 'ConnectorRegistry::instance()->init_connectors()');

        $this->assertNotFalse(
            $initPos,
            'isf_init() must call ConnectorRegistry::instance()->init_connectors() — without it the registry is never populated.'
        );
        $this->assertNotFalse($loadPos, 'isf_init() must load the bundled connectors.');
        $this->assertGreaterThan(
            $loadPos,
            $initPos,
            'init_connectors() must be called AFTER isf_load_bundled_connectors() so the loaders are registered on the action first.'
        );
    }

    public function test_registries_do_not_register_past_plugins_loaded_priorities(): void
    {
        $connectorSource = file_get_contents(dirname(__DIR__, 2) . '/includes/api/class-connector-registry.php');
        $destinationSource = file_get_contents(dirname(__DIR__, 2) . '/includes/destinations/class-destination-registry.php');

        $this->assertIsString($connectorSource);
        $this->assertIsString($destinationSource);
        $this->assertStringNotContainsString("add_action('plugins_loaded'", $connectorSource);
        $this->assertStringNotContainsString("add_action('plugins_loaded'", $destinationSource);
    }
}

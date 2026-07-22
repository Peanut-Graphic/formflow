<?php
/**
 * Connector Registry
 *
 * Central registry for API connectors. Manages registration, retrieval,
 * and lifecycle of all connector instances.
 *
 * @package FormFlow
 * @since 2.0.0
 */

namespace ISF\Api;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ConnectorRegistry
 */
class ConnectorRegistry {

    /**
     * Singleton instance
     */
    private static ?ConnectorRegistry $instance = null;

    /**
     * Registered connectors
     *
     * @var array<string, ApiConnectorInterface>
     */
    private array $connectors = [];

    /**
     * Connector metadata cache
     *
     * @var array
     */
    private array $metadata_cache = [];

    /**
     * Whether init_connectors() has already fired.
     *
     * @var bool
     */
    private bool $initialized = false;

    /**
     * Get singleton instance
     *
     * @return ConnectorRegistry
     */
    public static function instance(): ConnectorRegistry {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {
        // Allow plugins to register connectors early
        add_action('plugins_loaded', [$this, 'init_connectors'], 5);
    }

    /**
     * Initialize connector registration.
     *
     * Safe to call multiple times — guarded by $initialized. This mirrors
     * DestinationRegistry::init_destinations(): the plugins_loaded@5 self-hook
     * added in the constructor is unreliable because the singleton is first
     * instantiated inside isf_init() on plugins_loaded@10, by which point
     * priority 5 has already run — so isf_init() calls this method explicitly.
     */
    public function init_connectors(): void {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        /**
         * Action: Register API connectors
         *
         * External plugins should hook here to register their connectors.
         *
         * @param ConnectorRegistry $registry The registry instance
         *
         * @example
         * add_action('isf_register_connectors', function($registry) {
         *     $registry->register(new MyApiConnector());
         * });
         */
        do_action('isf_register_connectors', $this);

        /**
         * Filter: Modify registered connectors
         *
         * @param array $connectors Array of registered connectors
         * @return array Modified connectors array
         */
        $this->connectors = apply_filters('isf_registered_connectors', $this->connectors);
    }

    /**
     * Register a connector
     *
     * @param ApiConnectorInterface $connector The connector instance
     * @return bool True if registered successfully
     */
    public function register(ApiConnectorInterface $connector): bool {
        $id = $connector->get_id();

        if (empty($id)) {
            return false;
        }

        // Check for duplicate registration
        if (isset($this->connectors[$id])) {
            /**
             * Filter: Allow overriding existing connector
             *
             * @param bool $allow Whether to allow override
             * @param string $id Connector ID
             * @param ApiConnectorInterface $new_connector The new connector
             * @param ApiConnectorInterface $existing_connector The existing connector
             */
            $allow_override = apply_filters(
                'isf_allow_connector_override',
                false,
                $id,
                $connector,
                $this->connectors[$id]
            );

            if (!$allow_override) {
                return false;
            }
        }

        $this->connectors[$id] = $connector;

        // Clear metadata cache
        unset($this->metadata_cache[$id]);

        /**
         * Action: Connector registered
         *
         * @param string $id Connector ID
         * @param ApiConnectorInterface $connector The connector instance
         */
        do_action('isf_connector_registered', $id, $connector);

        return true;
    }

    /**
     * Unregister a connector
     *
     * @param string $id Connector ID
     * @return bool True if unregistered
     */
    public function unregister(string $id): bool {
        if (!isset($this->connectors[$id])) {
            return false;
        }

        $connector = $this->connectors[$id];
        unset($this->connectors[$id]);
        unset($this->metadata_cache[$id]);

        /**
         * Action: Connector unregistered
         *
         * @param string $id Connector ID
         * @param ApiConnectorInterface $connector The connector that was removed
         */
        do_action('isf_connector_unregistered', $id, $connector);

        return true;
    }

    /**
     * Get a connector by ID
     *
     * @param string $id Connector ID
     * @return ApiConnectorInterface|null The connector or null if not found
     */
    public function get(string $id): ?ApiConnectorInterface {
        return $this->connectors[$id] ?? null;
    }

    /**
     * Get all registered connectors
     *
     * @return array<string, ApiConnectorInterface>
     */
    public function get_all(): array {
        return $this->connectors;
    }

    /**
     * Check if a connector is registered
     *
     * @param string $id Connector ID
     * @return bool True if registered
     */
    public function has(string $id): bool {
        return isset($this->connectors[$id]);
    }

    /**
     * Get connector metadata for admin display
     *
     * @param string|null $id Specific connector ID, or null for all
     * @return array Connector metadata
     */
    public function get_metadata(?string $id = null): array {
        if ($id !== null) {
            return $this->get_single_metadata($id);
        }

        $all_metadata = [];
        foreach (array_keys($this->connectors) as $connector_id) {
            $all_metadata[$connector_id] = $this->get_single_metadata($connector_id);
        }

        return $all_metadata;
    }

    /**
     * Get metadata for a single connector
     *
     * @param string $id Connector ID
     * @return array Connector metadata
     */
    private function get_single_metadata(string $id): array {
        if (isset($this->metadata_cache[$id])) {
            return $this->metadata_cache[$id];
        }

        $connector = $this->get($id);
        if (!$connector) {
            return [];
        }

        $metadata = [
            'id' => $connector->get_id(),
            'name' => $connector->get_name(),
            'description' => $connector->get_description(),
            'version' => $connector->get_version(),
            'config_fields' => $connector->get_config_fields(),
            'supported_features' => $connector->get_supported_features(),
            'presets' => $connector->get_presets(),
        ];

        $this->metadata_cache[$id] = $metadata;

        return $metadata;
    }

    /**
     * Get connectors as options for select dropdown
     *
     * @return array Options array [id => name]
     */
    public function get_options(): array {
        $options = [];

        foreach ($this->connectors as $id => $connector) {
            $options[$id] = $connector->get_name();
        }

        return $options;
    }

    /**
     * Get all presets from all connectors
     *
     * @return array All presets with connector prefix
     */
    public function get_all_presets(): array {
        $all_presets = [];

        foreach ($this->connectors as $id => $connector) {
            $presets = $connector->get_presets();
            foreach ($presets as $preset_id => $preset) {
                $key = $id . '::' . $preset_id;
                $all_presets[$key] = array_merge($preset, [
                    'connector_id' => $id,
                    'preset_id' => $preset_id,
                ]);
            }
        }

        return $all_presets;
    }

    /**
     * Get connectors that support a specific feature
     *
     * @param string $feature Feature key
     * @return array<string, ApiConnectorInterface>
     */
    public function get_supporting(string $feature): array {
        $supporting = [];

        foreach ($this->connectors as $id => $connector) {
            if ($connector->supports($feature)) {
                $supporting[$id] = $connector;
            }
        }

        return $supporting;
    }

    /**
     * Get the default connector
     *
     * @return ApiConnectorInterface|null The first registered connector, or null
     */
    public function get_default(): ?ApiConnectorInterface {
        if (empty($this->connectors)) {
            return null;
        }

        /**
         * Filter: Get default connector ID
         *
         * @param string $default_id The default connector ID
         * @param array $connectors All registered connectors
         */
        $default_id = apply_filters(
            'isf_default_connector',
            array_key_first($this->connectors),
            $this->connectors
        );

        return $this->get($default_id);
    }

    /**
     * Get connector count
     *
     * @return int Number of registered connectors
     */
    public function count(): int {
        return count($this->connectors);
    }

    /**
     * Check if any connectors are registered
     *
     * @return bool True if at least one connector is registered
     */
    public function has_connectors(): bool {
        return !empty($this->connectors);
    }
}

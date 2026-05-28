<?php
/**
 * Destination Registry
 *
 * Central registry for submission destinations. Mirrors the structure of
 * ApiConnector\ConnectorRegistry but lives in its own namespace so the
 * two subsystems don't conflict.
 *
 * Plugins register destinations on the `isf_register_destinations` action:
 *
 *   add_action('isf_register_destinations', function ($registry) {
 *       $registry->register(new \ISF\Destinations\Sftp\SftpDestination());
 *   });
 *
 * @package FormFlow
 * @since 2.9.0
 */

namespace ISF\Destinations;

if (!defined('ABSPATH')) {
    exit;
}

class DestinationRegistry {

    private static ?DestinationRegistry $instance = null;

    /** @var array<string, DestinationInterface> */
    private array $destinations = [];

    /** @var array<string, array> */
    private array $metadata_cache = [];

    private bool $initialized = false;

    public static function instance(): DestinationRegistry {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init_destinations'], 5);
    }

    /**
     * Fire the registration action so destinations can attach themselves.
     *
     * Safe to call multiple times — guarded by $initialized.
     */
    public function init_destinations(): void {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        /**
         * Action: Register submission destinations.
         *
         * @param DestinationRegistry $registry
         *
         * @example
         * add_action('isf_register_destinations', function ($registry) {
         *     $registry->register(new MySftpDestination());
         * });
         */
        do_action('isf_register_destinations', $this);

        /**
         * Filter: Modify registered destinations.
         *
         * @param array<string, DestinationInterface> $destinations
         */
        $this->destinations = apply_filters('isf_registered_destinations', $this->destinations);
    }

    public function register(DestinationInterface $destination): bool {
        $id = $destination->get_id();
        if ($id === '') {
            return false;
        }

        if (isset($this->destinations[$id])) {
            /**
             * Filter: Allow overriding an existing destination registration.
             *
             * @param bool                 $allow
             * @param string               $id
             * @param DestinationInterface $new
             * @param DestinationInterface $existing
             */
            $allow = apply_filters(
                'isf_allow_destination_override',
                false,
                $id,
                $destination,
                $this->destinations[$id]
            );
            if (!$allow) {
                return false;
            }
        }

        $this->destinations[$id] = $destination;
        unset($this->metadata_cache[$id]);

        do_action('isf_destination_registered', $id, $destination);
        return true;
    }

    public function unregister(string $id): bool {
        if (!isset($this->destinations[$id])) {
            return false;
        }
        $dest = $this->destinations[$id];
        unset($this->destinations[$id], $this->metadata_cache[$id]);
        do_action('isf_destination_unregistered', $id, $dest);
        return true;
    }

    public function get(string $id): ?DestinationInterface {
        return $this->destinations[$id] ?? null;
    }

    /** @return array<string, DestinationInterface> */
    public function get_all(): array {
        return $this->destinations;
    }

    public function has(string $id): bool {
        return isset($this->destinations[$id]);
    }

    public function count(): int {
        return count($this->destinations);
    }

    /**
     * Get destinations as label-options for an admin select dropdown.
     *
     * @return array<string, string>
     */
    public function get_options(): array {
        $options = [];
        foreach ($this->destinations as $id => $dest) {
            $options[$id] = $dest->get_name();
        }
        return $options;
    }

    /**
     * Get destinations that support a specific feature.
     *
     * @return array<string, DestinationInterface>
     */
    public function get_supporting(string $feature): array {
        $matched = [];
        foreach ($this->destinations as $id => $dest) {
            if ($dest->supports($feature)) {
                $matched[$id] = $dest;
            }
        }
        return $matched;
    }

    /**
     * Metadata for admin display.
     *
     * @param string|null $id Specific destination ID, or null for all.
     */
    public function get_metadata(?string $id = null): array {
        if ($id !== null) {
            return $this->get_single_metadata($id);
        }

        $all = [];
        foreach (array_keys($this->destinations) as $did) {
            $all[$did] = $this->get_single_metadata($did);
        }
        return $all;
    }

    private function get_single_metadata(string $id): array {
        if (isset($this->metadata_cache[$id])) {
            return $this->metadata_cache[$id];
        }

        $dest = $this->get($id);
        if (!$dest) {
            return [];
        }

        $metadata = [
            'id' => $dest->get_id(),
            'name' => $dest->get_name(),
            'description' => $dest->get_description(),
            'version' => $dest->get_version(),
            'config_fields' => $dest->get_config_fields(),
            'supported_features' => $dest->get_supported_features(),
        ];

        $this->metadata_cache[$id] = $metadata;
        return $metadata;
    }
}

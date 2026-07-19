<?php
namespace ISF\Connectors\DominionPtr;

use ISF\Database\Database;

if (!defined('ABSPATH')) { exit; }

/**
 * Seeds the WordPress instance row that binds a form to the dominion-ptr
 * connector, from the preset in DominionPtrConnector::get_presets().
 *
 * Split into a pure builder (build_instance_row(), no DB — unit-tested) and
 * a thin DB wrapper (create_instance(), needs a real DB — not unit-tested).
 */
class Seeder {

    /**
     * Builds the instance row for the dominion_ptr preset. Pure: no DB.
     *
     * @return array
     */
    public static function build_instance_row(): array {
        $preset = (new DominionPtrConnector())->get_presets()['dominion_ptr'];

        $settings = [
            'connector' => 'dominion-ptr',
            'disable_device' => true,
            'disable_scheduling' => true,
            'branding' => $preset['branding'],
            'program' => [
                'name' => $preset['program_name'],
                'url' => $preset['program_url'],
            ],
            'analytics' => [
                'gtmEnabled'     => true,
                'gtmContainerId' => 'GTM-KG937MGX',
            ],
        ];

        return [
            'name' => $preset['name'],
            'slug' => 'dominion-ptr',
            'utility' => 'dominion',
            'form_type' => 'enrollment',
            'api_endpoint' => $preset['api_endpoint'],
            'settings' => $settings,
            'is_active' => 1,
            'test_mode' => 1,
        ];
    }

    /**
     * Inserts the instance row via the plugin's Database. Needs a real DB.
     *
     * @return int The new instance id.
     */
    public static function create_instance(): int {
        $id = Database::instance()->create_instance(self::build_instance_row());
        if ($id === false) {
            throw new \RuntimeException('Failed to create the Dominion PTR instance.');
        }
        return (int) $id;
    }
}

<?php
namespace ISF\Connectors\DominionPtr;

if (!defined('ABSPATH')) { exit; }

// The shared PowerPortal JSON connector carries all the logic; load it first
// regardless of connector glob order.
require_once dirname(__DIR__) . '/powerportal-json/class-powerportal-json-connector.php';

use ISF\Connectors\PowerportalJson\PowerportalJsonConnector;

/**
 * Dominion Peak Time Rebates connector.
 *
 * A thin specialization of the shared PowerPortal JSON connector: it fixes the
 * connector id (back-compat with the seeded `dominion_ptr` instance) and ships
 * the validated Dominion preset. All behaviour lives in the base class.
 *
 * Distinct from the legacy XML `intellisource` connector (Energy Wise) — that
 * connector is untouched.
 */
class DominionPtrConnector extends PowerportalJsonConnector {

    public function get_id(): string { return 'dominion-ptr'; }
    public function get_name(): string { return __('Dominion Peak Time Rebates', 'formflow'); }
    public function get_description(): string {
        return __('Dominion PTR enrollment via the IntelliSource JSON API.', 'formflow');
    }

    protected function demo_confirmation_prefix(): string { return 'PTR-DEMO-'; }

    public function get_presets(): array {
        return [
            'dominion_ptr' => [
                'name' => __('Dominion Energy — Peak Time Rebates', 'formflow'),
                'short_name' => 'Dominion PTR',
                'state' => 'VA',
                'api_endpoint' => 'https://www.dominionenergyptr.com/ptr/residential/api',
                'program_name' => 'Peak Time Rebates',
                'program_url' => 'https://www.dominionenergyptr.com',
                'connector' => 'dominion-ptr',
                'disable_device' => true,
                'disable_scheduling' => true,
                'branding' => ['primary_color' => '#0072ce', 'logo_url' => ''],
            ],
        ];
    }
}

<?php
/**
 * Real-WordPress REST contract test (net 7) for the public programs catalog route.
 *
 * Pins the REAL `GET /isf/v1/programs` route registered by
 * \ISF\Programs\ProgramManager::register_rest_routes(). The route is
 * `permission_callback => '__return_true'` (public by design — read-only,
 * non-PII program catalog the frontend pulls before sign-in), so it is the
 * stable, gettable surface to lock down.
 *
 * Documented response shape (see ProgramManager::rest_get_programs):
 *   200 => [ 'success' => true, 'programs' => array<...> ]
 *
 * This boots a real WordPress and dispatches through the real REST server —
 * NO mocks. If the route or shape regresses, this fails.
 */

namespace ISF\Tests\ContractWp;

use WP_UnitTestCase;
use WP_REST_Request;
use ISF\Programs\ProgramManager;

class ProgramsRouteContractTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();

        // ProgramManager registers its routes inside init() (hooked on
        // rest_api_init) and creates its tables on demand. The plugin's
        // production boot wires this elsewhere; here we initialize the real
        // service explicitly and ensure its (real) tables exist, then let
        // the REST server pick the route up below.
        $pm = ProgramManager::instance();
        $pm->maybe_create_tables();
        $pm->init();

        // Rebuild the REST server so the just-registered route is live.
        global $wp_rest_server;
        $wp_rest_server = null;
        do_action('rest_api_init');
    }

    public function test_programs_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/isf/v1/programs',
            $routes,
            'Public programs catalog route must be registered on a real WordPress.'
        );
    }

    public function test_get_programs_returns_documented_contract(): void {
        $request  = new WP_REST_Request('GET', '/isf/v1/programs');
        $response = rest_get_server()->dispatch($request);

        // Real status from the real callback.
        $this->assertSame(
            200,
            $response->get_status(),
            'Public programs catalog must return HTTP 200.'
        );

        $data = $response->get_data();

        // Documented response-shape keys.
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('programs', $data);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['programs']);
    }
}

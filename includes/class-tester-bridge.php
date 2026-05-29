<?php

namespace ISF;

use ISF\TesterBridge\HmacRequestVerifier;

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/tester-bridge/class-hmac-verifier.php';

/**
 * WordPress glue for the tester-bridge harness.
 *
 * Exposes a single REST endpoint — `/wp-json/formflow/v1/tester/health` —
 * that mirrors the Laravel side (Coffee Club, HUB, RFM) and the Next.js
 * side (BENCH). The TESTER worker probes this to confirm the consumer is
 * reachable + that HMAC signing matches.
 *
 * Dormant unless `TESTER_MODE` is true. All config comes from constants
 * (define them in wp-config.php) so the plugin's options table never sees
 * the shared secret:
 *
 *   define('TESTER_MODE', true);
 *   define('TESTER_SHARED_SECRET', '...32+ byte hex...');
 *   define('TESTER_ALLOWED_IPS', '203.0.113.4,198.51.100.7'); // optional
 */
class TesterBridge
{
    private const REST_NAMESPACE = 'formflow/v1';
    private const REST_ROUTE = '/tester/health';

    public function init(): void
    {
        if (! $this->mode_enabled()) {
            return;
        }

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        // Public: the route's auth happens inside handle_health() —
        // it runs $this->ip_check($request) + HMAC verification on the
        // request body. WP's permission_callback can't see the request
        // body, so we accept-all here and gate at the callback layer.
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods' => 'GET, POST',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_health'],
        ]);
    }

    public function handle_health(\WP_REST_Request $request): \WP_REST_Response
    {
        $denial = $this->ip_check($request);
        if ($denial !== null) {
            return $this->deny($denial);
        }

        $secret = $this->shared_secret();
        if ($secret === null) {
            return $this->deny('mode_off');
        }

        $verifier = new HmacRequestVerifier($secret);
        $result = $verifier->verify(
            $request->get_method(),
            self::canonical_path($request),
            (string) $request->get_body(),
            self::extract_signing_headers($request),
        );

        if (! $result->ok) {
            return $this->deny((string) $result->reason);
        }

        return new \WP_REST_Response([
            'ok' => true,
            'consumer' => 'formflow',
            'capabilities' => ['health', 'replay_defense' => false],
            'scenarios' => $this->discover_scenarios(),
        ], 200);
    }

    private function mode_enabled(): bool
    {
        return defined('TESTER_MODE') && filter_var(constant('TESTER_MODE'), FILTER_VALIDATE_BOOLEAN);
    }

    private function shared_secret(): ?string
    {
        if (! defined('TESTER_SHARED_SECRET')) {
            return null;
        }
        $secret = (string) constant('TESTER_SHARED_SECRET');

        return $secret === '' ? null : $secret;
    }

    private function ip_check(\WP_REST_Request $request): ?string
    {
        if (! defined('TESTER_ALLOWED_IPS')) {
            return null;
        }
        $allowed = array_filter(array_map('trim', explode(',', (string) constant('TESTER_ALLOWED_IPS'))));
        if (empty($allowed)) {
            return null;
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return in_array($remote, $allowed, true) ? null : 'ip';
    }

    private function deny(string $reason): \WP_REST_Response
    {
        return new \WP_REST_Response(['ok' => false, 'reason' => $reason], 401);
    }

    /**
     * Match the canonical-request path the TESTER worker signs: the request
     * URI without the query string, leading slash preserved.
     */
    private static function canonical_path(\WP_REST_Request $request): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? $request->get_route();
        $q = strpos($uri, '?');

        return $q === false ? $uri : substr($uri, 0, $q);
    }

    /**
     * @return array<string,string>
     */
    private static function extract_signing_headers(\WP_REST_Request $request): array
    {
        return [
            'X-Tester-Token' => (string) $request->get_header('x-tester-token'),
            'X-Tester-Signature' => (string) $request->get_header('x-tester-signature'),
            'X-Tester-Timestamp' => (string) $request->get_header('x-tester-timestamp'),
            'X-Tester-Nonce' => (string) $request->get_header('x-tester-nonce'),
        ];
    }

    /**
     * @return array<int,array{slug:string,description:string}>
     */
    private function discover_scenarios(): array
    {
        $dir = ISF_PLUGIN_DIR . 'tester/scenarios';
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.ts') ?: [] as $path) {
            $contents = (string) @file_get_contents($path);
            if (preg_match('/slug:\s*[\'"]([^\'"]+)[\'"]/', $contents, $m)) {
                $slug = $m[1];
                $desc = '';
                if (preg_match('/description:\s*[\'"]([^\'"]+)[\'"]/', $contents, $d)) {
                    $desc = $d[1];
                }
                $out[] = ['slug' => $slug, 'description' => $desc];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['slug'], $b['slug']));

        return $out;
    }
}

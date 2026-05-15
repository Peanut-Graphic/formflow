<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * PWA Handler
 *
 * Provides Progressive Web App functionality for enrollment forms.
 */


class PWAHandler {

    /**
     * Database instance
     */
    private Database\Database $db;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database\Database();
    }

    /**
     * Initialize PWA for an instance
     */
    public function init(array $instance): void {
        if (!FeatureManager::is_enabled($instance, 'pwa_support')) {
            return;
        }

        add_action('wp_head', fn() => $this->render_meta_tags($instance));
        add_action('wp_footer', fn() => $this->render_install_prompt($instance));
    }

    /**
     * Generate manifest.json content
     */
    public function get_manifest(array $instance): array {
        $config = FeatureManager::get_feature($instance, 'pwa_support');
        $content = $instance['settings']['content'] ?? [];

        return [
            'name' => $config['app_name'] ?? $content['program_name'] ?? 'EnergyWise Enrollment',
            'short_name' => $config['app_short_name'] ?? 'EnergyWise',
            'description' => 'Enroll in the EnergyWise Rewards energy savings program',
            'start_url' => $this->get_form_url($instance),
            'display' => 'standalone',
            'orientation' => 'portrait',
            'theme_color' => $config['theme_color'] ?? '#0073aa',
            'background_color' => $config['background_color'] ?? '#ffffff',
            'icons' => $this->get_icons($instance),
            'categories' => ['utilities', 'lifestyle'],
            'lang' => 'en-US',
            'dir' => 'ltr',
            'scope' => '/',
            'prefer_related_applications' => false,
        ];
    }

    /**
     * Get form URL for PWA start
     */
    private function get_form_url(array $instance): string {
        return add_query_arg('isf_pwa', '1', home_url('/'));
    }

    /**
     * Get PWA icons
     */
    private function get_icons(array $instance): array {
        $config = FeatureManager::get_feature($instance, 'pwa_support');
        $custom_icon = $config['icon_url'] ?? '';

        if ($custom_icon) {
            return [
                ['src' => $custom_icon, 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $custom_icon, 'sizes' => '512x512', 'type' => 'image/png'],
            ];
        }

        // Default icons from plugin
        $base_url = ISF_PLUGIN_URL . 'public/assets/images/';

        return [
            ['src' => $base_url . 'icon-72.png', 'sizes' => '72x72', 'type' => 'image/png'],
            ['src' => $base_url . 'icon-96.png', 'sizes' => '96x96', 'type' => 'image/png'],
            ['src' => $base_url . 'icon-128.png', 'sizes' => '128x128', 'type' => 'image/png'],
            ['src' => $base_url . 'icon-144.png', 'sizes' => '144x144', 'type' => 'image/png'],
            ['src' => $base_url . 'icon-152.png', 'sizes' => '152x152', 'type' => 'image/png'],
            ['src' => $base_url . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => $base_url . 'icon-384.png', 'sizes' => '384x384', 'type' => 'image/png'],
            ['src' => $base_url . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ];
    }

    /**
     * Render PWA meta tags
     */
    public function render_meta_tags(array $instance): void {
        $config = FeatureManager::get_feature($instance, 'pwa_support');
        $manifest_url = add_query_arg([
            'isf_manifest' => '1',
            'instance' => $instance['slug'],
        ], home_url('/'));

        ?>
        <!-- PWA Meta Tags -->
        <meta name="theme-color" content="<?php echo esc_attr($config['theme_color'] ?? '#0073aa'); ?>">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="<?php echo esc_attr($config['app_short_name'] ?? 'EnergyWise'); ?>">
        <link rel="manifest" href="<?php echo esc_url($manifest_url); ?>">
        <?php
    }

    /**
     * Generate service worker JavaScript
     */
    public function get_service_worker(array $instance): string {
        $config = FeatureManager::get_feature($instance, 'pwa_support');
        $cache_name = 'isf-cache-v' . ISF_VERSION;
        $offline_enabled = !empty($config['enable_offline']);

        $assets_to_cache = [
            ISF_PLUGIN_URL . 'public/assets/css/forms.css',
            ISF_PLUGIN_URL . 'public/assets/js/enrollment.js',
            ISF_PLUGIN_URL . 'public/assets/js/validation.js',
        ];

        return <<<JS
const CACHE_NAME = '{$cache_name}';
const OFFLINE_URL = '/offline.html';

const ASSETS_TO_CACHE = [
    '/',
    '/offline.html',
    '{$assets_to_cache[0]}',
    '{$assets_to_cache[1]}',
    '{$assets_to_cache[2]}'
];

// Install event - cache assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS_TO_CACHE);
        })
    );
    self.skipWaiting();
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name.startsWith('isf-cache-') && name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

// Fetch event - serve from cache, fall back to network
self.addEventListener('fetch', (event) => {
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Skip API calls - always go to network
    if (event.request.url.includes('/wp-admin/admin-ajax.php') ||
        event.request.url.includes('/wp-json/')) {
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cachedResponse) => {
            if (cachedResponse) {
                // Return cached version, but fetch update in background
                event.waitUntil(
                    fetch(event.request).then((response) => {
                        if (response && response.status === 200) {
                            caches.open(CACHE_NAME).then((cache) => {
                                cache.put(event.request, response);
                            });
                        }
                    }).catch(() => {})
                );
                return cachedResponse;
            }

            // Not in cache - fetch from network
            return fetch(event.request).then((response) => {
                // Cache successful responses
                if (response && response.status === 200) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            }).catch(() => {
                // Offline - return offline page for navigation requests
                if (event.request.mode === 'navigate') {
                    return caches.match(OFFLINE_URL);
                }
                return new Response('Offline', { status: 503 });
            });
        })
    );
});

// Background sync for form submissions
self.addEventListener('sync', (event) => {
    if (event.tag === 'isf-form-sync') {
        event.waitUntil(syncFormData());
    }
});

async function syncFormData() {
    const db = await openDB();
    const pendingSubmissions = await db.getAll('pending-submissions');

    for (const submission of pendingSubmissions) {
        try {
            const response = await fetch(submission.url, {
                method: 'POST',
                body: submission.data,
                headers: submission.headers
            });

            if (response.ok) {
                await db.delete('pending-submissions', submission.id);
            }
        } catch (error) {
            console.error('Sync failed for submission:', submission.id);
        }
    }
}

// IndexedDB helper
function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('isf-offline', 1);
        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('pending-submissions')) {
                db.createObjectStore('pending-submissions', { keyPath: 'id', autoIncrement: true });
            }
            if (!db.objectStoreNames.contains('form-drafts')) {
                db.createObjectStore('form-drafts', { keyPath: 'sessionId' });
            }
        };
    });
}

// Push notifications
self.addEventListener('push', (event) => {
    if (!event.data) return;

    const data = event.data.json();

    event.waitUntil(
        self.registration.showNotification(data.title || 'EnergyWise Rewards', {
            body: data.body || '',
            icon: data.icon || '/icon-192.png',
            badge: '/badge-72.png',
            data: data.url || '/',
            actions: data.actions || []
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data)
    );
});
JS;
    }

    /**
     * Render install prompt
     */
    public function render_install_prompt(array $instance): void {
        $config = FeatureManager::get_feature($instance, 'pwa_support');

        if (empty($config['show_install_prompt'])) {
            return;
        }

        ?>
        <div id="isf-pwa-install" class="isf-pwa-install" style="display: none;">
            <div class="isf-pwa-install-content">
                <div class="isf-pwa-install-icon">
                    <span class="dashicons dashicons-smartphone"></span>
                </div>
                <div class="isf-pwa-install-text">
                    <strong><?php esc_html_e('Add to Home Screen', 'formflow'); ?></strong>
                    <p><?php esc_html_e('Install this app for quick access and offline use.', 'formflow'); ?></p>
                </div>
                <div class="isf-pwa-install-actions">
                    <button type="button" class="button button-primary" id="isf-pwa-install-btn">
                        <?php esc_html_e('Install', 'formflow'); ?>
                    </button>
                    <button type="button" class="button" id="isf-pwa-dismiss-btn">
                        <?php esc_html_e('Not Now', 'formflow'); ?>
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function() {
            let deferredPrompt;
            const installBanner = document.getElementById('isf-pwa-install');
            const installBtn = document.getElementById('isf-pwa-install-btn');
            const dismissBtn = document.getElementById('isf-pwa-dismiss-btn');

            // Check if already installed or dismissed
            if (window.matchMedia('(display-mode: standalone)').matches ||
                localStorage.getItem('isf_pwa_dismissed')) {
                return;
            }

            // Register service worker
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('<?php echo esc_js(add_query_arg(['isf_sw' => '1', 'instance' => $instance['slug']], home_url('/'))); ?>')
                    .then(function(registration) {
                        console.log('ISF Service Worker registered:', registration.scope);
                    })
                    .catch(function(error) {
                        console.error('ISF Service Worker registration failed:', error);
                    });
            }

            // Capture install prompt
            window.addEventListener('beforeinstallprompt', function(e) {
                e.preventDefault();
                deferredPrompt = e;
                installBanner.style.display = 'flex';
            });

            // Install button click
            if (installBtn) {
                installBtn.addEventListener('click', async function() {
                    if (!deferredPrompt) return;

                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;

                    if (outcome === 'accepted') {
                        console.log('PWA installed');
                    }

                    deferredPrompt = null;
                    installBanner.style.display = 'none';
                });
            }

            // Dismiss button click
            if (dismissBtn) {
                dismissBtn.addEventListener('click', function() {
                    installBanner.style.display = 'none';
                    localStorage.setItem('isf_pwa_dismissed', 'true');
                });
            }

            // Hide after successful install
            window.addEventListener('appinstalled', function() {
                installBanner.style.display = 'none';
                deferredPrompt = null;
            });
        })();
        </script>

        <style>
        .isf-pwa-install {
            display: none;
            position: fixed;
            bottom: 20px;
            left: 20px;
            right: 20px;
            max-width: 400px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 16px;
            z-index: 9999;
            animation: slideUp 0.3s ease-out;
        }
        @keyframes slideUp {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .isf-pwa-install-content {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .isf-pwa-install-icon {
            width: 48px;
            height: 48px;
            background: #f0f6fc;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .isf-pwa-install-icon .dashicons {
            font-size: 24px;
            width: 24px;
            height: 24px;
            color: #0073aa;
        }
        .isf-pwa-install-text {
            flex: 1;
            min-width: 150px;
        }
        .isf-pwa-install-text strong {
            display: block;
            margin-bottom: 4px;
        }
        .isf-pwa-install-text p {
            margin: 0;
            font-size: 13px;
            color: #666;
        }
        .isf-pwa-install-actions {
            display: flex;
            gap: 8px;
        }
        @media (max-width: 480px) {
            .isf-pwa-install-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }
        </style>
        <?php
    }

    /**
     * Generate offline page HTML
     */
    public function get_offline_page(array $instance): string {
        $config = FeatureManager::get_feature($instance, 'pwa_support');
        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'EnergyWise Rewards';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - {$program_name}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #f5f5f5;
            padding: 20px;
        }
        .offline-container {
            text-align: center;
            max-width: 400px;
        }
        .offline-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 12px;
            color: #333;
        }
        p {
            color: #666;
            margin-bottom: 24px;
            line-height: 1.5;
        }
        .retry-btn {
            display: inline-block;
            padding: 12px 24px;
            background: #0073aa;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
        }
        .retry-btn:hover {
            background: #005a87;
        }
    </style>
</head>
<body>
    <div class="offline-container">
        <div class="offline-icon">📡</div>
        <h1>You're Offline</h1>
        <p>It looks like you've lost your internet connection. Please check your connection and try again.</p>
        <a href="/" class="retry-btn" onclick="window.location.reload(); return false;">Try Again</a>
    </div>
    <script>
        window.addEventListener('online', () => window.location.reload());
    </script>
</body>
</html>
HTML;
    }

    /**
     * Check if current request is from PWA
     */
    public static function is_pwa_request(): bool {
        return !empty($_GET['isf_pwa']) ||
               (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'document' &&
                isset($_SERVER['HTTP_SEC_FETCH_MODE']) && $_SERVER['HTTP_SEC_FETCH_MODE'] === 'navigate');
    }
}

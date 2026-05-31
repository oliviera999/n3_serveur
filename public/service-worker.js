/**
 * Service Worker pour PWA n³ IoT
 * Gère le cache offline et les notifications push
 */

const CACHE_NAME = 'n3-iot-v5.1.8';
const RUNTIME_CACHE = 'n3-iot-runtime';

// Assets à mettre en cache lors de l'installation (shell minimal)
const STATIC_ASSETS = [
    '/',
    '/aquaponie',
    '/aquaponie-description',
    '/aquaponie-control',
    '/assets/css/main.css',
    '/assets/css/theme-variables.css',
    '/assets/css/realtime-styles.css',
    '/assets/css/fontawesome.min.css',
    '/assets/webfonts/fa-solid-900.woff2',
    '/assets/js/jquery.min.js',
    '/assets/js/main.js',
    '/assets/js/toast-notifications.js',
    '/assets/js/realtime-updater.js',
    '/assets/js/pwa-init.js',
    '/manifest.json',
];

// URLs d'API à ne jamais cacher (toujours réseau)
const API_URLS = [
    '/api/realtime/',
    '/api/outputs/',
    '/post-data',
    '/heartbeat',
];

/**
 * Installation du Service Worker
 */
self.addEventListener('install', event => {
    console.log('[SW] Installing...');

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .catch(err => {
                console.error('[SW] Failed to cache assets:', err);
            })
    );

    self.skipWaiting();
});

/**
 * Activation du Service Worker
 */
self.addEventListener('activate', event => {
    console.log('[SW] Activating...');

    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames
                    .filter(name => name !== CACHE_NAME && name !== RUNTIME_CACHE)
                    .map(name => {
                        console.log('[SW] Deleting old cache:', name);
                        return caches.delete(name);
                    })
            );
        })
    );

    return self.clients.claim();
});

/**
 * Interception des requêtes réseau
 * Stratégie : Network First, Cache Fallback
 */
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    if (API_URLS.some(apiUrl => url.pathname.includes(apiUrl))) {
        event.respondWith(fetch(request));
        return;
    }

    if (request.method !== 'GET') {
        event.respondWith(fetch(request));
        return;
    }

    event.respondWith(
        fetch(request)
            .then(response => {
                const responseClone = response.clone();

                if (response.status === 200) {
                    caches.open(RUNTIME_CACHE)
                        .then(cache => cache.put(request, responseClone));
                }

                return response;
            })
            .catch(() => {
                return caches.match(request)
                    .then(cachedResponse => {
                        if (cachedResponse) {
                            console.log('[SW] Serving from cache:', request.url);
                            return cachedResponse;
                        }

                        return caches.match('/')
                            .then(fallback => fallback || new Response(
                                '<h1>Hors ligne</h1><p>Aucune connexion disponible.</p>',
                                { headers: { 'Content-Type': 'text/html' } }
                            ));
                    });
            })
    );
});

/**
 * Gestion des notifications push
 */
self.addEventListener('push', event => {
    console.log('[SW] Push notification received');

    let data = {};
    if (event.data) {
        data = event.data.json();
    }

    const title = data.title || 'n³ IoT Aquaponie';
    const options = {
        body: data.body || 'Nouvelle notification',
        icon: '/assets/icons/icon-192.png',
        badge: '/assets/icons/icon-72.png',
        vibrate: [200, 100, 200],
        tag: data.tag || 'general',
        requireInteraction: data.requireInteraction || false,
        data: {
            url: data.url || '/aquaponie',
            timestamp: Date.now()
        },
        actions: data.actions || [
            { action: 'open', title: 'Ouvrir' },
            { action: 'close', title: 'Fermer' }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

/**
 * Click sur notification
 */
self.addEventListener('notificationclick', event => {
    console.log('[SW] Notification clicked:', event.action);

    event.notification.close();

    if (event.action === 'close') {
        return;
    }

    const urlToOpen = event.notification.data.url || '/aquaponie';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(windowClients => {
                for (const client of windowClients) {
                    if (client.url.includes(urlToOpen) && 'focus' in client) {
                        return client.focus();
                    }
                }

                if (clients.openWindow) {
                    return clients.openWindow(urlToOpen);
                }
            })
    );
});

/**
 * Synchronisation en arrière-plan (quand connexion revient)
 */
self.addEventListener('sync', event => {
    console.log('[SW] Background sync:', event.tag);

    if (event.tag === 'sync-data') {
        event.waitUntil(syncDataWithServer());
    }
});

async function syncDataWithServer() {
    try {
        console.log('[SW] Syncing data with server...');

        const response = await fetch('/api/realtime/sensors/latest');

        if (response.ok) {
            const data = await response.json();
            console.log('[SW] Sync successful:', data);

            const clients = await self.clients.matchAll();
            clients.forEach(client => {
                client.postMessage({
                    type: 'SYNC_COMPLETE',
                    data: data
                });
            });
        }
    } catch (error) {
        console.error('[SW] Sync failed:', error);
    }
}

/**
 * Messages depuis l'application
 */
self.addEventListener('message', event => {
    console.log('[SW] Message received:', event.data);

    if (event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (event.data.type === 'CACHE_URLS') {
        event.waitUntil(
            caches.open(RUNTIME_CACHE)
                .then(cache => cache.addAll(event.data.urls))
        );
    }

    if (event.data.type === 'CLEAR_CACHE') {
        event.waitUntil(
            caches.keys().then(names => Promise.all(names.map(name => caches.delete(name))))
        );
    }
});

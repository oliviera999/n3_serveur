/**
 * Service Worker pour PWA FFP3 Aquaponie
 * Gère le cache offline et les notifications push
 */

const CACHE_NAME = 'ffp3-aqua-v1.0.0';
const RUNTIME_CACHE = 'ffp3-runtime';

// Assets à mettre en cache lors de l'installation
const STATIC_ASSETS = [
    '/ffp3/',
    '/ffp3/dashboard',
    '/ffp3/aquaponie',
    '/ffp3/aquaponie-description',
    '/ffp3/aquaponie-control',
    '/ffp3/aquamobile',
    '/ffp3/aquamobile-control',
    '/ffp3/assets/css/realtime-styles.css',
    '/ffp3/assets/js/toast-notifications.js',
    '/ffp3/assets/js/realtime-updater.js',
    '/ffp3/manifest.json',
    'https://code.highcharts.com/stock/highstock.js',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js'
];

// URLs d'API à ne jamais cacher (toujours réseau)
const API_URLS = [
    '/api/realtime/',
    '/api/outputs/',
    '/post-data'
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
    
    // Activer immédiatement
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
    
    // Prendre le contrôle immédiatement
    return self.clients.claim();
});

/**
 * Interception des requêtes réseau
 * Stratégie : Network First, Cache Fallback
 */
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);
    
    // Ne pas cacher les requêtes API
    if (API_URLS.some(apiUrl => url.pathname.includes(apiUrl))) {
        event.respondWith(fetch(request));
        return;
    }
    
    // Ne cacher que les GET requests
    if (request.method !== 'GET') {
        event.respondWith(fetch(request));
        return;
    }
    
    // Stratégie Network First avec fallback sur cache
    event.respondWith(
        fetch(request)
            .then(response => {
                // Cloner la réponse car elle ne peut être lue qu'une fois
                const responseClone = response.clone();
                
                // Mettre en cache si la réponse est valide
                if (response.status === 200) {
                    caches.open(RUNTIME_CACHE)
                        .then(cache => cache.put(request, responseClone));
                }
                
                return response;
            })
            .catch(() => {
                // En cas d'échec réseau, chercher dans le cache
                return caches.match(request)
                    .then(cachedResponse => {
                        if (cachedResponse) {
                            console.log('[SW] Serving from cache:', request.url);
                            return cachedResponse;
                        }
                        
                        // Page offline par défaut si rien dans le cache
                        return caches.match('/ffp3/')
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
    
    const title = data.title || 'FFP3 Aquaponie';
    const options = {
        body: data.body || 'Nouvelle notification',
        icon: '/ffp3/assets/icons/icon-192.png',
        badge: '/ffp3/assets/icons/icon-72.png',
        vibrate: [200, 100, 200],
        tag: data.tag || 'general',
        requireInteraction: data.requireInteraction || false,
        data: {
            url: data.url || '/ffp3/',
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
    
    // Ouvrir ou focus sur l'application
    const urlToOpen = event.notification.data.url || '/ffp3/';
    
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(windowClients => {
                // Chercher si une fenêtre est déjà ouverte
                for (const client of windowClients) {
                    if (client.url === urlToOpen && 'focus' in client) {
                        return client.focus();
                    }
                }
                
                // Sinon, ouvrir une nouvelle fenêtre
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

/**
 * Synchronise les données avec le serveur
 */
async function syncDataWithServer() {
    try {
        console.log('[SW] Syncing data with server...');
        
        // Récupérer les dernières données
        const response = await fetch('/ffp3/api/realtime/sensors/latest');
        
        if (response.ok) {
            const data = await response.json();
            console.log('[SW] Sync successful:', data);
            
            // Notifier les clients
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


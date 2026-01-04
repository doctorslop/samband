/**
 * Sambandscentralen - Service Worker
 * Ger offline-stöd och caching för bättre prestanda
 */

const CACHE_NAME = 'sambandscentralen-v3';

// Get the base path from service worker location (supports subdirectory deployment)
const BASE_PATH = self.location.pathname.replace(/\/sw\.js$/, '');
const OFFLINE_URL = BASE_PATH + '/offline.html';

// Resurser att cacha vid installation (relative to base path)
const STATIC_ASSETS = [
    BASE_PATH + '/',
    BASE_PATH + '/manifest.json',
    BASE_PATH + '/icons/favicon.ico',
    BASE_PATH + '/icons/favicon-16x16.png',
    BASE_PATH + '/icons/favicon-32x32.png',
    BASE_PATH + '/icons/apple-touch-icon.png',
    BASE_PATH + '/icons/android-chrome-512x512.png',
    'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
];

// Installera service worker och cacha statiska resurser
self.addEventListener('install', (event) => {
    console.log('[SW] Installing...');
    
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                console.log('[SW] Installed');
                return self.skipWaiting();
            })
            .catch((error) => {
                console.error('[SW] Installation failed:', error);
            })
    );
});

// Aktivera och rensa gamla cacher
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating...');
    
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((name) => name !== CACHE_NAME)
                        .map((name) => {
                            console.log('[SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => {
                console.log('[SW] Activated');
                return self.clients.claim();
            })
    );
});

// Hantera fetch-förfrågningar
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Ignorera icke-GET förfrågningar
    if (request.method !== 'GET') return;

    // Ignorera chrome-extension och andra protokoll
    if (!url.protocol.startsWith('http')) return;

    // Strategi: Network First för API-anrop
    if (url.pathname.includes('/api/') || url.searchParams.has('ajax')) {
        event.respondWith(networkFirst(request));
        return;
    }

    // Strategi: Cache First för statiska resurser
    if (isStaticAsset(url)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Strategi: Stale While Revalidate för HTML-sidor
    event.respondWith(staleWhileRevalidate(request));
});

// Network First - försök nätverket först, sedan cache
async function networkFirst(request) {
    try {
        const networkResponse = await fetch(request);
        
        // Cacha lyckad respons
        if (networkResponse.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, networkResponse.clone());
        }
        
        return networkResponse;
    } catch (error) {
        console.log('[SW] Network failed, trying cache:', request.url);
        
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        
        // Returnera offline-fallback för navigering
        if (request.mode === 'navigate') {
            return caches.match(OFFLINE_URL);
        }
        
        throw error;
    }
}

// Cache First - kolla cache först, sedan nätverket
async function cacheFirst(request) {
    const cachedResponse = await caches.match(request);
    
    if (cachedResponse) {
        return cachedResponse;
    }
    
    try {
        const networkResponse = await fetch(request);
        
        if (networkResponse.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, networkResponse.clone());
        }
        
        return networkResponse;
    } catch (error) {
        console.log('[SW] Cache first failed:', request.url);
        throw error;
    }
}

// Stale While Revalidate - returnera cache direkt och uppdatera i bakgrunden
async function staleWhileRevalidate(request) {
    const cachedResponse = await caches.match(request);
    
    const fetchPromise = fetch(request)
        .then((networkResponse) => {
            if (networkResponse.ok) {
                const cache = caches.open(CACHE_NAME);
                cache.then((c) => c.put(request, networkResponse.clone()));
            }
            return networkResponse;
        })
        .catch((error) => {
            console.log('[SW] Network error:', error);
            return cachedResponse;
        });
    
    return cachedResponse || fetchPromise;
}

// Kontrollera om URL är en statisk resurs
function isStaticAsset(url) {
    const staticExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.ico', '.svg', '.woff', '.woff2'];
    return staticExtensions.some((ext) => url.pathname.endsWith(ext)) ||
           url.hostname === 'fonts.googleapis.com' ||
           url.hostname === 'fonts.gstatic.com' ||
           url.hostname === 'unpkg.com';
}

// Background Sync för att synka data när online
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-events') {
        console.log('[SW] Background sync: sync-events');
        event.waitUntil(syncEvents());
    }
});

async function syncEvents() {
    // Synka eventuella lokalt sparade filter eller preferenser
    console.log('[SW] Syncing events...');
}

// Push-notifikationer (förberedelse för framtiden)
self.addEventListener('push', (event) => {
    if (!event.data) return;
    
    const data = event.data.json();
    
    const options = {
        body: data.body || 'Ny händelse rapporterad',
        icon: BASE_PATH + '/icons/android-chrome-512x512.png',
        badge: BASE_PATH + '/icons/favicon-32x32.png',
        vibrate: [100, 50, 100],
        data: {
            url: data.url || BASE_PATH + '/'
        },
        actions: [
            { action: 'open', title: 'Visa händelse' },
            { action: 'close', title: 'Stäng' }
        ]
    };
    
    event.waitUntil(
        self.registration.showNotification(data.title || 'Sambandscentralen', options)
    );
});

// Hantera notifikationsklick
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    
    if (event.action === 'close') return;

    const url = event.notification.data?.url || BASE_PATH + '/';
    
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                // Fokusera existerande fönster om det finns
                for (const client of clientList) {
                    if (client.url === url && 'focus' in client) {
                        return client.focus();
                    }
                }
                // Öppna nytt fönster
                if (clients.openWindow) {
                    return clients.openWindow(url);
                }
            })
    );
});

// Logga meddelanden från huvudtråden
self.addEventListener('message', (event) => {
    if (event.data === 'skipWaiting') {
        self.skipWaiting();
    }
    
    if (event.data === 'clearCache') {
        caches.delete(CACHE_NAME).then(() => {
            console.log('[SW] Cache cleared');
        });
    }
});

console.log('[SW] Service Worker loaded');

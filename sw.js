// Единая версия: bump при изменении статики — обновит и имя кэша, и URL ассетов
const ASSETS_VERSION = 'v6';
const CACHE_NAME = 'contest-cache-' + ASSETS_VERSION;
const STATIC_ASSETS = [
    '/assets/css/style.css?' + ASSETS_VERSION,
    '/assets/js/main.js?' + ASSETS_VERSION,
    '/assets/js/editor.js?' + ASSETS_VERSION,
    '/assets/css/editor.css?' + ASSETS_VERSION,
    '/assets/favicon-256x256.png',
    '/assets/favicon-48x48.png',
    '/assets/favicon-32x32.png',
    '/assets/favicon-16x16.png',
    '/assets/favicon-180x180.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames
                    .filter(name => name !== CACHE_NAME)
                    .map(name => caches.delete(name))
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;
    if (event.request.url.includes('/api/') || event.request.url.includes('/admin/')) return;

    const isNavigation = event.request.mode === 'navigate';
    const isStaticAsset = STATIC_ASSETS.some(path => event.request.url.includes(path));

    if (isNavigation) {
        event.respondWith(networkFirst(event.request));
    } else if (isStaticAsset) {
        event.respondWith(staleWhileRevalidate(event.request));
    }
});

async function networkFirst(request) {
    try {
        const response = await fetch(request);
        if (response && response.status === 200) {
            const clone = response.clone();
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, clone);
        }
        return response;
    } catch (error) {
        const cached = await caches.match(request);
        if (cached) return cached;
        throw error;
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    const fetchPromise = fetch(request).then(response => {
        if (response && response.status === 200) {
            const clone = response.clone();
            cache.put(request, clone);
        }
        return response;
    }).catch(() => cached);
    return cached || fetchPromise;
}

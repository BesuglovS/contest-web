const CACHE_NAME = 'contest-cache-v1';
const ASSETS_TO_CACHE = [
    '/index.php',
    '/assets/css/style.css?v=5',
    '/assets/js/main.js',
    '/assets/js/editor.js',
    '/assets/css/editor.css',
    '/assets/favicon-256x256.png',
    '/assets/favicon-48x48.png',
    '/assets/favicon-32x32.png',
    '/assets/favicon-16x16.png',
    '/assets/favicon-180x180.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(ASSETS_TO_CACHE))
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

    event.respondWith(
        caches.match(event.request).then(cached => {
            const fetchPromise = fetch(event.request).then(response => {
                if (!response || response.status !== 200 || response.type !== 'basic') {
                    return response;
                }
                const responseClone = response.clone();
                caches.open(CACHE_NAME).then(cache => {
                    cache.put(event.request, responseClone);
                });
                return response;
            }).catch(() => cached);
            return cached || fetchPromise;
        })
    );
});
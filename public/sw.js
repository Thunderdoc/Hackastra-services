/* Hackstra Academy service worker — app-shell offline support.
 * Versioned cache: bump CACHE when the shell changes.
 * Rules: navigations are network-first with an offline fallback;
 * same-origin static assets are cache-first; API calls, POSTs and
 * any request carrying an Authorization header are NEVER cached, so
 * authenticated HTML and API responses can never leak across users.
 */
const CACHE = 'hackstra-v1';
const SHELL = ['/offline.html', '/manifest.webmanifest'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(SHELL))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return /\.(css|js|png|jpg|jpeg|svg|ico|woff|woff2|ttf)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    if (req.headers.has('Authorization')) return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req)
                .then((res) => res)
                .catch(() => caches.match('/offline.html'))
        );
        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(req).then((cached) => cached || fetch(req).then((res) => {
                const copy = res.clone();
                caches.open(CACHE).then((cache) => cache.put(req, copy));
                return res;
            }))
        );
    }
});

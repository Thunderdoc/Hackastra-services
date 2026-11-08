/* Hackstra Academy service worker — app-shell offline support.
 * Versioned cache: bump CACHE when the shell changes.
 * Rules: navigations are network-first with an offline fallback;
 * same-origin static assets are cache-first; API calls, POSTs and
 * any request carrying an Authorization header are NEVER cached, so
 * authenticated HTML and API responses can never leak across users.
 *
 * Item 16 audit (owner, 2026-11-07 late — repeated 419s from stale
 * pages): navigation responses are never written to any cache here
 * (network-first, cache only as the offline fallback page), and a
 * static asset is cached ONLY when the network answered 200 — an
 * error response (e.g. an HTML 500 page served under an asset URL
 * during an outage) must never be stored and then served stale
 * forever. The v2 -> v3 bump purges the previous static cache on
 * activate; the bfcache half of the staleness family is handled by
 * the pageshow guard in hackstra-ux.js, which no SW rule can reach.
 */
const CACHE = 'hackstra-v3';
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
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(CACHE).then((cache) => cache.put(req, copy));
                }
                return res;
            }))
        );
    }
});

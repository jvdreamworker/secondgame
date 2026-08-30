/**
 * service-worker.js — caches the app shell (HTML/CSS/JS/icons) so the app
 * loads with no connection at all. Actual data lives in IndexedDB (idb.js),
 * not here — this only makes sure the app itself opens offline.
 *
 * Bump CACHE_VERSION whenever you deploy changed static assets so clients
 * pick up the new files instead of serving stale ones from cache.
 */
const CACHE_VERSION = "pool-shell-v5";
const SHELL_FILES = [
  "/pool",
  "/css/pool.css",
  "/js/idb.js",
  "/js/api-sync.js",
  "/js/pool-app.js",
  "/manifest.json",
  "/icons/pool-192.png",
  "/icons/pool-512.png",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => cache.addAll(SHELL_FILES))
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  const { request } = event;

  // Never cache API calls — those go through the online-only sync queue
  // and must always hit the network (or fail fast so the queue retries).
  if (request.url.includes("/api/")) return;

  // Cache-first for the app shell; fall back to network, then to the
  // cached shell page itself if totally offline on first visit to a route.
  // ignoreSearch so cache-busting query strings (?v=<mtime>) still match the
  // precached shell files.
  event.respondWith(
    caches.match(request, { ignoreSearch: true }).then((cached) => {
      if (cached) return cached;
      return fetch(request)
        .then((res) => {
          if (res.ok && request.method === "GET") {
            const copy = res.clone();
            caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));
          }
          return res;
        })
        .catch(() => caches.match("/pool"));
    })
  );
});

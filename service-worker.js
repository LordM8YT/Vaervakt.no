/* Værvakt PWA – Service Worker for offline caching */

const CACHE_VERSION = 'vaervakt-pwa-v11';
const PRECACHE_URLS = [
  '/',
  '/index.php',
  '/manifest.json',
  '/service-worker.js',
  '/icons/vaervakt-icon.svg',
  '/assets/css/tailwind.css',
  '/assets/css/app.css',
  '/assets/js/app-core.js',
  '/assets/js/pwa-push.js',
  '/assets/js/map.js',
  '/assets/vendor/lucide/lucide.min.js',
  '/assets/vendor/leaflet/leaflet.css',
  '/assets/vendor/leaflet/leaflet.js',
  '/assets/vendor/leaflet.markercluster/MarkerCluster.css',
  '/assets/vendor/leaflet.markercluster/MarkerCluster.Default.css',
  '/assets/vendor/leaflet.markercluster/leaflet.markercluster.js'
];

const API_CACHE = 'vaervakt-api-v5';
const STATIC_CACHE = 'vaervakt-static-v5';
const CACHEABLE_APIS = ['api.met.no'];

// Installering og caching av kjernefiler
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => {
      return cache.addAll(PRECACHE_URLS).catch(e => console.warn('[SW] Precache feilet:', e));
    }).then(() => self.skipWaiting())
  );
});

// Aktivering og opprydding av gammel cache
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => ![CACHE_VERSION, API_CACHE, STATIC_CACHE].includes(k))
          .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// Cache management for MET.no og statiske ressurser
self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);

  if (request.mode === 'navigate' && url.origin === self.location.origin) {
    event.respondWith(
      fetch(request)
        .then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE_VERSION).then((c) => c.put(request, copy));
          }
          return res;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match('/index.php')))
    );
    return;
  }

  if (url.origin === self.location.origin && url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(STATIC_CACHE).then((c) => c.put(request, copy));
          }
          return res;
        });
      })
    );
    return;
  }

  if (CACHEABLE_APIS.some(api => url.hostname.includes(api))) {
    event.respondWith(
      caches.match(request).then((cached) => {
        const fetchPromise = fetch(request).then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(API_CACHE).then((c) => c.put(request, copy));
          }
          return res;
        });
        return cached || fetchPromise;
      })
    );
  }
});

// Push notification handler
self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    try { data = { body: event.data.text() }; } catch (e2) { data = {}; }
  }
  const title = data.title || 'Værvakt';
  const options = {
    body: data.body || 'Oppdatering fra Værvakt',
    icon: '/icons/vaervakt-icon.svg',
    badge: '/icons/vaervakt-icon.svg',
    data: { url: data.url || '/' }
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/';
  event.waitUntil(clients.openWindow(url));
});

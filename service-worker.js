const CACHE_NAME = 'vaervakt-2026-v18';
const ASSET_VERSION = '20260611-ui2';
const PRECACHE_URLS = [
  './',
  './index.html',
  `./tailwind.css?v=${ASSET_VERSION}`,
  `./styles.css?v=${ASSET_VERSION}`,
  `./vendor/leaflet/leaflet.css?v=${ASSET_VERSION}`,
  `./vendor/leaflet/leaflet.js?v=${ASSET_VERSION}`,
  `./app.js?v=${ASSET_VERSION}`,
  `./script.js?v=${ASSET_VERSION}`,
  './manifest.json',
  './icons/vaervakt-icon.svg',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  const url = new URL(event.request.url);
  if (url.pathname.startsWith('/api/')) return;
  if (url.pathname.startsWith('/admin')) return;

  event.respondWith(
    fetch(event.request).then((response) => {
      if (response.ok && url.origin === self.location.origin) {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
      }

      return response;
    }).catch(() => caches.match(event.request).then((cached) => {
      if (cached) return cached;
      if (event.request.mode === 'navigate') return caches.match('./index.html');
      return Response.error();
    }))
  );
});

self.addEventListener('push', (event) => {
  let payload = {};

  try {
    payload = event.data ? event.data.json() : {};
  } catch (error) {
    payload = { body: event.data ? event.data.text() : '' };
  }

  const title = payload.title || 'Værvakt 2026';
  const options = {
    body: payload.body || 'Ny oppdatering fra Værvakt.',
    icon: './icons/vaervakt-icon.svg',
    badge: './icons/vaervakt-icon.svg',
    data: { url: payload.url || './' },
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || './';
  event.waitUntil(self.clients.openWindow(url));
});

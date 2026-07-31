const CACHE_NAME = "vaervakt-svelte-shell-v5";
const CORE_ASSETS = [
  "/",
  "/lokalt/",
  "/bad/",
  "/manifest.json",
  "/weather.png",
  "/weather.ico",
  "/static/js/met-alert-banner.js",
];

async function cacheCoreAssets() {
  const cache = await caches.open(CACHE_NAME);
  const assets = [...CORE_ASSETS];

  try {
    const response = await fetch("/asset-manifest.json", { cache: "no-store" });
    if (response.ok) {
      const manifest = await response.json();
      assets.push(...Object.values(manifest.files || {}));
    }
  } catch {
    // Kjernen er fortsatt nok til å starte appen på nett.
  }

  await Promise.allSettled(
    [...new Set(assets)].map((asset) => cache.add(new Request(asset, { cache: "reload" })))
  );
}

self.addEventListener("install", (event) => {
  event.waitUntil(cacheCoreAssets().then(() => self.skipWaiting()));
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.map((key) => (key === CACHE_NAME ? null : caches.delete(key)))))
      .then(() => self.clients.claim())
  );
});

async function networkFirst(request, fallback = "/") {
  const cache = await caches.open(CACHE_NAME);

  try {
    const response = await fetch(request, { cache: "no-store" });
    if (response.ok && new URL(request.url).origin === self.location.origin) {
      await cache.put(request, response.clone());
    }
    return response;
  } catch {
    return (await cache.match(request)) || (await cache.match(fallback));
  }
}

async function cacheFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);
  if (cached) return cached;

  const response = await fetch(request);
  if (response.ok && new URL(request.url).origin === self.location.origin) {
    await cache.put(request, response.clone());
  }
  return response;
}

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith("/api/") || url.pathname.startsWith("/admin/")) return;

  const isShellAsset =
    request.mode === "navigate" ||
    request.destination === "script" ||
    request.destination === "style" ||
    url.pathname === "/manifest.json" ||
    url.pathname === "/asset-manifest.json";

  if (isShellAsset) {
    event.respondWith(networkFirst(request));
    return;
  }

  event.respondWith(cacheFirst(request));
});

self.addEventListener("message", (event) => {
  if (event.data?.type === "SKIP_WAITING") self.skipWaiting();
});

self.addEventListener("push", (event) => {
  const fallback = {
    title: "Værvakt",
    body: "Ny oppdatering fra Værvakt.",
    url: "/",
  };
  let payload = fallback;

  try {
    payload = event.data ? { ...fallback, ...event.data.json() } : fallback;
  } catch {
    payload = { ...fallback, body: event.data ? event.data.text() : fallback.body };
  }

  event.waitUntil(
    self.registration.showNotification(payload.title || fallback.title, {
      body: payload.body || fallback.body,
      icon: "/weather.png",
      badge: "/weather.png",
      data: { url: payload.url || "/" },
    })
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl = new URL(event.notification.data?.url || "/", self.location.origin).href;

  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clients) => {
      const existing = clients.find((client) => client.url === targetUrl);
      if (existing) return existing.focus();
      return self.clients.openWindow(targetUrl);
    })
  );
});

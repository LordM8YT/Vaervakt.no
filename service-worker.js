const CACHE_NAME = "vaervakt-svelte-shell-v2";
const CORE_ASSETS = [
  "/",
  "/lokalt/",
  "/bad/",
  "/manifest.json",
  "/weather.png",
  "/weather.ico",
  "/static/js/met-alert-banner.js",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(CACHE_NAME);
      const assets = [...CORE_ASSETS];

      try {
        const response = await fetch("/asset-manifest.json", { cache: "no-store" });
        const manifest = await response.json();
        assets.push(...Object.values(manifest.files || {}));
      } catch {
        // Kjernen er fortsatt nok til å starte appen på nett.
      }

      await Promise.allSettled(
        [...new Set(assets)].map((asset) =>
          cache.add(new Request(asset, { cache: "reload" }))
        )
      );

      await self.skipWaiting();
    })()
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
      )
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.pathname.startsWith("/api/") || url.pathname.startsWith("/admin/")) return;

  const isNavigation = request.mode === "navigate";
  const isAppAsset =
    url.origin === self.location.origin &&
    (url.pathname.endsWith(".html") ||
      url.pathname.endsWith(".js") ||
      url.pathname.endsWith(".css") ||
      url.pathname === "/asset-manifest.json" ||
      url.pathname === "/manifest.json");

  if (isNavigation || isAppAsset) {
    event.respondWith(
      fetch(new Request(request, { cache: "no-store" }))
        .then((response) => {
          if (response.ok && url.origin === self.location.origin) {
            caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone()));
          }
          return response;
        })
        .catch(() =>
          caches.match(request).then((cached) => cached || (isNavigation ? caches.match("/") : undefined))
        )
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(
      (cached) =>
        cached ||
        fetch(request).then((response) => {
          if (response.ok && url.origin === self.location.origin) {
            caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone()));
          }
          return response;
        })
    )
  );
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

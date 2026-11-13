const CACHE_NAME = "foodbyk-pwa-v1";
const APP_SHELL = ["/src/index.html", "/manifest.webmanifest", "/images/pwa-icon.svg"];

self.addEventListener("install", (event) => {
    event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(
        keys.filter((key) => key.startsWith("foodbyk-pwa-") && key !== CACHE_NAME).map((key) => caches.delete(key))
    )).then(() => self.clients.claim()));
});

self.addEventListener("fetch", (event) => {
    const request = event.request;
    if (request.method !== "GET" || new URL(request.url).origin !== self.location.origin) return;
    event.respondWith(fetch(request).then((response) => {
        if (response.ok && request.destination !== "document") {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        }
        return response;
    }).catch(async () => (await caches.match(request)) || (request.mode === "navigate" ? caches.match("/src/index.html") : Response.error())));
});

self.addEventListener("push", (event) => {
    let payload = {};
    try { payload = event.data?.json() || {}; } catch (_) {}
    const title = payload.title || "Food by K";
    const options = {
        body: payload.body || "You have a new update.",
        icon: "/images/pwa-icon.svg",
        badge: "/images/pwa-icon.svg",
        tag: payload.tag || "foodbyk-update",
        renotify: Boolean(payload.renotify),
        requireInteraction: Boolean(payload.requireInteraction),
        data: { url: payload.url || "/pages/customer/orders.html", orderId: payload.orderId || null }
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || "/src/index.html", self.location.origin).href;
    event.waitUntil(self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clients) => {
        const staffUrl = new URL(url).pathname.includes("/admin/");
        const existing = clients.find((client) => new URL(client.url).origin === self.location.origin
            && (!staffUrl || new URL(client.url).pathname.includes("/admin/")));
        if (existing) return existing.navigate(url).then(() => existing.focus());
        return self.clients.openWindow(url);
    }));
});

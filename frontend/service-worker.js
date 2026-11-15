const CACHE_NAME = "foodbyk-pwa-v2";
const IMAGE_CACHE_NAME = "foodbyk-product-images-v1";
const APP_SHELL = ["/src/index.html", "/manifest.webmanifest", "/images/pwa-icon.svg"];

const isSignedS3Image = (url) => {
    const host = url.hostname.toLowerCase();
    return url.protocol === "https:" && (
        host.endsWith(".amazonaws.com") || host === "amazonaws.com" ||
        host.endsWith(".storage.googleapis.com") || host === "storage.googleapis.com" ||
        host.endsWith(".digitaloceanspaces.com") || host.endsWith(".r2.cloudflarestorage.com") ||
        url.searchParams.has("X-Amz-Signature") || url.searchParams.has("x-amz-signature") ||
        url.searchParams.has("X-Goog-Signature") || url.searchParams.has("x-goog-signature")
    );
};

const canonicalImageRequest = (url) => new Request(`${url.origin}${url.pathname}`, {
    method: "GET",
    mode: "cors",
    credentials: "omit",
});

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
    if (request.method !== "GET") return;
    const requestUrl = new URL(request.url);
    if (requestUrl.origin !== self.location.origin) {
        if (request.destination !== "image" || !isSignedS3Image(requestUrl)) return;
        const cacheKey = canonicalImageRequest(requestUrl);
        event.respondWith((async () => {
            const cache = await caches.open(IMAGE_CACHE_NAME);
            const cached = await cache.match(cacheKey);
            const refresh = fetch(request).then((response) => {
                if (response.ok || response.type === "opaque") {
                    return cache.put(cacheKey, response.clone()).then(() => response);
                }
                return response;
            });
            if (cached) {
                event.waitUntil(refresh.catch(() => {}));
                return cached;
            }
            return refresh.catch(async () => (await cache.match(cacheKey)) || Response.error());
        })());
        return;
    }
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
        silent: false,
        vibrate: [180, 90, 180],
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

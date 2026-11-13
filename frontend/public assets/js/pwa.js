(() => {
    const rootPath = new URL("/", window.location.href).pathname;
    let installPrompt = null;
    const INSTALL_DISMISSED = "foodbyk-install-dismissed-until";

    function showNotice(message, type = "info", duration = 6500) {
        if (!message) return;
        let host = document.getElementById("foodbykNoticeHost");
        if (!host) {
            host = document.createElement("div");
            host.id = "foodbykNoticeHost";
            host.className = "foodbyk-notice-host";
            host.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
            host.setAttribute("aria-relevant", "additions text");
            document.body.appendChild(host);
        }
        const notice = document.createElement("div");
        notice.className = `foodbyk-notice is-${type}`;
        notice.setAttribute("role", type === "error" ? "alert" : "status");
        const text = document.createElement("span"); text.textContent = message;
        const close = document.createElement("button"); close.type = "button"; close.className = "foodbyk-notice-close";
        close.setAttribute("aria-label", "Dismiss message"); close.textContent = "×";
        close.addEventListener("click", () => notice.remove());
        notice.append(text, close); host.appendChild(notice);
        window.clearTimeout(notice.hideTimer);
        if (duration > 0) notice.hideTimer = window.setTimeout(() => notice.remove(), duration);
    }

    window.foodByKShowNotice = showNotice;
    window.foodByKNotify = showNotice;
    (window.foodByKNoticeQueue || []).splice(0).forEach((notice) => {
        showNotice(notice.message, notice.type, notice.duration);
    });
    const initializePwa = () => {
        const style = document.createElement("style");
        style.textContent = `
            .foodbyk-notice-host{position:fixed;z-index:10000;top:max(14px,env(safe-area-inset-top));left:50%;transform:translateX(-50%);width:min(560px,calc(100vw - 28px));display:grid;gap:10px;pointer-events:none}
            .foodbyk-notice{pointer-events:auto;display:flex;align-items:center;justify-content:space-between;gap:18px;padding:15px 18px;border-radius:14px;background:#fff;color:#201b16;box-shadow:0 12px 44px #0003;border-left:5px solid #e8a817;font:600 14px/1.45 system-ui,sans-serif;animation:foodbyk-notice-in .18s ease-out}
            .foodbyk-notice.is-success{border-color:#16864a}.foodbyk-notice.is-error{border-color:#cf342a}.foodbyk-notice-close{border:0;background:transparent;color:inherit;font-size:23px;line-height:1;cursor:pointer}
            @keyframes foodbyk-notice-in{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
            .foodbyk-install-prompt{position:fixed;z-index:9998;left:50%;bottom:max(16px,env(safe-area-inset-bottom));transform:translateX(-50%);width:min(520px,calc(100vw - 28px));display:flex;align-items:center;gap:14px;padding:15px;border-radius:16px;background:#181614;color:#fff;box-shadow:0 12px 40px #0004;font:500 14px/1.4 system-ui,sans-serif}
            .foodbyk-install-prompt p{margin:0;flex:1}.foodbyk-install-prompt button{border:0;border-radius:9px;padding:10px 13px;font:700 13px system-ui,sans-serif;cursor:pointer}.foodbyk-install-action{background:#f8bd31;color:#181614}.foodbyk-install-dismiss{background:#393633;color:#fff}
            .staff-order-alert{position:fixed;z-index:9999;top:20px;left:50%;transform:translateX(-50%);display:flex;gap:16px;align-items:center;padding:15px 18px;border-radius:14px;background:#171512;color:white;box-shadow:0 12px 42px #0004;font:600 14px system-ui,sans-serif}.staff-order-alert a{color:#f8bd31;white-space:nowrap}
            @media(max-width:520px){.foodbyk-notice-host{top:max(8px,env(safe-area-inset-top))}.foodbyk-notice{padding:13px;font-size:13px}.foodbyk-install-prompt{flex-wrap:wrap}.foodbyk-install-prompt p{flex-basis:100%}.staff-order-alert{width:calc(100vw - 24px);font-size:13px}}
            @media(prefers-reduced-motion:reduce){.foodbyk-notice{animation:none}}
        `;
        document.head.appendChild(style);
        if ("serviceWorker" in navigator && window.isSecureContext) {
            navigator.serviceWorker.register(`${rootPath}service-worker.js`, { scope: rootPath }).catch((error) => console.warn("Food by K offline app setup failed.", error));
        }
        const apiScript = document.querySelector('script[src*="api.js"]');
        const siteRoot = apiScript?.src ? new URL("/", apiScript.src).href : `${window.location.origin}/`;
        if (!document.querySelector('link[rel="manifest"]')) {
            const manifest = document.createElement("link"); manifest.rel = "manifest"; manifest.href = new URL("manifest.webmanifest", siteRoot).href; document.head.appendChild(manifest);
        }
        window.dispatchEvent(new CustomEvent("foodbyk:pwa-ready"));
        showInstallPrompt();
    };
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initializePwa, { once: true });
    else initializePwa();

    window.addEventListener("beforeinstallprompt", (event) => {
        event.preventDefault();
        installPrompt = event;
        if (document.readyState !== "loading") showInstallPrompt();
    });
    window.addEventListener("appinstalled", () => {
        installPrompt = null;
        document.querySelector(".foodbyk-install-prompt")?.remove();
        showNotice("Food by K has been added to your device.", "success");
    });

    function showInstallPrompt() {
        if (document.querySelector(".foodbyk-install-prompt") || window.matchMedia("(display-mode: standalone)").matches) return;
        let dismissedUntil = 0;
        try { dismissedUntil = Number(localStorage.getItem(INSTALL_DISMISSED) || 0); } catch (_) {}
        if (dismissedUntil > Date.now()) return;
        const prompt = document.createElement("aside"); prompt.className = "foodbyk-install-prompt"; prompt.setAttribute("aria-label", "Install Food by K app");
        const message = document.createElement("p"); message.textContent = "Add Food by K to your home screen for quick access and order notifications.";
        const install = document.createElement("button"); install.type = "button"; install.className = "foodbyk-install-action"; install.textContent = "Install app";
        const notifications = document.createElement("button"); notifications.type = "button"; notifications.className = "foodbyk-install-action"; notifications.textContent = "Enable notifications";
        const dismiss = document.createElement("button"); dismiss.type = "button"; dismiss.className = "foodbyk-install-dismiss"; dismiss.textContent = "Later";
        install.addEventListener("click", async () => {
            if (installPrompt) {
                const promptEvent = installPrompt; installPrompt = null; prompt.remove();
                await promptEvent.prompt(); await promptEvent.userChoice;
                return;
            }
            const isAppleMobile = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
            showNotice(isAppleMobile
                ? "To install, tap Share in Safari, then choose Add to Home Screen."
                : "Use your browser's Install app or Add to Home Screen option to install Food by K.", "info", 9000);
        });
        notifications.addEventListener("click", enableNotifications);
        dismiss.addEventListener("click", () => {
            try { localStorage.setItem(INSTALL_DISMISSED, String(Date.now() + 24 * 60 * 60 * 1000)); } catch (_) {}
            prompt.remove();
        });
        prompt.append(message, install, notifications, dismiss); document.body.appendChild(prompt);
    }

    async function enableNotifications() {
        const button = document.querySelector(".foodbyk-install-prompt .foodbyk-install-action:nth-of-type(2)");
        if (!window.isSecureContext || !("serviceWorker" in navigator) || !window.PushManager || !window.apiGet || !window.apiPost) {
            showNotice("Order notifications are not available in this browser yet.", "error"); return;
        }
        try {
            const config = await window.apiGet("/push/public-key");
            if (!config?.success || !config.data?.enabled || !config.data?.public_key) {
                showNotice("Order notifications are being set up. Please try again later.", "error"); return;
            }
            if (Notification.permission === "denied") {
                showNotice("Notifications are blocked in your browser settings. Allow notifications for Food by K, then try again.", "error"); return;
            }
            const session = await window.apiGet("/auth/me");
            if (!session?.success || !session.data) throw new Error("Sign in to enable order notifications on this device.");
            const permission = Notification.permission === "granted" ? "granted" : await Notification.requestPermission();
            if (permission !== "granted") { showNotice("Allow notifications to receive updates on your order.", "info"); return; }
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription() || await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: decodeVapidKey(config.data.public_key)
            });
            const saved = await window.apiPost("/push/subscribe", { subscription: subscription.toJSON() });
            if (!saved?.success) throw new Error(saved?.error || "Unable to save notification settings.");
            const role = String(session.data?.user?.role || session.data?.role || "").toLowerCase();
            document.querySelector(".foodbyk-install-prompt")?.remove();
            showNotice(role === "staff" || role === "admin"
                ? "New order alerts are on for this device. Open the app to review orders; alerts can be dismissed by your device.": "Order notifications are on for this device.", "success");
        } catch (error) {
            showNotice(error.message || "Unable to enable notifications. Please try again.", "error");
        } finally {
            if (button) button.disabled = false;
        }
    }

    function decodeVapidKey(value) {
        const padding = "=".repeat((4 - value.length % 4) % 4);
        return Uint8Array.from(atob((value + padding).replace(/-/g, "+").replace(/_/g, "/")), (character) => character.charCodeAt(0));
    }
})();

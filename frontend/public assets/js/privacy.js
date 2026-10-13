(() => {
    const KEY = "foodByKCookieChoice", VERSION = "2026-10-13";
    if (!document.querySelector('link[data-privacy-style]')) {
        const style = document.createElement("link"); style.rel = "stylesheet"; style.href = "/public%20assets/css/privacy.css"; style.dataset.privacyStyle = "true"; document.head.appendChild(style);
    }
    const readChoice = () => { try { return JSON.parse(localStorage.getItem(KEY) || "null"); } catch { return null; } };
    const saveChoice = (choice) => localStorage.setItem(KEY, JSON.stringify({ essential: true, optional: choice, version: VERSION, saved_at: new Date().toISOString() }));
    const openBanner = () => {
        let banner = document.getElementById("cookie-consent-banner");
        if (!banner) {
            banner = document.createElement("section"); banner.id = "cookie-consent-banner"; banner.className = "cookie-consent-banner";
            banner.setAttribute("role", "dialog"); banner.setAttribute("aria-label", "Cookie preferences");
            banner.innerHTML = '<div><strong>Privacy and cookies</strong><p>Food by K uses essential session storage to keep you signed in and remembers this cookie choice. No analytics or advertising cookies are active. <a href="/pages/legal/cookies.html">Cookie details</a></p></div><div class="cookie-consent-actions"><button type="button" data-cookie-choice="essential">Essential only</button><button type="button" data-cookie-choice="all">Accept all</button></div>';
            document.body.appendChild(banner);
            banner.addEventListener("click", (event) => { const choice = event.target.closest("[data-cookie-choice]")?.dataset.cookieChoice; if (choice) { saveChoice(choice); banner.remove(); } });
        }
    };
    const choice = readChoice(); if (!choice || choice.version !== VERSION) openBanner();
    window.foodByKManageCookieSettings = openBanner;
})();

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
        initializeMobileNavigation();
        updateCustomerNavigation();
        installFoodByKAssistant();
    }, { once: true });
} else {
    initializeMobileNavigation();
    updateCustomerNavigation();
    installFoodByKAssistant();
}

function initializeMobileNavigation() {
    const navbar = document.querySelector(".navbar");
    const nav = navbar?.querySelector("nav");
    const brand = navbar?.querySelector(".logo a");
    if (!navbar || !nav || !brand) return;

    if (!brand.querySelector(".navbar-brand-mark")) {
        const mark = document.createElement("span");
        mark.className = "navbar-brand-mark";
        mark.setAttribute("aria-hidden", "true");
        brand.prepend(mark);
    }

    if (!nav.id) nav.id = "customer-site-navigation";
    let toggle = navbar.querySelector("[data-mobile-nav-toggle]");
    if (!toggle) {
        toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "navbar-toggle";
        toggle.dataset.mobileNavToggle = "true";
        toggle.setAttribute("aria-controls", nav.id);
        toggle.setAttribute("aria-expanded", "false");
        toggle.setAttribute("aria-label", "Open navigation menu");
        for (let index = 0; index < 3; index += 1) {
            const line = document.createElement("span");
            line.setAttribute("aria-hidden", "true");
            toggle.appendChild(line);
        }
        brand.closest(".logo").insertAdjacentElement("afterend", toggle);
    }

    if (toggle.dataset.mobileNavReady === "true") return;
    toggle.dataset.mobileNavReady = "true";
    toggle.addEventListener("click", () => {
        const isOpen = navbar.classList.toggle("is-mobile-nav-open");
        toggle.setAttribute("aria-expanded", String(isOpen));
        toggle.setAttribute("aria-label", isOpen ? "Close navigation menu" : "Open navigation menu");
    });
    nav.addEventListener("click", (event) => {
        if (event.target.closest("a")) closeMobileNavigation(navbar, toggle);
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") closeMobileNavigation(navbar, toggle);
    });
    document.addEventListener("click", (event) => {
        if (!navbar.contains(event.target)) closeMobileNavigation(navbar, toggle);
    });
}

function closeMobileNavigation(navbar, toggle) {
    navbar.classList.remove("is-mobile-nav-open");
    toggle.setAttribute("aria-expanded", "false");
    toggle.setAttribute("aria-label", "Open navigation menu");
}

function installFoodByKAssistant() {
    if (!document.querySelector(".navbar")) return;
    const navigationScript = document.querySelector('script[src*="navigation.js"]');
    if (!navigationScript?.src) return;

    if (!document.querySelector("[data-mobile-navigation-styles]")) {
        const navigationStyles = document.createElement("link");
        navigationStyles.rel = "stylesheet";
        navigationStyles.href = new URL("../css/mobile-navigation.css", navigationScript.src).href;
        navigationStyles.dataset.mobileNavigationStyles = "true";
        document.head.appendChild(navigationStyles);
    }

    if (document.querySelector("[data-foodbyk-assistant]")) return;

    const stylesheet = document.createElement("link");
    stylesheet.rel = "stylesheet";
    stylesheet.href = new URL("../css/assistant.css", navigationScript.src).href;
    document.head.appendChild(stylesheet);

    const script = document.createElement("script");
    script.src = new URL("assistant.js", navigationScript.src).href;
    script.defer = true;
    document.body.appendChild(script);
}

async function updateCustomerNavigation() {
    const nav = document.querySelector(".navbar nav");
    if (!nav) return;
    nav.dataset.authReady = "pending";
    nav.setAttribute("aria-busy", "true");
    renderNavigationSkeletons(nav);

    let user = null;
    try {
        if (typeof getCurrentUser === "function") {
            const result = await getCurrentUser();
            if (result?.success && result.data) {
                user = result.data;
                localStorage.setItem("foodByKUser", JSON.stringify({ id: user.id, role: user.role }));
            } else if (result?.status === 0) {
                user = getStoredUser();
            } else {
                localStorage.removeItem("foodByKUser");
            }
        } else {
            user = getStoredUser();
        }
    } catch (error) {
        user = getStoredUser();
        console.warn("Unable to refresh Food by K session navigation.", error);
    }

    renderNavigationActions(nav, user);
    await renderCustomerOrdersLink(nav, user);
    setActiveNavigationLink(nav);
    nav.querySelectorAll("[data-nav-skeleton]").forEach((item) => item.remove());
    nav.dataset.authReady = "true";
    nav.setAttribute("aria-busy", "false");
}

function renderNavigationSkeletons(nav) {
    nav.querySelectorAll("[data-nav-skeleton]").forEach((item) => item.remove());
    const count = Math.max(3, Math.min(nav.querySelectorAll("a").length, 6));
    for (let index = 0; index < count; index += 1) {
        const placeholder = document.createElement("span");
        placeholder.className = "nav-skeleton";
        placeholder.dataset.navSkeleton = "true";
        placeholder.setAttribute("aria-hidden", "true");
        nav.appendChild(placeholder);
    }
}

function renderNavigationActions(nav, user) {
    const navRoot = nav.closest(".navbar") || nav;
    navRoot.querySelectorAll("[data-customer-orders-link]").forEach((item) => item.remove());
    navRoot.querySelectorAll("[data-customer-promotions-link]").forEach((item) => item.remove());
    navRoot.querySelectorAll("[data-customer-loyalty-link]").forEach((item) => item.remove());
    navRoot.querySelectorAll("a, button").forEach((item) => {
        const label = item.textContent.trim().toLowerCase();
        const href = item.getAttribute("href") || "";
        const isAuthAction = item.classList.contains("nav-auth-action")
            || item.matches(".nav-login, .nav-register, .nav-account, .nav-logout")
            || /(?:^|\/)(login|register|account)\.html(?:$|[?#])/.test(href)
            || ["login", "sign in", "register", "account", "logout", "dashboard", "staff orders"].includes(label);
        if (isAuthAction) item.remove();
    });

    const promotionsLink = createNavigationLink("Promotions", "/pages/customer/promotions.html", "nav-customer-promotions");
    promotionsLink.dataset.customerPromotionsLink = "true";
    const insertionPoint = [...nav.querySelectorAll("a")].find((link) => {
        const href = (link.getAttribute("href") || "").toLowerCase();
        return /(?:^|\/)cart\.html(?:$|[?#])/.test(href)
            || /(?:^|\/)menu\.html(?:$|[?#])/.test(href);
    });
    if (insertionPoint) insertionPoint.insertAdjacentElement("afterend", promotionsLink);
    else nav.appendChild(promotionsLink);

    if (!user) {
        nav.append(
            createNavigationLink("Login", "/pages/auth/login.html", "nav-login nav-auth-action"),
            createNavigationLink("Register", "/pages/auth/register.html", "nav-register nav-auth-action")
        );
        return;
    }

    const role = String(user.role || "customer").toLowerCase();
    if (role === "customer") {
        const loyaltyLink = createNavigationLink("Loyalty rewards", "/pages/customer/loyalty.html", "nav-customer-loyalty");
        loyaltyLink.dataset.customerLoyaltyLink = "true";
        promotionsLink.insertAdjacentElement("afterend", loyaltyLink);
    }
    let accountLabel = "Account";
    let accountPath = "/pages/auth/account.html";
    if (role === "admin") {
        accountLabel = "Dashboard";
        accountPath = "/admin/dashboard.html";
    } else if (role === "staff") {
        accountLabel = "Staff orders";
        accountPath = "/admin/orders.html";
    }

    const logoutLink = createNavigationLink("Logout", "#", "nav-register nav-logout nav-auth-action");
    logoutLink.addEventListener("click", handleNavigationLogout);
    nav.append(
        createNavigationLink(accountLabel, accountPath, "nav-account nav-auth-action"),
        logoutLink
    );
}

async function renderCustomerOrdersLink(nav, user) {
    if (String(user?.role || "").toLowerCase() !== "customer"
        || user?.policy_acceptance_required
        || typeof apiGet !== "function") return;

    try {
        const result = await apiGet("/orders?limit=1");
        if (!result?.success || Number(result.data?.total || 0) < 1) return;

        const link = createNavigationLink("Your orders", "/pages/customer/orders.html", "nav-customer-orders");
        link.dataset.customerOrdersLink = "true";
        nav.appendChild(link);
    } catch (error) {
        console.warn("Unable to check customer orders for navigation.", error);
    }
}

function createNavigationLink(label, path, className) {
    const link = document.createElement("a");
    link.href = path;
    link.className = className;
    link.textContent = label;
    return link;
}

async function handleNavigationLogout(event) {
    event.preventDefault();
    const link = event.currentTarget;
    link.textContent = "Logging out…";
    link.setAttribute("aria-disabled", "true");

    try {
        if (typeof logoutUser !== "function") {
            throw new Error("Logout is unavailable. Please reload and try again.");
        }
        const result = await logoutUser();
        if (!result?.success) throw new Error(result?.error || "Logout failed.");

        localStorage.removeItem("foodByKUser");
        window.location.assign("/src/index.html");
    } catch (error) {
        link.textContent = "Logout";
        link.removeAttribute("aria-disabled");
        window.alert(error.message || "Unable to log out. Please try again.");
    }
}

function getStoredUser() {
    try {
        const storedUser = localStorage.getItem("foodByKUser");
        return storedUser ? JSON.parse(storedUser) : null;
    } catch (error) {
        localStorage.removeItem("foodByKUser");
        return null;
    }
}

function setActiveNavigationLink(nav) {
    const currentUrl = new URL(window.location.href);
    nav.querySelectorAll("a").forEach((link) => {
        const targetUrl = new URL(link.href, currentUrl);
        if (targetUrl.pathname === currentUrl.pathname && !targetUrl.hash) {
            link.classList.add("active");
        } else {
            link.classList.remove("active");
        }
    });
}

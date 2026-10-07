if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", updateCustomerNavigation, { once: true });
} else {
    updateCustomerNavigation();
}

async function updateCustomerNavigation() {
    const nav = document.querySelector(".navbar nav");
    if (!nav) return;
    nav.dataset.authReady = "pending";

    let user = null;
    try {
        if (typeof getCurrentUser === "function") {
            const result = await getCurrentUser();
            if (result?.success && result.data) {
                user = result.data;
                localStorage.setItem("foodByKUser", JSON.stringify(user));
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
    setActiveNavigationLink(nav);
}

function renderNavigationActions(nav, user) {
    const navRoot = nav.closest(".navbar") || nav;
    navRoot.querySelectorAll("a, button").forEach((item) => {
        const label = item.textContent.trim().toLowerCase();
        const href = item.getAttribute("href") || "";
        const isAuthAction = item.classList.contains("nav-auth-action")
            || item.matches(".nav-login, .nav-register, .nav-account, .nav-logout")
            || /(?:^|\/)(login|register|account)\.html(?:$|[?#])/.test(href)
            || ["login", "sign in", "register", "account", "logout", "dashboard", "staff orders"].includes(label);
        if (isAuthAction) item.remove();
    });

    if (!user) {
        nav.append(
            createNavigationLink("Login", "/pages/auth/login.html", "nav-login nav-auth-action"),
            createNavigationLink("Register", "/pages/auth/register.html", "nav-register nav-auth-action")
        );
        nav.dataset.authReady = "true";
        return;
    }

    const role = String(user.role || "customer").toLowerCase();
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
    nav.dataset.authReady = "true";
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

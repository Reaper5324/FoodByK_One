const ADMIN_ONLY_PAGES = new Set([
    "categories.html",
    "promotions.html",
    "customers.html",
    "staff-users.html",
    "reports.html",
    "settings.html"
]);

const STAFF_ALLOWED_PAGES = new Set([
    "dashboard.html",
    "orders.html",
    "menu-items.html"
]);




const uploadProductImage = async (file) => {
    const csrfToken = await ensureCsrfToken();
    const formData = new FormData();
    formData.append("image", file);
    try {
        const response = await fetch(API_BASE_URL + "/admin/products/image", {
            method: "POST",
            body: formData,
            credentials: "include",
            headers: { ...buildSessionHeaders(), ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {}) }
        });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result?.success) {
            return { success: false, data: null, error: result?.error || "The image could not be uploaded." };
        }
        return { success: true, data: result.data, error: null };
    } catch {
        return { success: false, data: null, error: "The image could not be uploaded. Check your connection and try again." };
    }
};

const initializeAdminLive = async () => {
    const page = location.pathname.split("/").pop();
    const loadingState = document.getElementById("adminLoadingState");
    const showLoadingError = (message) => {
        if (!loadingState) return;
        loadingState.classList.add("is-error");
        const status = loadingState.querySelector("[data-admin-loading-message]");
        if (status) status.textContent = message;
        const retry = loadingState.querySelector("[data-admin-loading-retry]");
        if (retry) retry.hidden = false;
    };
    loadingState?.querySelector("[data-admin-loading-retry]")?.addEventListener("click", () => location.reload());
    let initialDataLoadError = false;
    let isInitialPageLoad = true;
    const loadScript = (src) => new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = src;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });

    try {
        if (typeof apiGet !== "function") {
            if (typeof API_BASE_URL === "undefined") {
                await loadScript("../public%20assets/js/config.js");
            }
            await loadScript("../public%20assets/js/api/api.js");
        }
    } catch {
        showLoadingError("Could not connect to the service. Check your connection and try again.");
        return;
    }

    const authResult = await apiGet("/auth/me");

    if (!authResult.success || !authResult.data) {
        if (authResult.status === 0 || authResult.status >= 500) {
            showLoadingError("Could not load your session. Check your connection and try again.");
            return;
        }
        location.href = "../pages/auth/login.html";
        return;
    }

    const role = String(authResult.data.role || "").toLowerCase();

    if (role !== "admin" && role !== "staff") {
        location.href = "../pages/auth/login.html";
        return;
    }

    if (role === "staff" && ADMIN_ONLY_PAGES.has(page)) {
        location.href = "dashboard.html";
        return;
    }


        // Notification and signed-in user menus
    const topbarRight = document.querySelector(".topbar-right");
    const notificationButton = document.querySelector(".notification-btn");
    const profile = document.querySelector(".admin-profile");

    const user = authResult.data.user || authResult.data;
    const userName = user.name || user.full_name || user.username || "Signed-in user";
    const userEmail = user.email || "";
    const userRole = String(user.role || role).toLowerCase();

    // Show the actual signed-in user
    if (profile) {
        const heading = profile.querySelector(".profile-info h4");
        const subtitle = profile.querySelector(".profile-info p");
        const avatar = profile.querySelector(".profile-image");

        if (heading) heading.textContent = userName;
        if (subtitle) {
            subtitle.textContent =
                userRole.charAt(0).toUpperCase() + userRole.slice(1);
        }
        if (avatar) avatar.textContent = userName.charAt(0).toUpperCase();

        profile.setAttribute("role", "button");
        profile.setAttribute("tabindex", "0");
        profile.setAttribute("aria-expanded", "false");
        profile.setAttribute("aria-label", "Open account menu");
    }

    // Create the notification panel
    let notificationPanel = document.getElementById("notificationPanel");

    if (!notificationPanel && topbarRight) {
        notificationPanel = document.createElement("div");
        notificationPanel.id = "notificationPanel";
        notificationPanel.className = "topbar-popover notification-panel";
        notificationPanel.innerHTML = `
            <h3>Notifications</h3>
            <p>No new notifications.</p>
            <small>Notifications are not yet connected to the backend.</small>
        `;
        topbarRight.appendChild(notificationPanel);
    }

    // Create the account dropdown
    let accountPanel = document.getElementById("accountPanel");

    if (!accountPanel && topbarRight) {
        accountPanel = document.createElement("div");
        accountPanel.id = "accountPanel";
        accountPanel.className = "topbar-popover account-panel";
        accountPanel.innerHTML = `
            <div class="account-panel-user">
                <strong class="account-user-name"></strong>
                <small class="account-user-email"></small>
                <small class="account-user-role"></small>
            </div>
            <button type="button" class="account-logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i>
                Logout
            </button>
        `;
        accountPanel.querySelector(".account-user-name").textContent = userName;
        accountPanel.querySelector(".account-user-email").textContent = userEmail;
        accountPanel.querySelector(".account-user-role").textContent =
            userRole.charAt(0).toUpperCase() + userRole.slice(1);
        topbarRight.appendChild(accountPanel);
    }

    const closeTopbarPanels = () => {
        notificationPanel?.classList.remove("is-open");
        accountPanel?.classList.remove("is-open");
        profile?.setAttribute("aria-expanded", "false");
    };

    notificationButton?.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();

        const shouldOpen = !notificationPanel?.classList.contains("is-open");
        closeTopbarPanels();

        if (shouldOpen) {
            notificationPanel?.classList.add("is-open");
        }
    });

    const toggleAccountPanel = (event) => {
        event.preventDefault();
        event.stopPropagation();

        const shouldOpen = !accountPanel?.classList.contains("is-open");
        closeTopbarPanels();

        if (shouldOpen) {
            accountPanel?.classList.add("is-open");
            profile?.setAttribute("aria-expanded", "true");
        }
    };

    profile?.addEventListener("click", toggleAccountPanel);

    profile?.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
            toggleAccountPanel(event);
        }
    });

    document.addEventListener("click", (event) => {
        if (
            !event.target.closest(".notification-btn") &&
            !event.target.closest(".admin-profile") &&
            !event.target.closest(".topbar-popover")
        ) {
            closeTopbarPanels();
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") closeTopbarPanels();
    });

    accountPanel?.querySelector(".account-logout-btn")?.addEventListener(
        "click",
        async () => {
            try {
                await apiPost("/auth/logout", {});
            } finally {
                location.href = "../pages/auth/login.html";
            }
        }
    );

    
        const paths = {
        "Dashboard": "dashboard.html",
        "Orders": "orders.html",
        "Menu Items": "menu-items.html",
        "Categories": "categories.html",
        "Promotions": "promotions.html",
        "Customers": "customers.html",
        "Staff & Users": "staff-users.html",
        "Reports": "reports.html",
        "Settings": "settings.html"
    };

    if (role === "staff") {
        Object.keys(paths).forEach((label) => {
            if (ADMIN_ONLY_PAGES.has(paths[label])) {
                delete paths[label];
            }
        });
    }

    const labels = Object.keys(paths);


    const nav = document.querySelector(".sidebar-menu");
    if (nav) {
        nav.replaceChildren();
        labels.forEach((label) => {
            const link = document.createElement("a");
            link.className = "menu-item" + (paths[label] === page ? " active" : "");
            link.href = paths[label];
            const name = document.createElement("span");
            name.textContent = label;
            link.appendChild(name);
            nav.appendChild(link);
        });
    }

    const logout = document.querySelector(".sidebar-bottom .logout");
    logout?.addEventListener("click", async (event) => {
        event.preventDefault();
        await apiPost("/auth/logout", {});
        location.href = "../pages/auth/login.html";
    });

    const showResult = (result) => {
        if (!result?.success) {
            if (isInitialPageLoad) {
                initialDataLoadError = true;
            } else {
                alert(result?.error || "The request could not be completed.");
            }
            return false;
        }
        return true;
    };
    const makeCell = (row, value) => {
        const cell = document.createElement("td");
        cell.textContent = value == null || value === "" ? "—" : String(value);
        row.appendChild(cell);
        return cell;
    };
    const setMessageRow = (body, text, columns) => {
        body.replaceChildren();
        const row = document.createElement("tr");
        const cell = document.createElement("td");
        cell.colSpan = columns;
        cell.textContent = text;
        row.appendChild(cell);
        body.appendChild(row);
    };
    const addAction = (cell, label, action, id) => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "action-btn";
        button.textContent = label;
        button.dataset.adminAction = action;
        button.dataset.id = String(id);
        cell.appendChild(button);
    };

    const loadDashboard = async () => {
        const [analytics, orders] = await Promise.all([
            apiGet("/staff/analytics"), apiGet("/staff/orders/incoming")
        ]);
        if (analytics.success) {
            const values = [analytics.data.pending_orders, analytics.data.orders_today,
                analytics.data.completed_today, "R" + Number(analytics.data.revenue_today).toFixed(2)];
            document.querySelectorAll(".stats-grid .stat-card h3").forEach((el, index) => {
                if (values[index] !== undefined) el.textContent = String(values[index]);
            });
        } else {
            showResult(analytics);
        }
        const body = document.getElementById("dashboardIncomingOrders");
        if (!body) return;
        if (!orders.success) {
            showResult(orders);
            setMessageRow(body, orders.error || "Unable to load incoming orders.", 7);
            return;
        }
        const today = new Date();
        const todayKey = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;
        const records = (orders.data || []).filter((order) => {
            const requestedDate = String(order.requested_window_start || "").slice(0, 10);
            return order.status === "submitted" && requestedDate === todayKey;
        });
        const pending = document.getElementById("dashboardPendingCount");
        if (pending) pending.textContent = String(records.length);
        if (!records.length) return setMessageRow(body, "There are no orders due for review today.", 7);
        body.replaceChildren();
        records.slice(0, 5).forEach((order) => {
            const row = document.createElement("tr");
            const time = String(order.requested_window_start || "").match(/\d{2}:\d{2}/)?.[0] || "—";
            const total = Math.max(0, Number(order.subtotal || 0) + Number(order.delivery_fee || 0)
                - Number(order.locked_discount || 0) - Number(order.loyalty_points_redeemed || 0)
                + Number(order.loyalty_points_refunded || 0));
            [`#${order.id}`, order.customer_name || "Customer", order.fulfilment_type,
                time, "R" + total.toFixed(2), "Pending"].forEach((value) => makeCell(row, value));
            const action = document.createElement("td");
            const link = document.createElement("a");
            link.className = "action-btn";
            link.href = "orders.html";
            link.textContent = "Manage";
            action.appendChild(link);
            row.appendChild(action);
            body.appendChild(row);
        });
    };

    const loadReports = async () => {
        const period = document.getElementById("reportPeriod")?.value || "week";
        const result = await apiGet("/admin/analytics?period=" + encodeURIComponent(period));
        if (!showResult(result)) return;
        document.getElementById("reportSales")?.replaceChildren(document.createTextNode("R" + Number(result.data.sales).toFixed(2)));
        document.getElementById("reportOrders")?.replaceChildren(document.createTextNode(String(result.data.orders)));
        document.getElementById("reportAverage")?.replaceChildren(document.createTextNode("R" + Number(result.data.average_paid_order).toFixed(2)));
        const columns = document.querySelectorAll(".chart-bars .chart-column");
        const sales = result.data.daily_sales || [];
        const max = Math.max(1, ...sales.map((item) => Number(item.sales)));
        columns.forEach((column, index) => {
            const visibleSales = sales.slice(-columns.length);
            const item = visibleSales[index];
            const bar = column.querySelector(".chart-bar");
            const label = column.querySelector("span");
            if (!item) {
                if (bar) bar.style.height = "0%";
                if (label) label.textContent = "—";
                return;
            }
            if (bar) bar.style.height = Math.max(2, Number(item.sales) / max * 100) + "%";
            if (label) label.textContent = item.date.slice(5);
        });
        const topContainer = document.querySelector(".report-two-column");
        if (topContainer) {
            topContainer.replaceChildren();
            const card = document.createElement("div");
            card.className = "content-card report-section";
            const heading = document.createElement("h3");
            heading.textContent = "Top Selling Items";
            card.appendChild(heading);
            (result.data.top_products || []).forEach((product, index) => {
                const item = document.createElement("p");
                item.textContent = `${index + 1}. ${product.name} — ${product.units} sold (R${Number(product.sales).toFixed(2)})`;
                card.appendChild(item);
            });
            if (!result.data.top_products?.length) {
                const empty = document.createElement("p");
                empty.textContent = "No paid orders in this period.";
                card.appendChild(empty);
            }
            topContainer.appendChild(card);
        }
    };

    const loadCategories = async () => {
        const [result, productResult] = await Promise.all([apiGet("/admin/categories"), apiGet("/admin/products")]);
        if (!showResult(result) || !showResult(productResult)) return;
        const body = document.querySelector("#categoryTable tbody");
        if (!body) return;
        body.replaceChildren();
        result.data.forEach((category) => {
            const row = document.createElement("tr");
            row.dataset.id = category.id;
            makeCell(row, category.name);
            makeCell(row, category.description);
            makeCell(row, productResult.data.filter((product) => Number(product.category_id) === Number(category.id)).length);
            makeCell(row, "Active");
            const actions = document.createElement("td");
            addAction(actions, "Edit", "category-edit", category.id);
            addAction(actions, "Delete", "category-delete", category.id);
            row.appendChild(actions);
            body.appendChild(row);
        });
        const total = document.getElementById("totalCategories");
        if (total) total.textContent = String(result.data.length);
        const active = document.getElementById("activeCategories");
        if (active) active.textContent = String(result.data.length);
        window.adminCategories = result.data;
    };

    const loadProducts = async () => {
        const productEndpoint = role === "staff" ? "/staff/products" : "/admin/products";
        const categoryEndpoint = role === "staff" ? "/categories" : "/admin/categories";
        const [productResult, categoryResult] = await Promise.all([apiGet(productEndpoint), apiGet(categoryEndpoint)]);
        if (!showResult(productResult) || !showResult(categoryResult)) return;
        const grid = document.getElementById("menuGrid");
        const addMenuButton = document.getElementById("addMenuBtn");
        if (addMenuButton) addMenuButton.hidden = role !== "admin";
        const title = document.querySelector(".menu-page-header h3");
        const description = document.querySelector(".menu-description");
        if (role === "staff") {
            if (title) title.textContent = "Menu Availability";
            if (description) description.textContent = "Mark menu items available or unavailable.";
        }
        const categoryNames = new Map(categoryResult.data.map((category) => [Number(category.id), category.name]));
        const categorySelect = document.getElementById("itemCategory");
        if (categorySelect) {
            categorySelect.replaceChildren(new Option("Select category", ""));
            categoryResult.data.forEach((category) => categorySelect.add(new Option(category.name, category.id)));
        }
        const filterButtons = document.querySelector(".menu-filter-buttons");
        if (filterButtons) {
            filterButtons.replaceChildren();
            const all = document.createElement("button");
            all.type = "button"; all.className = "menu-filter-btn active"; all.dataset.category = "all"; all.textContent = "All";
            filterButtons.appendChild(all);
            categoryResult.data.forEach((category) => {
                const button = document.createElement("button");
                button.type = "button"; button.className = "menu-filter-btn"; button.dataset.category = String(category.id); button.textContent = category.name;
                filterButtons.appendChild(button);
            });
        }
        if (!grid) return;
        grid.replaceChildren();
        productResult.data.forEach((product) => {
            const card = document.createElement("article");
            card.className = "menu-card";
            card.dataset.id = product.id;
            card.dataset.category = String(product.category_id);
            const imageContainer = document.createElement("div");
            imageContainer.className = "menu-image";
            const image = document.createElement("img");
            image.src = product.image_url || "";
            image.alt = product.name;
            const status = document.createElement("span");
            status.className = `availability ${product.is_available ? "available" : "unavailable"}`;
            status.textContent = product.is_available ? "Available" : "Unavailable";
            imageContainer.append(image, status);
            const content = document.createElement("div");
            content.className = "menu-card-content";
            const category = document.createElement("p");
            category.className = "menu-category";
            category.textContent = categoryNames.get(Number(product.category_id)) || "MENU ITEM";
            const title = document.createElement("h3");
            title.textContent = product.name;
            const description = document.createElement("p");
            description.className = "menu-description";
            description.textContent = product.description || "";
            const price = document.createElement("p");
            price.className = "menu-price";
            price.textContent = "R" + Number(product.price).toFixed(2);
            const actions = document.createElement("div");
            actions.className = "menu-card-actions";
            const edit = document.createElement("button");
            edit.type = "button"; edit.className = "edit-menu-btn"; edit.textContent = "Edit"; edit.dataset.adminAction = "product-edit"; edit.dataset.id = product.id;
            const availability = document.createElement("button");
            availability.type = "button"; availability.className = `availability-btn${product.is_available ? "" : " unavailable-btn"}`;
            availability.textContent = product.is_available ? "Mark unavailable" : "Mark available";
            availability.dataset.adminAction = "product-toggle"; availability.dataset.id = product.id;
            const remove = document.createElement("button");
            remove.type = "button"; remove.className = "delete-menu-btn"; remove.textContent = "Remove"; remove.dataset.adminAction = "product-delete"; remove.dataset.id = product.id;
            if (role === "admin") actions.append(edit);
            actions.append(availability);
            if (role === "admin") actions.append(remove);
            content.append(category, title, description, price, actions);
            card.append(imageContainer, content);
            grid.appendChild(card);
        });
        const counter = document.getElementById("totalMenuItems");
        if (counter) counter.textContent = String(productResult.data.length);
        window.adminProducts = productResult.data;
    };

    const loadPromotions = async () => {
        const result = await apiGet("/admin/promotions");
        if (!showResult(result)) return;
        const body = document.getElementById("promotionTableBody");
        if (!body) return;
        body.replaceChildren();
        result.data.forEach((promo) => {
            const row = document.createElement("tr");
            row.dataset.id = promo.id;
            makeCell(row, promo.code);
            makeCell(row, promo.discount_type === "percentage" ? `${promo.discount_value}%` : `${promo.discount_type}: ${promo.discount_value}`);
            makeCell(row, promo.start_date);
            makeCell(row, promo.end_date);
            makeCell(row, promo.is_active ? "Active" : "Inactive");
            const actions = document.createElement("td");
            addAction(actions, "Edit", "promotion-edit", promo.id);
            addAction(actions, promo.is_active ? "Deactivate" : "Activate", "promotion-toggle", promo.id);
            row.appendChild(actions);
            body.appendChild(row);
        });
        const setCount = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = String(value); };
        setCount("totalPromotions", result.data.length);
        setCount("activePromotions", result.data.filter((promo) => Boolean(Number(promo.is_active))).length);
        setCount("inactivePromotions", result.data.filter((promo) => !Boolean(Number(promo.is_active))).length);
        window.adminPromotions = result.data;
    };

    const loadStaff = async () => {
        const result = await apiGet("/admin/staff");
        if (!showResult(result)) return;
        const body = document.getElementById("userTableBody");
        if (!body) return;
        body.replaceChildren();
        result.data.forEach((staff) => {
            const row = document.createElement("tr");
            row.dataset.id = staff.id;
            row.dataset.role = staff.role;
            row.dataset.status = Number(staff.is_active) ? "active" : "inactive";
            makeCell(row, staff.full_name);
            makeCell(row, staff.email);
            makeCell(row, staff.role);
            makeCell(row, "—");
            makeCell(row, staff.is_active ? "Active" : "Inactive");
            const actions = document.createElement("td");
            if (staff.role === "staff") {
                addAction(actions, "Edit", "staff-edit", staff.id);
                addAction(actions, staff.is_active ? "Deactivate" : "Activate", "staff-toggle", staff.id);
            }
            row.appendChild(actions);
            body.appendChild(row);
        });
        const setCount = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = String(value); };
        setCount("totalUsers", result.data.length);
        setCount("activeUsers", result.data.filter((user) => Boolean(Number(user.is_active))).length);
        setCount("staffMembers", result.data.filter((user) => user.role === "staff").length);
        window.adminStaff = result.data;
    };

    const loadCustomers = async () => {
        const result = await apiGet("/admin/customers");
        if (!showResult(result)) return;
        const body = document.getElementById("customerTableBody");
        if (!body) return;
        body.replaceChildren();
        result.data.forEach((customer) => {
            const row = document.createElement("tr");
            row.dataset.id = customer.id;
            row.dataset.status = Number(customer.is_active) ? "active" : "inactive";
            makeCell(row, customer.full_name); makeCell(row, customer.email); makeCell(row, customer.phone);
            makeCell(row, customer.order_count); makeCell(row, "R" + Number(customer.total_spent).toFixed(2));
            makeCell(row, customer.is_active ? "Active" : "Inactive");
            const actions = document.createElement("td");
            addAction(actions, "View", "customer-view", customer.id);
            addAction(actions, customer.is_active ? "Deactivate" : "Activate", "customer-toggle", customer.id);
            row.appendChild(actions); body.appendChild(row);
        });
        const setCount = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = String(value); };
        setCount("totalCustomers", result.data.length);
        setCount("activeCustomers", result.data.filter((user) => Boolean(Number(user.is_active))).length);
        setCount("newCustomers", result.data.filter((user) => String(user.created_at || "").slice(0, 10) === new Date().toISOString().slice(0, 10)).length);
        window.adminCustomers = result.data;
    };

    const loadOrders = async () => {
        const result = await apiGet("/staff/orders");
        if (!showResult(result)) return;
        const body = document.querySelector("#ordersTable tbody");
        if (!body) return;
        body.replaceChildren();
        const records = Array.isArray(result.data) ? result.data : [];
        if (!records.length) {
            setMessageRow(body, "No orders were found.", 7);
            window.adminOrders = [];
            return;
        }
        records.forEach((order) => {
            const row = document.createElement("tr");
            row.dataset.status = order.status === "submitted" ? "awaiting" : order.status;
            row.dataset.id = order.id;
            const requested = order.confirmed_window_start || order.requested_window_start;
            makeCell(row, `#${order.id}`); makeCell(row, order.customer_name || "Customer");
            makeCell(row, String(order.fulfilment_type || "").replaceAll("_", " "));
            makeCell(row, requested ? new Date(requested).toLocaleString([], { dateStyle: "medium", timeStyle: "short" }) : "—");
            const total = order.total ?? Math.max(0, Number(order.subtotal || 0) + Number(order.delivery_fee || 0)
                - Number(order.locked_discount || 0) - Number(order.loyalty_points_redeemed || 0)
                + Number(order.loyalty_points_refunded || 0));
            makeCell(row, "R" + Number(total).toFixed(2));
            makeCell(row, String(order.status || "unknown").replaceAll("_", " "));
            const actions = document.createElement("td");
            addAction(actions, order.status === "submitted" ? "Review" : "View / Manage", "order-review", order.id);
            row.appendChild(actions); body.appendChild(row);
        });
        window.adminOrders = records;
    };

    let currentReviewOrderId = null;
    let currentReviewData = null;
    const money = (value) => "R" + Number(value || 0).toFixed(2);
    const displayDateTime = (value) => {
        if (!value) return "—";
        const date = new Date(String(value).replace(" ", "T"));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
    };
    const setText = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value == null || value === "" ? "—" : String(value);
    };
    const localDateKey = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
    const loadReviewSlots = async () => {
        const dateInput = document.getElementById("slotDate");
        const select = document.getElementById("slotSelect");
        const button = document.querySelector(".confirm-time-btn");
        const help = document.getElementById("slotHelp");
        if (!dateInput || !select) return;
        select.replaceChildren();
        const placeholder = document.createElement("option");
        placeholder.value = ""; placeholder.textContent = "Loading available times…"; select.appendChild(placeholder);
        if (button) button.disabled = true;
        if (help) help.textContent = "";
        const result = await apiGet(`/checkout/slots?date=${encodeURIComponent(dateInput.value)}`);
        select.replaceChildren();
        if (!result.success) {
            const failed = document.createElement("option"); failed.value = ""; failed.textContent = "Unable to load times"; select.appendChild(failed);
            if (help) help.textContent = result.error || "Unable to load available times.";
            return;
        }
        const slots = (Array.isArray(result.data) ? result.data : []).filter((slot) => slot.available);
        const choose = document.createElement("option"); choose.value = ""; choose.textContent = slots.length ? "Choose an available slot" : "No available slots for this date"; select.appendChild(choose);
        slots.forEach((slot) => {
            const option = document.createElement("option");
            // Preserve the exact ISO format returned by /checkout/slots (Y-m-dTH:i:s, no timezone suffix).
            option.value = JSON.stringify({ start: slot.start, end: slot.end });
            option.textContent = `${new Date(slot.start).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })} – ${new Date(slot.end).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`;
            select.appendChild(option);
        });
        if (help && !slots.length) help.textContent = "Choose another date to see available slots.";
        if (button) button.disabled = true;
    };
    const renderOrderItems = (items) => {
        const container = document.getElementById("modalItems");
        if (!container) return;
        container.replaceChildren();
        if (!Array.isArray(items) || !items.length) {
            const empty = document.createElement("p"); empty.textContent = "No item details were returned for this order."; container.appendChild(empty); return;
        }
        items.forEach((item) => {
            const row = document.createElement("div"); row.className = "order-item-line";
            const name = document.createElement("div"); name.className = "order-item-name";
            const title = document.createElement("strong"); title.textContent = item.product_name || item.name || `Menu item #${item.product_id || "?"}`; name.appendChild(title);
            const detail = document.createElement("span"); detail.textContent = `${Number(item.quantity || 0)} × ${money(item.unit_price ?? item.price)}`; name.appendChild(detail);
            const total = document.createElement("strong"); total.textContent = money(item.line_total ?? (Number(item.quantity || 0) * Number(item.unit_price ?? item.price ?? 0)));
            row.append(name, total); container.appendChild(row);
        });
    };
    const openOrderReview = async (id) => {
        currentReviewOrderId = id;
        currentReviewData = null;
        setText("modalOrderId", `Order #${id}`);
        setText("modalItems", "Loading order items…");
        openModal("#orderModal");
        const result = await apiGet(`/staff/orders/${id}`);
        if (!showResult(result)) return;
        const data = result.data || {};
        const order = data.order || data;
        currentReviewData = { ...data, order };
        const customer = data.customer || {};
        const address = data.address || {};
        setText("modalOrderId", `Order #${order.id || id}`);
        setText("modalCustomer", customer.full_name || customer.name || order.customer_name || "Customer");
        setText("modalContact", [customer.email, customer.phone].filter(Boolean).join(" · ") || "—");
        setText("modalPlaced", displayDateTime(order.created_at));
        setText("modalType", String(order.fulfilment_type || "—").replaceAll("_", " "));
        setText("modalTime", `${displayDateTime(order.confirmed_window_start || order.requested_window_start)}${order.confirmed_window_start ? " (confirmed)" : " (requested)"}`);
        const addressText = [address.address_line1, address.address_line2, address.suburb, address.city, address.postal_code].filter(Boolean).join(", ") || address.address || address.formatted_address || "No address supplied";
        const addressRow = document.getElementById("modalAddressRow");
        if (addressRow) addressRow.hidden = order.fulfilment_type !== "delivery";
        setText("modalAddress", addressText);
        renderOrderItems(data.items || order.items || []);
        setText("modalSubtotal", money(order.subtotal));
        setText("modalDiscount", money(order.locked_discount));
        setText("modalDeliveryFee", money(order.delivery_fee));
        setText("modalTotal", money(data.total ?? Math.max(0, Number(order.subtotal || 0) + Number(order.delivery_fee || 0)
            - Number(order.locked_discount || 0) - Number(order.loyalty_points_redeemed || 0)
            + Number(order.loyalty_points_refunded || 0))));
        setText("modalNotes", order.customer_notes || order.special_instructions || order.notes || "None");
        const pending = order.status === "submitted";
        const confirmation = document.getElementById("confirmationSection");
        const status = document.getElementById("statusSection");
        if (confirmation) confirmation.hidden = !pending;
        if (status) status.classList.toggle("show", !pending && ["paid", "preparing", "ready"].includes(order.status));
        const statusSelect = document.getElementById("statusSelect");
        if (statusSelect) statusSelect.value = ({ paid: "preparing", preparing: "ready", ready: "completed" })[order.status] || "preparing";
        const dateInput = document.getElementById("slotDate");
        if (dateInput) {
            const requested = String(order.requested_window_start || "").slice(0, 10);
            const today = localDateKey(new Date());
            dateInput.min = today;
            dateInput.value = requested >= today ? requested : today;
        }
        const reason = document.getElementById("declineReason"); if (reason) reason.value = "";
        const slotSelect = document.getElementById("slotSelect"); if (slotSelect) slotSelect.replaceChildren();
        const slotButton = document.querySelector(".confirm-time-btn"); if (slotButton) slotButton.disabled = true;
        if (pending) await loadReviewSlots();
    };

    const loadSettings = async () => {
        const result = await apiGet("/admin/settings");
        if (!showResult(result)) return;
        const content = document.querySelector(".dashboard-content");
        if (!content) return;
        content.replaceChildren();
        const form = document.createElement("form");
        form.id = "adminApiSettingsForm";
        form.className = "content-card settings-card";
        const title = document.createElement("h3");
        title.textContent = "Ordering and Delivery Settings";
        form.appendChild(title);
        const fields = [
            ["business_lat", "Business latitude", "number", "0.000001"],
            ["business_long", "Business longitude", "number", "0.000001"],
            ["delivery_radius_km", "Delivery radius (km)", "number", "0.01"],
            ["collection_radius_km", "Collection radius (km)", "number", "0.01"],
            ["delivery_fee", "Delivery fee (R)", "number", "0.01"],
            ["trading_hours_start", "Trading starts", "time", ""],
            ["trading_hours_end", "Trading ends", "time", ""],
            ["slot_duration_minutes", "Slot length (minutes)", "number", "1"],
            ["max_orders_per_slot", "Maximum orders per slot", "number", "1"]
        ];
        fields.forEach(([name, labelText, type, step]) => {
            const label = document.createElement("label");
            label.className = "form-group";
            label.textContent = labelText;
            const input = document.createElement("input");
            input.name = name;
            input.type = type;
            if (step) input.step = step;
            input.required = true;
            const value = result.data[name];
            input.value = type === "time" ? String(value || "").slice(0, 5) : String(value ?? "");
            label.appendChild(input);
            form.appendChild(label);
        });
        ["delivery_enabled", "collection_enabled"].forEach((name) => {
            const label = document.createElement("label");
            const input = document.createElement("input");
            input.type = "checkbox";
            input.name = name;
            input.checked = Boolean(Number(result.data[name]));
            label.append(input, document.createTextNode(name === "delivery_enabled" ? " Delivery enabled" : " Collection enabled"));
            form.appendChild(label);
        });
        const save = document.createElement("button");
        save.type = "submit";
        save.className = "save-menu-btn";
        save.textContent = "Save settings";
        form.appendChild(save);
        content.appendChild(form);
    };

    const pageLoaders = {
        "dashboard.html": loadDashboard,
        "reports.html": loadReports,
        "categories.html": loadCategories,
        "menu-items.html": loadProducts,
        "promotions.html": loadPromotions,
        "staff-users.html": loadStaff,
        "customers.html": loadCustomers,
        "orders.html": loadOrders,
        "settings.html": loadSettings
    };

    const closeModal = (selector) => document.querySelector(selector)?.classList.remove("show");
    const openModal = (selector) => document.querySelector(selector)?.classList.add("show");
    let editingId = null;

    
    document.addEventListener("click", (event) => {
        const button = event.target.closest("[data-quick-action]");
        if (!button) return;

        event.preventDefault();

        const pages = {
            menu: "menu-items.html",
            promotion: "promotions.html",
            orders: "orders.html",
            reports: "reports.html"
        };

        const page = pages[button.dataset.quickAction];
        if (page) {
            window.location.href = page;
        }
    });

    
    document.addEventListener("click", async (event) => {
        const target = event.target.closest("[data-admin-action], #addCategoryBtn, #addMenuBtn, #addPromotionBtn, #addUserBtn, #updateReportBtn, #printReportBtn, #saveHoursBtn, #saveAccountBtn, .close-category-modal, .close-menu-modal, #closePromotionModal, #closeUserModal, #cancelUserBtn, .close-modal, .refresh-btn, .accept-order-btn, .confirm-time-btn, .confirm-decline-btn, .save-status-btn");
        if (!target) return;
        const action = target.dataset.adminAction;
        if (!action && !target.matches("#addCategoryBtn, #addMenuBtn, #addPromotionBtn, #addUserBtn, #updateReportBtn, #printReportBtn, #saveHoursBtn, #saveAccountBtn, .close-category-modal, .close-menu-modal, #closePromotionModal, #closeUserModal, #cancelUserBtn, .close-modal, .refresh-btn, .accept-order-btn, .confirm-time-btn, .confirm-decline-btn, .save-status-btn")) return;
        event.preventDefault(); event.stopImmediatePropagation();

        if (target.matches(".close-category-modal")) return closeModal("#categoryModal");
        if (target.matches(".close-menu-modal")) return closeModal("#menuModal");
        if (target.matches("#closePromotionModal")) return closeModal("#promotionModal");
        if (target.matches("#closeUserModal, #cancelUserBtn")) return closeModal("#userModal");
        if (target.matches(".close-modal")) return closeModal(target.closest("#orderModal") ? "#orderModal" : "#customerModal");
        if (target.matches(".refresh-btn")) return loadOrders();
        if (target.matches("#updateReportBtn")) return loadReports();
        if (target.matches("#printReportBtn")) return window.print();
        if (target.matches("#saveHoursBtn, #saveAccountBtn")) {
            const form = document.getElementById("adminApiSettingsForm");
            if (form) return form.requestSubmit();
            return alert("Settings form is not available.");
        }
        if (target.matches("#addCategoryBtn")) { editingId = null; document.getElementById("categoryForm")?.reset(); openModal("#categoryModal"); return; }
        if (target.matches("#addMenuBtn")) { editingId = null; document.getElementById("menuForm")?.reset(); openModal("#menuModal"); return; }
        if (target.matches("#addPromotionBtn")) { editingId = null; document.getElementById("promotionForm")?.reset(); openModal("#promotionModal"); return; }
        if (target.matches("#addUserBtn")) { editingId = null; document.getElementById("userForm")?.reset(); openModal("#userModal"); return; }

        const id = Number(target.dataset.id);
        const record = (action || "").startsWith("product-") ? window.adminProducts?.find((item) => Number(item.id) === id)
            : (action || "").startsWith("category-") ? null
            : (action || "").startsWith("promotion-") ? window.adminPromotions?.find((item) => Number(item.id) === id)
            : (action || "").startsWith("staff-") ? window.adminStaff?.find((item) => Number(item.id) === id)
            : (action || "").startsWith("customer-") ? window.adminCustomers?.find((item) => Number(item.id) === id)
            : (action || "").startsWith("order-") ? window.adminOrders?.find((item) => Number(item.id) === id) : null;

        if (action === "product-toggle" && record) {
            const result = await apiPut(`/staff/products/${id}/availability`, { is_available: !record.is_available });
            if (showResult(result)) await loadProducts();
        } else if (role === "admin" && action === "product-delete" && confirm("Remove this menu item?")) {
            const result = await apiDelete(`/admin/products/${id}`); if (showResult(result)) await loadProducts();
        } else if (role === "admin" && action === "product-edit" && record) {
            editingId = id;
            document.getElementById("itemName").value = record.name;
            document.getElementById("itemCategory").value = record.category_id;
            document.getElementById("itemPrice").value = record.price;
            document.getElementById("itemImage").value = record.image_key || "";
            document.getElementById("itemDescription").value = record.description || "";
            openModal("#menuModal");
        } else if (action === "category-delete" && confirm("Delete this category? It must have no products assigned.")) {
            const result = await apiDelete(`/admin/categories/${id}`); if (showResult(result)) await loadCategories();
        } else if (action === "category-edit") {
            const result = await apiGet("/admin/categories");
            const category = result.data?.find((item) => Number(item.id) === id);
            if (!category) return showResult(result);
            editingId = id;
            document.getElementById("categoryName").value = category.name;
            document.getElementById("categoryDescription").value = category.description || "";
            openModal("#categoryModal");
        } else if (action === "promotion-toggle" && record) {
            const result = await apiPut(`/admin/promotions/${id}`, { is_active: !Boolean(Number(record.is_active)) });
            if (showResult(result)) await loadPromotions();
        } else if (action === "promotion-edit" && record) {
            if (record.discount_type !== "percentage") return alert("This promotion type cannot be edited with the current form.");
            editingId = id;
            document.getElementById("promotionName").value = record.code;
            document.getElementById("promotionDiscount").value = record.discount_value;
            document.getElementById("promotionStartDate").value = record.start_date || "";
            document.getElementById("promotionEndDate").value = record.end_date || "";
            document.getElementById("promotionStatus").value = record.is_active ? "active" : "inactive";
            openModal("#promotionModal");
        } else if ((action === "staff-toggle" || action === "staff-edit") && record) {
            if (action === "staff-toggle") {
                const result = await apiPut(`/admin/staff/${id}`, { is_active: !Boolean(Number(record.is_active)) });
                if (showResult(result)) await loadStaff();
            } else {
                editingId = id;
                document.getElementById("userName").value = record.full_name;
                document.getElementById("userEmail").value = record.email;
                document.getElementById("userRole").value = record.role;
                openModal("#userModal");
            }
        } else if (action === "customer-toggle" && record) {
            const result = await apiPut(`/admin/customers/${id}`, { is_active: !Boolean(Number(record.is_active)) });
            if (showResult(result)) await loadCustomers();
        } else if (action === "customer-view" && record) {
            document.getElementById("detailName").textContent = record.full_name;
            document.getElementById("detailCustomerId").textContent = `Customer #${record.id}`;
            document.getElementById("detailEmail").textContent = record.email;
            document.getElementById("detailPhone").textContent = record.phone || "—";
            document.getElementById("detailOrders").textContent = record.order_count;
            document.getElementById("detailSpent").textContent = "R" + Number(record.total_spent).toFixed(2);
            openModal("#customerModal");
        } else if (action === "order-review" && record) {
            await openOrderReview(id);
        } else if (target.matches(".accept-order-btn") && currentReviewOrderId) {
            const order = currentReviewData?.order;
            if (!order || order.status !== "submitted") return;
            target.disabled = true;
            const result = await apiPost(`/staff/orders/${currentReviewOrderId}/confirm`, {});
            target.disabled = false;
            if (showResult(result)) { closeModal("#orderModal"); await loadOrders(); }
        } else if (target.matches(".confirm-time-btn") && currentReviewOrderId) {
            const select = document.getElementById("slotSelect");
            if (!select?.value) return alert("Choose an available slot first.");
            const selected = JSON.parse(select.value);
            target.disabled = true;
            const result = await apiPost(`/staff/orders/${currentReviewOrderId}/confirm`, {
                confirmed_window_start: selected.start,
                confirmed_window_end: selected.end
            });
            target.disabled = false;
            if (showResult(result)) { closeModal("#orderModal"); await loadOrders(); }
        } else if (target.matches(".confirm-decline-btn") && currentReviewOrderId) {
            const reason = document.getElementById("declineReason")?.value.trim() || "";
            if (!reason) return alert("Please enter a reason for declining this order.");
            if (reason.length > 255) return alert("The decline reason must be 255 characters or fewer.");
            target.disabled = true;
            const result = await apiPost(`/staff/orders/${currentReviewOrderId}/decline`, { reason });
            target.disabled = false;
            if (showResult(result)) { closeModal("#orderModal"); await loadOrders(); }
        } else if (target.matches(".save-status-btn") && currentReviewOrderId) {
            const next = document.getElementById("statusSelect")?.value;
            if (!next) return;
            const result = await apiPost(`/staff/orders/${currentReviewOrderId}/advance`, { status: next });
            if (showResult(result)) { closeModal("#orderModal"); await loadOrders(); }
        }
    }, true);

    document.addEventListener("submit", async (event) => {
        const form = event.target;
        const id = editingId;
        let result;
        if (form.id === "adminApiSettingsForm") {
            event.preventDefault(); event.stopImmediatePropagation();
            const body = {};
            new FormData(form).forEach((value, key) => {
                const input = form.elements.namedItem(key);
                body[key] = input.type === "checkbox" ? input.checked : input.type === "number" ? Number(value) : value;
            });
            ["delivery_enabled", "collection_enabled"].forEach((key) => {
                body[key] = Boolean(form.elements.namedItem(key).checked);
            });
            result = await apiPut("/admin/settings", body);
            if (showResult(result)) alert("Ordering and delivery settings saved.");
        } else if (form.id === "categoryForm") {
            event.preventDefault(); event.stopImmediatePropagation();
            const category = window.adminCategories?.find((item) => Number(item.id) === Number(id));
            const body = { name: document.getElementById("categoryName").value.trim(), description: document.getElementById("categoryDescription").value.trim(), display_order: category?.display_order ?? 999 };
            result = id ? await apiPut(`/admin/categories/${id}`, body) : await apiPost("/admin/categories", body);
            if (showResult(result)) { closeModal("#categoryModal"); await loadCategories(); }
        } else if (form.id === "menuForm") {
            event.preventDefault(); event.stopImmediatePropagation();
            const product = window.adminProducts?.find((item) => Number(item.id) === Number(id));
            let imageUrl = document.getElementById("itemImage").value.trim() || null;
            const imageFile = document.getElementById("itemImageFile")?.files?.[0];
            if (imageFile) {
                const upload = await uploadProductImage(imageFile);
                if (!showResult(upload)) return;
                imageUrl = upload.data.key;
            }
            const body = { category_id: Number(document.getElementById("itemCategory").value), name: document.getElementById("itemName").value.trim(), description: document.getElementById("itemDescription").value.trim(), price: Number(document.getElementById("itemPrice").value), image_url: imageUrl, is_available: product?.is_available ?? true, status: product?.status || "active" };
            result = id ? await apiPut(`/admin/products/${id}`, body) : await apiPost("/admin/products", body);
            if (showResult(result)) { closeModal("#menuModal"); await loadProducts(); }
        } else if (form.id === "promotionForm") {
            event.preventDefault(); event.stopImmediatePropagation();
            const body = { code: document.getElementById("promotionName").value.trim().toUpperCase(), discount_type: "percentage", discount_value: Number(document.getElementById("promotionDiscount").value), start_date: document.getElementById("promotionStartDate").value || null, end_date: document.getElementById("promotionEndDate").value || null, is_active: document.getElementById("promotionStatus").value === "active" };
            result = id ? await apiPut(`/admin/promotions/${id}`, body) : await apiPost("/admin/promotions", body);
            if (showResult(result)) { closeModal("#promotionModal"); await loadPromotions(); }
        } else if (form.id === "userForm") {
            event.preventDefault(); event.stopImmediatePropagation();
            const body = { full_name: document.getElementById("userName").value.trim(), email: document.getElementById("userEmail").value.trim(), role: document.getElementById("userRole").value };
            result = id ? await apiPut(`/admin/staff/${id}`, body) : await apiPost("/admin/staff", body);
            if (showResult(result)) { closeModal("#userModal"); await loadStaff(); if (!id) alert("An account setup email was sent to the new user."); }
        }
    }, true);

    document.querySelectorAll(".sidebar-bottom .logout").forEach((link) => link.href = "../pages/auth/login.html");
    document.querySelectorAll("#userRole option[value='manager']").forEach((option) => option.remove());
    const password = document.getElementById("userPassword");
    if (password) password.closest(".form-group")?.replaceChildren(document.createTextNode("A secure account setup link is emailed to the new staff member."));
    try {
        if (pageLoaders[page]) await pageLoaders[page]();
        if (initialDataLoadError) {
            showLoadingError("Live data could not be loaded. Check your connection, then try again.");
        } else {
            loadingState?.remove();
        }
    } catch (error) {
        console.error("Unable to load the current admin page data.", error);
        showLoadingError("Live data could not be loaded. Check your connection, then try again.");
    } finally {
        isInitialPageLoad = false;
    }
    document.getElementById("slotDate")?.addEventListener("change", loadReviewSlots);
    document.getElementById("slotSelect")?.addEventListener("change", () => {
        const button = document.querySelector(".confirm-time-btn");
        if (button) button.disabled = !document.getElementById("slotSelect")?.value;
    });
    document.getElementById("reportPeriod")?.addEventListener("change", loadReports);

    const filterRows = (bodySelector, searchSelector, statusSelector, roleSelector) => {
        const body = document.querySelector(bodySelector);
        const query = (document.querySelector(searchSelector)?.value || "").toLowerCase();
        const status = document.querySelector(statusSelector)?.value || "all";
        const role = document.querySelector(roleSelector)?.value || "all";
        body?.querySelectorAll("tr").forEach((row) => {
            const text = row.textContent.toLowerCase();
        row.hidden = !text.includes(query)
                || (status !== "all" && row.dataset.status !== status)
                || (role !== "all" && row.dataset.role !== role);
        });
    };
    document.getElementById("orderSearch")?.addEventListener("input", () => filterRows("#ordersTable tbody", "#orderSearch", null, null));
    document.getElementById("customerSearch")?.addEventListener("input", () => filterRows("#customerTableBody", "#customerSearch", "#customerStatusFilter", null));
    document.getElementById("customerStatusFilter")?.addEventListener("change", () => filterRows("#customerTableBody", "#customerSearch", "#customerStatusFilter", null));
    document.getElementById("userSearch")?.addEventListener("input", () => filterRows("#userTableBody", "#userSearch", null, "#userRoleFilter"));
    document.getElementById("userRoleFilter")?.addEventListener("change", () => filterRows("#userTableBody", "#userSearch", null, "#userRoleFilter"));
    document.getElementById("promotionSearch")?.addEventListener("input", () => filterRows("#promotionTableBody", "#promotionSearch", null, null));
    document.getElementById("categorySearch")?.addEventListener("input", () => filterRows("#categoryTable tbody", "#categorySearch", null, null));

    document.addEventListener("click", (event) => {
        const filter = event.target.closest(".menu-filter-btn, .filter-btn");
        if (!filter) return;
        event.preventDefault(); event.stopImmediatePropagation();
        const isMenu = filter.classList.contains("menu-filter-btn");
        const selector = isMenu ? ".menu-filter-btn" : ".filter-btn";
        document.querySelectorAll(selector).forEach((button) => button.classList.remove("active"));
        filter.classList.add("active");
        if (isMenu) {
            const query = (document.getElementById("menuSearch")?.value || "").toLowerCase();
            const category = filter.dataset.category || "all";
            document.querySelectorAll("#menuGrid .menu-card").forEach((card) => {
                card.hidden = !card.textContent.toLowerCase().includes(query) || (category !== "all" && card.dataset.category !== category);
            });
        } else {
            document.querySelectorAll("#ordersTable tbody tr").forEach((row) => {
                row.hidden = filter.dataset.filter !== "all" && row.dataset.status !== filter.dataset.filter;
            });
        }
    }, true);
    document.getElementById("menuSearch")?.addEventListener("input", () => {
        const category = document.querySelector(".menu-filter-btn.active")?.dataset.category || "all";
        const query = document.getElementById("menuSearch").value.toLowerCase();
        document.querySelectorAll("#menuGrid .menu-card").forEach((card) => {
            card.hidden = !card.textContent.toLowerCase().includes(query) || (category !== "all" && card.dataset.category !== category);
        });
    });
};

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeAdminLive, { once: true });
} else {
    initializeAdminLive();
}

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




const initializeAdminLive = async () => {
    const page = location.pathname.split("/").pop();
    const loadScript = (src) => new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = src;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });

    try {
        if (typeof apiGet !== "function") {
            await loadScript("../public%20assets/js/config.js");
            await loadScript("../public%20assets/js/api/api.js");
        }
    } catch {
        alert("The admin API client could not be loaded.");
        return;
    }

        const authResult = await apiGet("/auth/me");

    if (!authResult.success || !authResult.data) {
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
            alert(result?.error || "The request could not be completed.");
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
            setMessageRow(body, orders.error || "Unable to load incoming orders.", 7);
            return;
        }
        const records = orders.data || [];
        const pending = document.getElementById("dashboardPendingCount");
        if (pending) pending.textContent = String(records.length);
        if (!records.length) return setMessageRow(body, "There are no orders awaiting review.", 7);
        body.replaceChildren();
        records.slice(0, 5).forEach((order) => {
            const row = document.createElement("tr");
            const time = String(order.requested_window_start || "").match(/\d{2}:\d{2}/)?.[0] || "—";
            const total = Number(order.subtotal || 0) - Number(order.locked_discount || 0) + Number(order.delivery_fee || 0);
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
        const [productResult, categoryResult] = await Promise.all([apiGet("/admin/products"), apiGet("/admin/categories")]);
        if (!showResult(productResult) || !showResult(categoryResult)) return;
        const grid = document.getElementById("menuGrid");
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
            const image = document.createElement("img");
            image.src = product.image_url || "";
            image.alt = product.name;
            const title = document.createElement("h3");
            title.textContent = product.name;
            const price = document.createElement("p");
            price.textContent = "R" + Number(product.price).toFixed(2);
            const status = document.createElement("p");
            status.textContent = product.is_available ? "Available" : "Unavailable";
            const edit = document.createElement("button");
            edit.type = "button"; edit.textContent = "Edit"; edit.dataset.adminAction = "product-edit"; edit.dataset.id = product.id;
            const availability = document.createElement("button");
            availability.type = "button"; availability.textContent = product.is_available ? "Mark unavailable" : "Mark available";
            availability.dataset.adminAction = "product-toggle"; availability.dataset.id = product.id;
            const remove = document.createElement("button");
            remove.type = "button"; remove.textContent = "Remove"; remove.dataset.adminAction = "product-delete"; remove.dataset.id = product.id;
            card.append(image, title, price, status, edit, availability, remove);
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
        result.data.forEach((order) => {
            const row = document.createElement("tr");
            row.dataset.status = order.status === "submitted" ? "awaiting" : order.status;
            row.dataset.id = order.id;
            makeCell(row, `#${order.id}`); makeCell(row, order.customer_name);
            makeCell(row, order.fulfilment_type); makeCell(row, order.confirmed_window_start || order.requested_window_start);
            makeCell(row, "R" + Number(order.total).toFixed(2)); makeCell(row, order.status.replaceAll("_", " "));
            const actions = document.createElement("td");
            addAction(actions, "Review", "order-review", order.id);
            row.appendChild(actions); body.appendChild(row);
        });
        window.adminOrders = result.data;
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

    document.addEventListener("click", async (event) => {
        const target = event.target.closest("[data-admin-action], #addCategoryBtn, #addMenuBtn, #addPromotionBtn, #addUserBtn, #updateReportBtn, #printReportBtn, #saveHoursBtn, #saveAccountBtn, .close-category-modal, .close-menu-modal, #closePromotionModal, #closeUserModal, #cancelUserBtn, .close-modal, .refresh-btn");
        if (!target) return;
        const action = target.dataset.adminAction;
        if (!action && !target.matches("#addCategoryBtn, #addMenuBtn, #addPromotionBtn, #addUserBtn, #updateReportBtn, #printReportBtn, #saveHoursBtn, #saveAccountBtn, .close-category-modal, .close-menu-modal, #closePromotionModal, #closeUserModal, #cancelUserBtn, .close-modal, .refresh-btn")) return;
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
        const record = action.startsWith("product-") ? window.adminProducts?.find((item) => Number(item.id) === id)
            : action.startsWith("category-") ? null
            : action.startsWith("promotion-") ? window.adminPromotions?.find((item) => Number(item.id) === id)
            : action.startsWith("staff-") ? window.adminStaff?.find((item) => Number(item.id) === id)
            : action.startsWith("customer-") ? window.adminCustomers?.find((item) => Number(item.id) === id)
            : action.startsWith("order-") ? window.adminOrders?.find((item) => Number(item.id) === id) : null;

        if (action === "product-toggle" && record) {
            const result = await apiPut(`/admin/products/${id}`, { is_available: !record.is_available });
            if (showResult(result)) await loadProducts();
        } else if (action === "product-delete" && confirm("Remove this menu item?")) {
            const result = await apiDelete(`/admin/products/${id}`); if (showResult(result)) await loadProducts();
        } else if (action === "product-edit" && record) {
            editingId = id;
            document.getElementById("itemName").value = record.name;
            document.getElementById("itemCategory").value = record.category_id;
            document.getElementById("itemPrice").value = record.price;
            document.getElementById("itemImage").value = record.image_key || "";
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
            const status = record.status;
            if (status === "submitted") {
                const choice = prompt("Type CONFIRM to accept, DECLINE to decline, or CANCEL to close.");
                if (choice?.toUpperCase() === "CONFIRM") {
                    const result = await apiPost(`/staff/orders/${id}/confirm`, {}); showResult(result);
                } else if (choice?.toUpperCase() === "DECLINE") {
                    const reason = prompt("Reason for declining this order:");
                    if (reason?.trim()) { const result = await apiPost(`/staff/orders/${id}/decline`, { reason: reason.trim() }); showResult(result); }
                }
            } else if (["paid", "preparing", "ready"].includes(status)) {
                const next = { paid: "preparing", preparing: "ready", ready: "completed" }[status];
                if (confirm(`Move order #${id} to ${next}?`)) {
                    const result = await apiPost(`/staff/orders/${id}/advance`, { status: next }); showResult(result);
                }
            } else {
                alert(`Order #${id} is currently ${status.replaceAll("_", " ")}.`);
            }
            await loadOrders();
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
            const body = { category_id: Number(document.getElementById("itemCategory").value), name: document.getElementById("itemName").value.trim(), description: product?.description || "", price: Number(document.getElementById("itemPrice").value), image_url: document.getElementById("itemImage").value.trim() || null, is_available: product?.is_available ?? true, status: product?.status || "active" };
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
    if (pageLoaders[page]) await pageLoaders[page]();
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

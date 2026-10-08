document.addEventListener("DOMContentLoaded", () => {
    const list = document.getElementById("orderList");
    const feedback = document.getElementById("orderFeedback");
    const pageQuery = new URLSearchParams(window.location.search);
    const paymentState = pageQuery.get("payment");
    const returnedOrderId = pageQuery.get("order_id");
    let refreshTimer = null;
    let isRefreshing = false;
    let renderedOrderFingerprint = null;
    let observedActiveOrderId = null;

    const money = (amount) => `R${Number(amount || 0).toFixed(2)}`;
    const element = (tag, className, value) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (value !== undefined) node.textContent = value;
        return node;
    };
    const dateText = (value) => {
        if (!value) return "To be confirmed";
        const date = new Date(String(value).replace(" ", "T"));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
    };
    const setFeedback = (text, visible = true) => {
        feedback.textContent = text;
        feedback.hidden = !visible;
    };

    function showNoCurrentOrder() {
        renderedOrderFingerprint = null;
        list.replaceChildren();
        let firstName = "there";
        try {
            const user = JSON.parse(localStorage.getItem("foodByKUser") || "null");
            firstName = user?.full_name?.trim()?.split(/\s+/)[0] || firstName;
        } catch (error) {
            // Keep the generic greeting when no usable local profile is stored.
        }
        const empty = element("article", "current-order-empty");
        empty.append(
            element("p", "current-order-kicker", "A little room for something delicious"),
            element("h3", "", `Hi ${firstName}, you have no current orders.`),
            element("p", "", "When you place an order, you can follow its progress here."));
        const browse = element("a", "btn btn-primary", "Browse the menu");
        browse.href = "menu.html";
        empty.appendChild(browse);
        list.appendChild(empty);
        setFeedback("", false);
    }

    function renderOrder(bundle) {
        const order = bundle.order || {};
        const items = bundle.items || [];
        const isDelivery = order.fulfilment_type === "delivery";
        const card = element("article", "order-card");
        const top = element("header", "order-card-header");
        const heading = document.createElement("div");
        heading.append(element("h3", "", `Order #${order.id}`), element("p", "order-number", `Placed ${dateText(order.created_at)}`));
        const statusLabel = order.status === "ready"
            ? (isDelivery ? "out for delivery" : "ready for collection")
            : (order.status === "completed"
                ? (isDelivery ? "delivered" : "collected")
                : (order.status || "submitted").replaceAll("_", " "));
        top.append(heading, element("span", "order-status-label", statusLabel));
        card.appendChild(top);

        const content = element("div", "order-card-content");
        const main = element("div", "order-card-main");
        const side = element("aside", "order-card-side");

        if (order.status === "submitted") {
            main.appendChild(element("p", "order-status-message", "Your order is with our team for review. We’ll update this page as it moves through the kitchen."));
        }
        if (order.status === "declined" && order.decline_reason) main.appendChild(element("p", "order-reason", `This order was declined: ${order.decline_reason}`));
        if (order.status === "cancelled") main.appendChild(element("p", "order-reason", "This order has been cancelled."));
        if (order.status === "payment_failed") main.appendChild(element("p", "order-reason", "We couldn’t confirm payment for this order. Please contact Food by K for help."));

        const progress = isDelivery
            ? [
                { status: "submitted", label: "Received" },
                { status: "accepted", label: "Confirmed" },
                { status: "paid", label: "Paid" },
                { status: "preparing", label: "Preparing" },
                { status: "ready", label: "Out for delivery" },
                { status: "completed", label: "Delivered" }
            ]
            : [
                { status: "submitted", label: "Received" },
                { status: "accepted", label: "Confirmed" },
                { status: "paid", label: "Paid" },
                { status: "preparing", label: "Preparing" },
                { status: "ready", label: "Ready for collection" },
                { status: "completed", label: "Collected" }
            ];
        const progressTrack = element("div", `order-progress${isDelivery ? " order-progress-delivery" : " order-progress-collection"}`);
        progressTrack.setAttribute("aria-label", `${isDelivery ? "Delivery" : "Collection"} progress`);
        const current = ["adjusted", "charge_pending", "payment_failed"].includes(order.status) ? "accepted" : order.status;
        const currentIndex = progress.findIndex(({ status }) => status === current);
        progress.forEach(({ status, label }, index) => {
            const step = element("div", `order-step${index < currentIndex ? " is-done" : ""}${index === currentIndex ? " is-current" : ""}`, label);
            if (index === currentIndex) step.setAttribute("aria-current", "step");
            progressTrack.appendChild(step);
        });
        if (["declined", "cancelled"].includes(order.status)) progressTrack.hidden = true;
        main.appendChild(element("h4", "", "Order progress"));
        main.appendChild(progressTrack);

        main.appendChild(element("h4", "", "Order items"));
        const itemList = element("div", "order-items");
        items.forEach((item) => {
            const row = element("div", "order-item");
            const image = document.createElement("img");
            image.className = "order-item-image";
            image.src = item.image_url || "../../images/menu/BoxFresh.jpeg";
            image.alt = item.product_name || "Food by K menu item";
            image.loading = "lazy";
            image.addEventListener("error", () => { image.hidden = true; }, { once: true });
            const name = element("span", "order-item-name", `${item.quantity} × ${item.product_name || "Food by K item"}`);
            const description = element("div", "order-item-description");
            description.append(image, name);
            row.append(description, element("strong", "", money(Number(item.quantity) * Number(item.unit_price))));
            itemList.appendChild(row);
        });
        main.appendChild(itemList);

        side.appendChild(element("h4", "", "Order total"));
        [["Subtotal", order.subtotal], ["Discount", -Number(order.locked_discount || 0)], ["Delivery fee", order.delivery_fee]].forEach(([label, amount]) => {
            const row = element("div", "order-summary-row");
            row.append(element("span", "", label), element("span", "", `${Number(amount) < 0 ? "−" : ""}${money(Math.abs(Number(amount || 0)))}`));
            side.appendChild(row);
        });
        const total = element("div", "order-summary-row order-summary-total");
        total.append(element("strong", "", "Total"), element("strong", "", money(Math.max(0, Number(order.subtotal || 0) - Number(order.locked_discount || 0)) + Number(order.delivery_fee || 0))));
        side.appendChild(total);
        const meta = element("div", "order-meta");
        const fulfilment = element("div");
        fulfilment.append(element("strong", "", order.fulfilment_type === "delivery" ? "Delivery" : "Collection"), element("span", "", order.fulfilment_type === "delivery" ? (bundle.address?.raw_address || "Delivery address on file") : "Collect from Food by K"));
        const time = element("div");
        time.append(element("strong", "", order.confirmed_window_start ? "Confirmed time" : "Requested time"), element("span", "", dateText(order.confirmed_window_start || order.requested_window_start)));
        meta.append(fulfilment, time);
        side.appendChild(meta);

        if (order.status === "submitted" && paymentState === "pending") {
            side.appendChild(element("p", "order-muted", "Secure card setup is returning to us. Your card is not charged while the team reviews your order."));
        }
        if (order.status === "submitted") {
            const actions = element("div", "order-actions");
            const cancel = element("button", "btn order-cancel", "Cancel order");
            cancel.type = "button";
            cancel.addEventListener("click", async () => {
                if (!window.confirm("Cancel this order? This cannot be undone.")) return;
                cancel.disabled = true;
                cancel.textContent = "Cancelled";
                statusLabel.textContent = "cancelled";
                progressTrack.hidden = true;
                const optimisticNotice = element("p", "order-reason", "Your cancellation is being saved…");
                main.insertBefore(optimisticNotice, main.firstChild);
                const result = await apiPost(`/orders/${order.id}/cancel`, { reason: "Customer request" });
                if (!result.success) {
                    const error = result.error || "Unable to cancel this order. Please try again.";
                    await loadOrders(true);
                    setFeedback(error);
                    return;
                }
                await loadOrders();
            });
            actions.appendChild(cancel);
            side.appendChild(actions);
        }
        content.append(main, side);
        card.appendChild(content);
        return card;
    }

    async function loadOrders(forceRender = false) {
        if (isRefreshing) return;
        isRefreshing = true;
        const result = await apiGet("/orders?limit=1");
        isRefreshing = false;
        if (!result.success) {
            if (!list.childElementCount) setFeedback(result.error || "Unable to load your orders.");
            if (result.status === 401) {
                const login = element("a", "btn btn-primary", "Log in to view your orders");
                login.href = "../auth/login.html";
                feedback.appendChild(document.createTextNode(" "));
                feedback.appendChild(login);
            }
            return;
        }
        const newestOrder = result.data?.orders?.[0] || null;
        if (!newestOrder) {
            observedActiveOrderId = null;
            showNoCurrentOrder();
            return;
        }
        setFeedback("Order updates refresh automatically while this page is open.", false);
        const detail = await apiGet(`/orders/${newestOrder.id}`);
        if (!detail.success) {
            if (!list.childElementCount) setFeedback(detail.error || "Unable to load your newest order.");
            return;
        }

        const { order, items, address } = detail.data;
        if (["completed", "declined", "cancelled"].includes(order?.status)) {
            const completedOrderWasBeingTracked = String(observedActiveOrderId) === String(order.id)
                || String(returnedOrderId) === String(order.id);
            const alreadyThanked = sessionStorage.getItem("foodByKThankedOrder") === String(order.id);
            if (order.status === "completed" && completedOrderWasBeingTracked && !alreadyThanked) {
                sessionStorage.setItem("foodByKCompletedOrder", String(order.id));
                window.location.replace(`menu.html?order_complete=1&order_id=${encodeURIComponent(order.id)}`);
                return;
            }
            observedActiveOrderId = null;
            showNoCurrentOrder();
            return;
        }
        observedActiveOrderId = order?.id ?? newestOrder.id;

        const fingerprint = JSON.stringify({
            order: order && {
                id: order.id,
                status: order.status,
                updated_at: order.updated_at,
                created_at: order.created_at,
                decline_reason: order.decline_reason,
                fulfilment_type: order.fulfilment_type,
                subtotal: order.subtotal,
                locked_discount: order.locked_discount,
                delivery_fee: order.delivery_fee,
                confirmed_window_start: order.confirmed_window_start,
                requested_window_start: order.requested_window_start
            },
            items: (items || []).map(({ product_name, quantity, unit_price }) => ({ product_name, quantity, unit_price })),
            address: address?.raw_address || null
        });
        if (!forceRender && fingerprint === renderedOrderFingerprint) return;

        const scrollPosition = window.scrollY;
        renderedOrderFingerprint = fingerprint;
        list.replaceChildren(renderOrder(detail.data));
        window.requestAnimationFrame(() => window.scrollTo(0, scrollPosition));
    }

    loadOrders().then(() => {
        refreshTimer = window.setInterval(loadOrders, 10000);
    });
    window.addEventListener("beforeunload", () => window.clearInterval(refreshTimer), { once: true });
});

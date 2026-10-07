document.addEventListener("DOMContentLoaded", () => {
    const list = document.getElementById("orderList");
    const feedback = document.getElementById("orderFeedback");
    const paymentState = new URLSearchParams(window.location.search).get("payment");
    const progress = ["submitted", "accepted", "paid", "preparing", "ready", "completed"];
    let refreshTimer = null;
    let isRefreshing = false;
    let renderedOrderFingerprint = null;

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

    function renderOrder(bundle) {
        const order = bundle.order || {};
        const items = bundle.items || [];
        const card = element("article", "order-card");
        const top = element("header", "order-card-header");
        const heading = document.createElement("div");
        heading.append(element("h3", "", `Order #${order.id}`), element("p", "order-number", `Placed ${dateText(order.created_at)}`));
        const statusLabel = (order.status || "submitted").replaceAll("_", " ");
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

        const progressTrack = element("div", "order-progress");
        progressTrack.setAttribute("aria-label", "Order progress");
        const current = ["adjusted", "charge_pending", "payment_failed"].includes(order.status) ? "accepted" : order.status;
        const currentIndex = progress.indexOf(current);
        progress.forEach((status, index) => {
            const step = element("div", `order-step${index < currentIndex ? " is-done" : ""}${index === currentIndex ? " is-current" : ""}`, ({ submitted: "Received", accepted: "Confirmed", paid: "Paid", preparing: "Preparing", ready: "Ready", completed: "Done" })[status]);
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
            row.append(element("span", "order-item-name", `${item.quantity} × ${item.product_name || "Menu item"}`), element("strong", "", money(Number(item.quantity) * Number(item.unit_price))));
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
                cancel.textContent = "Cancelling…";
                const result = await apiPost(`/orders/${order.id}/cancel`, { reason: "Customer request" });
                if (!result.success) {
                    cancel.disabled = false;
                    cancel.textContent = "Cancel order";
                    setFeedback(result.error || "Unable to cancel this order. Please try again.");
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

    async function loadOrders() {
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
            renderedOrderFingerprint = null;
            list.replaceChildren();
            setFeedback("You don’t have any orders yet. Browse the menu when you’re ready for something good.");
            return;
        }
        setFeedback("Order updates refresh automatically while this page is open.", false);
        const detail = await apiGet(`/orders/${newestOrder.id}`);
        if (!detail.success) {
            if (!list.childElementCount) setFeedback(detail.error || "Unable to load your newest order.");
            return;
        }

        const { order, items, address } = detail.data;
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
        if (fingerprint === renderedOrderFingerprint) return;

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

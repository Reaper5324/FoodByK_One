document.addEventListener("DOMContentLoaded", async () => {
    const balance = document.getElementById("loyaltyBalance");
    const value = document.getElementById("loyaltyEstimatedValue");
    const balanceCard = document.querySelector(".loyalty-balance-card");
    const history = document.getElementById("loyaltyHistory");
    const message = document.getElementById("loyaltyMessage");
    const loadMoreButton = document.getElementById("loyaltyLoadMore");
    if (!balance || !value || !history || !message || !loadMoreButton) return;

    const formatPoints = (amount) => Number(amount || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    const formatDate = (value) => {
        if (!value) return "Date unavailable";
        const date = new Date(String(value).replace(" ", "T"));
        return Number.isNaN(date.getTime())
            ? "Date unavailable"
            : date.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
    };

    const showMessage = (text, isError = false) => {
        message.hidden = !text;
        message.textContent = text;
        message.classList.toggle("is-error", isError);
    };

    const renderSkeletons = () => {
        history.replaceChildren();
        for (let index = 0; index < 3; index += 1) {
            const skeleton = document.createElement("div");
            skeleton.className = "loyalty-order-card skeleton-block";
            skeleton.setAttribute("aria-hidden", "true");
            history.appendChild(skeleton);
        }
    };

    const renderOrder = (order) => {
        const card = document.createElement("article");
        const points = Number(order.loyalty_points_earned || 0);
        card.className = `loyalty-order-card${points > 0 ? "" : " is-no-points"}`;

        const details = document.createElement("div");
        const title = document.createElement("h3");
        title.textContent = `Order #${order.id}`;
        const meta = document.createElement("p");
        meta.className = "loyalty-order-meta";
        const status = String(order.status || "order").replaceAll("_", " ");
        const fulfilment = String(order.fulfilment_type || "order").replaceAll("_", " ");
        meta.textContent = formatDate(order.created_at) + " | " + fulfilment + " | " + status + " | R" + Number(order.total || 0).toFixed(2);
        details.append(title, meta);

        const earned = document.createElement("p");
        earned.className = "loyalty-order-points";
        earned.textContent = points > 0 ? `+${formatPoints(points)} points` : "No points earned";
        card.append(details, earned);
        return card;
    };

    let offset = 0;
    let totalOrders = 0;
    let loading = false;

    const loadHistory = async (reset = false) => {
        if (loading) return;
        loading = true;
        if (reset) {
            offset = 0;
            renderSkeletons();
            history.setAttribute("aria-busy", "true");
        }
        loadMoreButton.disabled = true;
        try {
            const result = await apiGet(`/loyalty/history?limit=50&offset=${offset}`);
            if (!result?.success || !result.data) {
                throw new Error(result?.error || "Sign in to view your loyalty rewards.");
            }

            balance.textContent = formatPoints(result.data.points);
            value.textContent = `Worth R${Number(result.data.estimated_value || 0).toFixed(2)} at checkout`;
            balanceCard.setAttribute("aria-busy", "false");
            totalOrders = Number(result.data.total_orders || 0);
            const orders = Array.isArray(result.data.orders) ? result.data.orders : [];

            if (reset) history.replaceChildren();
            if (!orders.length && reset) {
                const empty = document.createElement("p");
                empty.className = "loyalty-empty-state";
                empty.textContent = "Your order points will appear here after an order is paid. Browse the menu when you are ready.";
                history.appendChild(empty);
            } else {
                orders.forEach((order) => history.appendChild(renderOrder(order)));
            }
            offset += orders.length;
            loadMoreButton.hidden = offset >= totalOrders;
            showMessage("");
        } catch (error) {
            if (reset) {
                balance.textContent = "—";
                value.textContent = "Sign in to view your rewards.";
                balanceCard.setAttribute("aria-busy", "false");
                history.replaceChildren();
            }
            loadMoreButton.hidden = true;
            showMessage(error.message || "Unable to load loyalty history right now.", true);
        } finally {
            history.setAttribute("aria-busy", "false");
            loadMoreButton.disabled = false;
            loading = false;
        }
    };

    loadMoreButton.addEventListener("click", () => loadHistory());
    loadHistory(true);
});

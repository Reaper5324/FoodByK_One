document.addEventListener("DOMContentLoaded", () => {
    loadHomeMeals();
    loadHomePromotions();

    const modal = document.getElementById("latestPromotionModal");
    modal?.addEventListener("close", () => {
        rememberDismissedPromotion(modal.dataset.promotionId);
    });

    document.getElementById("closePromotionModal")?.addEventListener("click", () => {
        modal?.close();
    });
});

const localMealImages = {
    "bbl": "../images/menu/BBL.jpeg",
    "boxfresh": "../images/menu/BoxFresh.jpeg",
    "double sided cheese wrap": "../images/menu/DoubleSided_cheese_wrap.jpeg",
    "kay special": "../images/menu/Kay_special.jpeg",
    "platter for one": "../images/menu/Platter_for_one.jpeg"
};

async function loadHomeMeals() {
    const grid = document.getElementById("homeMealGrid");
    if (!grid || typeof API_BASE_URL === "undefined") return;

    try {
        const response = await fetch(`${API_BASE_URL}/products`, {
            headers: { Accept: "application/json" }
        });
        if (!response.ok) return;

        const result = await response.json();
        if (!result.success || !Array.isArray(result.data) || result.data.length === 0) return;

        const products = result.data
            .slice()
            .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0))
            .slice(0, 3);

        grid.replaceChildren(...products.map((product, index) => createMealCard(product, index)));
    } catch (error) {
        // The local menu highlights remain visible if the public API is unavailable.
        console.info("Home menu highlights are using the local fallback.");
    }
}

function createMealCard(product, index) {
    const card = document.createElement("article");
    card.className = `meal-card${index === 0 ? " meal-card-featured" : ""}`;

    const link = document.createElement("a");
    link.className = "meal-image";
    link.href = "../pages/customer/menu.html";
    link.setAttribute("aria-label", `Browse ${product.name || "this meal"} on the menu`);

    const image = document.createElement("img");
    const productName = String(product.name || "");
    image.src = product.image_url || localMealImages[productName.toLowerCase()] || "../images/foodbyk.png";
    image.alt = productName ? `${productName} from Food by K` : "Food by K menu item";
    image.loading = "lazy";
    image.addEventListener("error", () => {
        image.src = "../images/foodbyk.png";
    }, { once: true });

    const badge = document.createElement("span");
    badge.className = "meal-image-label";
    badge.textContent = index === 0 ? "Fresh from our menu" : "Made with flavour";
    link.append(image, badge);

    const info = document.createElement("div");
    info.className = "meal-info";
    const details = document.createElement("div");
    const kicker = document.createElement("p");
    kicker.className = "meal-kicker";
    kicker.textContent = product.description || "A Food by K favourite";
    const title = document.createElement("h3");
    title.textContent = productName || "Something delicious";
    details.append(kicker, title);

    const price = document.createElement("strong");
    price.className = "meal-price";
    const numericPrice = Number(product.price);
    price.textContent = Number.isFinite(numericPrice)
        ? `R${numericPrice.toFixed(2).replace(/\.00$/, "")}`
        : "On the menu";
    info.append(details, price);
    card.append(link, info);
    return card;
}

async function loadHomePromotions() {
    const list = document.getElementById("homePromotionList");
    if (!list || typeof API_BASE_URL === "undefined") return;

    try {
        const response = await fetch(`${API_BASE_URL}/promotions/active`, {
            headers: { Accept: "application/json" }
        });
        if (!response.ok) return;

        const result = await response.json();
        if (!result.success || !Array.isArray(result.data)) return;
        if (result.data.length === 0) return;

        const promotions = result.data.slice().sort((a, b) => {
            const dateDifference = new Date(b.created_at || 0) - new Date(a.created_at || 0);
            return dateDifference || Number(b.id || 0) - Number(a.id || 0);
        });
        showLatestPromotion(promotions[0]);
        list.replaceChildren(...promotions.slice(0, 2).map(createPromotionCard));
    } catch (error) {
        // Keep the inviting empty state if promotions cannot be loaded.
        console.info("Home promotions are using the default message.");
    }
}

function showLatestPromotion(promotion) {
    const modal = document.getElementById("latestPromotionModal");
    if (!modal || typeof modal.showModal !== "function") return;

    const promotionId = String(promotion.id || promotion.code || "latest");
    if (isPromotionDismissed(promotionId)) return;

    const value = Number(promotion.discount_value);
    const type = promotion.discount_type;
    let title = "Food by K special";
    if (type === "percentage" && Number.isFinite(value)) title = `${value}% off`;
    else if (type === "fixed_amount" && Number.isFinite(value)) title = `R${value.toFixed(2).replace(/\.00$/, "")} off`;
    else if (type === "buy_one_get_one") title = "Buy one, get one";
    else if (type === "free_delivery") title = "Free delivery";

    document.getElementById("promotionModalTitle").textContent = title;
    document.getElementById("promotionModalDescription").textContent = "Use this offer when you place your order.";
    document.getElementById("promotionModalCode").textContent = promotion.code ? `Use code: ${promotion.code}` : "Ask us how to claim this offer.";

    const expiry = document.getElementById("promotionModalExpiry");
    expiry.textContent = promotion.end_date ? `Available until ${formatPromotionDate(promotion.end_date)}` : "Available for a limited time";
    modal.dataset.promotionId = promotionId;
    modal.showModal();
}

function isPromotionDismissed(promotionId) {
    try {
        return sessionStorage.getItem("foodByKDismissedPromotion") === promotionId;
    } catch (error) {
        return false;
    }
}

function rememberDismissedPromotion(promotionId) {
    if (!promotionId) return;
    try {
        sessionStorage.setItem("foodByKDismissedPromotion", promotionId);
    } catch (error) {
        // Closing the dialog still works when browser storage is unavailable.
    }
}

function createPromotionCard(promotion) {
    const card = document.createElement("article");
    card.className = "promo-card";

    const badge = document.createElement("span");
    badge.className = "promo-card-label";
    badge.textContent = "A little something extra";

    const title = document.createElement("h3");
    const value = Number(promotion.discount_value);
    const type = promotion.discount_type;
    if (type === "percentage") title.textContent = `${value}% off`;
    else if (type === "fixed_amount") title.textContent = `R${value.toFixed(2).replace(/\.00$/, "")} off`;
    else if (type === "buy_one_get_one") title.textContent = "Buy one, get one";
    else if (type === "free_delivery") title.textContent = "Free delivery";
    else title.textContent = "Food by K special";

    const description = document.createElement("p");
    description.textContent = "Use this offer when you place your order.";

    const code = document.createElement("span");
    code.className = "promo-code";
    code.textContent = `CODE: ${promotion.code || "ASK US"}`;

    card.append(badge, title, description, code);
    if (promotion.end_date) {
        const expiry = document.createElement("p");
        expiry.className = "promo-dates";
        expiry.textContent = `Available until ${formatPromotionDate(promotion.end_date)}`;
        card.append(expiry);
    }
    return card;
}

function formatPromotionDate(dateValue) {
    const date = new Date(`${dateValue}T00:00:00`);
    return Number.isNaN(date.getTime())
        ? dateValue
        : new Intl.DateTimeFormat("en-ZA", { day: "numeric", month: "short", year: "numeric" }).format(date);
}

document.addEventListener("DOMContentLoaded", async () => {
    const detail = document.getElementById("menuItemDetail");
    const message = document.getElementById("menuItemMessage");
    if (!detail || !message) return;

    const showMessage = (text, isError = false) => {
        message.hidden = !text;
        message.textContent = text;
        message.classList.toggle("is-error", isError);
        if (text) window.foodByKNotify?.(text, isError ? "error" : "success");
    };

    const itemId = new URLSearchParams(window.location.search).get("id");
    if (!itemId || !/^\d+$/.test(itemId) || Number(itemId) < 1) {
        detail.hidden = true;
        detail.setAttribute("aria-busy", "false");
        showMessage("That menu item could not be found. Browse the menu to choose another item.", true);
        return;
    }

    const result = await apiGet(`/products/${encodeURIComponent(itemId)}`);
    if (!result?.success || !result.data) {
        detail.hidden = true;
        detail.setAttribute("aria-busy", "false");
        showMessage(result?.error || "This menu item is unavailable right now. Browse the menu to see today's options.", true);
        return;
    }

    const product = result.data;
    const promotionsResult = await apiGet("/promotions/active").catch(() => null);
    const promotions = promotionsResult?.success && Array.isArray(promotionsResult.data) ? promotionsResult.data : [];
    const eligiblePromotions = promotions
        .filter((promotion) => {
            const targets = Array.isArray(promotion.target_product_ids) ? promotion.target_product_ids.map(Number) : [];
            return (!targets.length || targets.includes(Number(product.id)))
                && ["percentage", "fixed_amount", "buy_one_get_one"].includes(promotion.discount_type);
        })
        .sort((a, b) => {
            const order = { percentage: 0, buy_one_get_one: 1, fixed_amount: 2 };
            return order[a.discount_type] - order[b.discount_type]
                || Number(b.discount_value || 0) - Number(a.discount_value || 0);
        });
    const supportLink = document.getElementById("menuItemSupportLink");
    const reportLink = document.createElement("a");
    reportLink.href = `support.html?category=menu_item&product_id=${encodeURIComponent(product.id)}&context=${encodeURIComponent(product.name || `menu item ${product.id}`)}`;
    reportLink.textContent = `Report an issue with ${product.name || "this menu item"}`;
    supportLink.replaceChildren(reportLink);
    supportLink.hidden = false;
    detail.replaceChildren();
    detail.setAttribute("aria-busy", "false");

    const media = document.createElement(product.image_url ? "img" : "div");
    media.className = "menu-item-media";
    if (media instanceof HTMLImageElement) {
        media.src = product.image_url;
        media.alt = product.name || "Food by K menu item";
        media.addEventListener("error", () => {
            const placeholder = document.createElement("div");
            placeholder.className = "menu-item-media menu-image-placeholder";
            placeholder.textContent = "Image unavailable";
            media.replaceWith(placeholder);
        }, { once: true });
    } else {
        media.classList.add("menu-image-placeholder");
        media.textContent = "Image unavailable";
    }

    const copy = document.createElement("div");
    copy.className = "menu-item-copy";
    const kicker = document.createElement("p");
    kicker.className = "section-tag";
    kicker.textContent = "Made fresh for you";
    const title = document.createElement("h1");
    title.textContent = product.name || "Menu item";
    const description = document.createElement("p");
    description.className = "menu-item-description";
    description.textContent = product.description || "A Food by K favourite, made fresh for you.";
    const options = Array.isArray(product.options) ? product.options.filter((option) => option.is_available !== false) : [];
    let optionPicker = null;
    if (options.length) {
        optionPicker = document.createElement("fieldset");
        optionPicker.className = "menu-item-options";
        const legend = document.createElement("legend");
        legend.textContent = "Make it yours · optional extras";
        optionPicker.appendChild(legend);
        options.forEach((option) => {
            const label = document.createElement("label");
            label.className = "menu-item-option";
            const checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.value = String(option.id);
            checkbox.dataset.priceDelta = String(Number(option.price_delta || 0));
            checkbox.addEventListener("change", updatePrice);
            const name = document.createElement("span");
            name.textContent = option.name;
            const optionPrice = document.createElement("strong");
            const delta = Number(option.price_delta || 0);
            optionPrice.textContent = delta > 0 ? `+R${delta.toFixed(2)}` : "Free";
            label.append(checkbox, name, optionPrice);
            optionPicker.appendChild(label);
        });
    }
    const price = document.createElement("p");
    price.className = "menu-item-price";
    const basePrice = Number(product.price || 0);
    const pricePromotion = eligiblePromotions.find((promotion) => promotion.discount_type === "percentage");
    const discount = pricePromotion ? Math.min(100, Math.max(0, Number(pricePromotion.discount_value || 0))) : 0;
    function updatePrice() {
        const selectedOptions = optionPicker ? [...optionPicker.querySelectorAll("input:checked")] : [];
        const customizationTotal = selectedOptions.reduce((sum, input) => sum + Number(input.dataset.priceDelta || 0), 0);
        const currentBase = basePrice + customizationTotal;
        price.replaceChildren();
        if (pricePromotion) {
        const original = document.createElement("span");
        original.className = "menu-price-original";
        original.textContent = `Was R${currentBase.toFixed(2)}`;
        const current = document.createElement("span");
        current.textContent = `Now R${(currentBase * (1 - discount / 100)).toFixed(2)}`;
        const terms = document.createElement("small");
        terms.className = "menu-price-condition";
        terms.textContent = pricePromotion.code
            ? `Use code ${String(pricePromotion.code).toUpperCase()} at checkout`
            : "Discount applied at checkout";
        price.append(original, current, terms);
        } else {
            price.textContent = `R${currentBase.toFixed(2)}`;
        }
    }
    updatePrice();
    let promotionList = null;
    if (eligiblePromotions.length) {
        promotionList = document.createElement("div");
        promotionList.className = "menu-promotion-list menu-item-promotion-list";
        eligiblePromotions.forEach((promotion) => {
            const badge = document.createElement("span");
            badge.className = "menu-promotion-badge";
            const value = Number(promotion.discount_value || 0);
            const label = promotion.discount_type === "percentage" ? `${value}% off`
                : promotion.discount_type === "buy_one_get_one" ? "Buy one, get one free"
                    : `R${value.toFixed(2)} off eligible items`;
            badge.textContent = promotion.code ? `${label} · code ${String(promotion.code).toUpperCase()}` : label;
            promotionList.appendChild(badge);
        });
    }
    const addButton = document.createElement("button");
    addButton.className = "btn btn-primary";
    addButton.type = "button";
    addButton.textContent = "Add to cart";
    addButton.addEventListener("click", async () => {
        addButton.disabled = true;
        addButton.textContent = "Adding...";
        const optionIds = optionPicker ? [...optionPicker.querySelectorAll("input:checked")].map((input) => Number(input.value)) : [];
        const addResult = await apiPost("/cart/items", { product_id: product.id, quantity: 1, option_ids: optionIds });
        if (addResult?.success) {
            const offer = eligiblePromotions.find((promotion) => promotion.code);
            if (offer) {
                try {
                    if (!sessionStorage.getItem("foodbykSelectedPromotion")) {
                        sessionStorage.setItem("foodbykSelectedPromotion", String(offer.code).toUpperCase());
                    }
                } catch (error) {
                    console.warn("Unable to save the promotion for checkout.", error);
                }
            }
            addButton.textContent = "Added to cart";
            showMessage(`${product.name} was added to your cart.`);
            window.setTimeout(() => {
                addButton.textContent = "Add to cart";
                addButton.disabled = false;
            }, 1200);
            return;
        }

        addButton.disabled = false;
        addButton.textContent = "Add to cart";
        showMessage(
            addResult?.status === 401
                ? "Please log in or create an account before adding items to your cart."
                : (addResult?.error || "Unable to add this item to your cart."),
            true
        );
    });

    copy.append(kicker, title, description);
    if (optionPicker) copy.appendChild(optionPicker);
    copy.appendChild(price);
    if (promotionList) copy.appendChild(promotionList);
    copy.appendChild(addButton);
    detail.append(media, copy);
    document.title = `${product.name || "Menu item"} | Food by K`;
});

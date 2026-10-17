document.addEventListener("DOMContentLoaded", async () => {
    const detail = document.getElementById("menuItemDetail");
    const message = document.getElementById("menuItemMessage");
    if (!detail || !message) return;

    const showMessage = (text, isError = false) => {
        message.hidden = !text;
        message.textContent = text;
        message.classList.toggle("is-error", isError);
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
    const price = document.createElement("p");
    price.className = "menu-item-price";
    price.textContent = `R${Number(product.price || 0).toFixed(2)}`;
    const addButton = document.createElement("button");
    addButton.className = "btn btn-primary";
    addButton.type = "button";
    addButton.textContent = "Add to cart";
    addButton.addEventListener("click", async () => {
        addButton.disabled = true;
        addButton.textContent = "Adding...";
        const addResult = await apiPost("/cart/items", { product_id: product.id, quantity: 1 });
        if (addResult?.success) {
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

    copy.append(kicker, title, description, price, addButton);
    detail.append(media, copy);
    document.title = `${product.name || "Menu item"} | Food by K`;
});

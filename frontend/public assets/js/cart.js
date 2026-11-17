document.addEventListener("DOMContentLoaded", async () => {
    const cartItems = document.getElementById("cartItems");
    const cartMessage = document.getElementById("cartMessage");
    const cartTotal = document.getElementById("cartTotal");
    const checkoutLink = document.getElementById("checkoutLink");
    let messageTimer = null;

    const showMessage = (message, isError = false, autoHide = false) => {
        clearTimeout(messageTimer);
        cartMessage.hidden = false;
        cartMessage.textContent = message;
        cartMessage.classList.toggle("is-error", isError);
        window.foodByKNotify?.(message, isError ? "error" : "success");
        if (autoHide && !isError) {
            messageTimer = setTimeout(() => { cartMessage.hidden = true; }, 3200);
        }
    };

    const showCartSkeletons = () => {
        cartItems.setAttribute("aria-busy", "true");
        cartItems.replaceChildren();
        for (let index = 0; index < 2; index += 1) {
            const row = document.createElement("article");
            row.className = "cart-row cart-skeleton-row";
            row.setAttribute("aria-hidden", "true");
            const image = document.createElement("div");
            image.className = "skeleton cart-skeleton-image";
            const info = document.createElement("div");
            info.className = "cart-skeleton-info";
            const name = document.createElement("span");
            name.className = "skeleton cart-skeleton-name";
            const price = document.createElement("span");
            price.className = "skeleton cart-skeleton-price";
            info.append(name, price);
            const quantity = document.createElement("span");
            quantity.className = "skeleton cart-skeleton-quantity";
            const subtotal = document.createElement("span");
            subtotal.className = "skeleton cart-skeleton-subtotal";
            const remove = document.createElement("span");
            remove.className = "skeleton cart-skeleton-remove";
            row.append(image, info, quantity, subtotal, remove);
            cartItems.appendChild(row);
        }
    };

    const renderRecommendations = (products) => {
        const section = document.createElement("section");
        section.className = "cart-recommendations";
        section.setAttribute("aria-labelledby", "cartRecommendationsHeading");
        const heading = document.createElement("div");
        heading.className = "cart-recommendations-heading";
        const title = document.createElement("h2");
        title.id = "cartRecommendationsHeading";
        title.textContent = "Meals you might like";
        const intro = document.createElement("p");
        intro.textContent = "Add a favourite to get your order started.";
        heading.append(title, intro);
        section.appendChild(heading);

        const grid = document.createElement("div");
        grid.className = "cart-recommendation-grid";
        products.slice(0, 3).forEach((product) => {
            const card = document.createElement("article");
            card.className = "cart-recommendation-card";
            const link = document.createElement("a");
            link.className = "cart-recommendation-link";
            link.href = `menu-item.html?id=${encodeURIComponent(product.id)}`;
            const image = document.createElement("img");
            image.src = product.image_url || "../../images/foodbyk.png";
            image.alt = product.name ? `${product.name} from Food by K` : "Food by K meal";
            image.addEventListener("error", () => { image.hidden = true; }, { once: true });
            const name = document.createElement("strong");
            name.textContent = product.name || "Menu favourite";
            const description = document.createElement("span");
            description.textContent = product.description || "Made fresh and ready to order.";
            link.append(image, name, description);
            const footer = document.createElement("div");
            footer.className = "cart-recommendation-footer";
            const price = document.createElement("strong");
            price.textContent = `R${Number(product.price || 0).toFixed(2)}`;
            const add = document.createElement("button");
            add.type = "button";
            add.className = "cart-recommendation-add";
            add.textContent = "Add to cart";
            add.addEventListener("click", async () => {
                add.disabled = true;
                add.textContent = "Adding…";
                const result = await apiPost("/cart/items", { product_id: Number(product.id), quantity: 1 });
                if (result?.success) {
                    await loadCart();
                    showMessage(`${product.name || "Meal"} added to your cart.`, false, true);
                    return;
                }
                add.disabled = false;
                add.textContent = "Add to cart";
                showMessage(result?.error || "Unable to add this meal to your cart.", true);
                if (result?.status === 401) {
                    const login = document.createElement("a");
                    login.href = "../auth/login.html";
                    login.textContent = " Log in";
                    cartMessage.appendChild(login);
                }
            });
            footer.append(price, add);
            card.append(link, footer);
            grid.appendChild(card);
        });
        if (!products.length) {
            const browse = document.createElement("a");
            browse.className = "btn btn-primary";
            browse.href = "menu.html";
            browse.textContent = "Browse the menu";
            grid.appendChild(browse);
        }
        section.appendChild(grid);
        cartItems.appendChild(section);
    };

    const loadCart = async () => {
        showCartSkeletons();
        cartTotal.hidden = true;
        checkoutLink.hidden = true;

        const [cartResult, productResult] = await Promise.all([
            apiGet("/cart"),
            apiGet("/products")
        ]);

        if (!cartResult.success) {
            cartItems.replaceChildren();
            cartItems.setAttribute("aria-busy", "false");
            showMessage(cartResult.error || "Please log in to view your cart.", true);
            const loginLink = document.createElement("a");
            loginLink.href = "../auth/login.html";
            loginLink.textContent = " Log in";
            cartMessage.appendChild(loginLink);
            return;
        }

        if (!productResult.success) {
            cartItems.replaceChildren();
            cartItems.setAttribute("aria-busy", "false");
            showMessage(productResult.error || "Unable to load product details.", true);
            return;
        }

        const products = new Map(productResult.data.map((product) => [Number(product.id), product]));
        const items = cartResult.data.items || [];
        const limits = cartResult.data.limits || { max_item_quantity: 6, max_cart_items: 10 };
        const maxItemQuantity = Number(limits.max_item_quantity) || 6;
        const maxCartItems = Number(limits.max_cart_items) || 10;
        const cartQuantity = items.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);

        if (items.length === 0) {
            cartItems.replaceChildren();
            cartItems.setAttribute("aria-busy", "false");
            showMessage("Your cart is empty.");
            const isAvailable = (value) => ![false, 0, "0", "false"].includes(
                typeof value === "string" ? value.trim().toLowerCase() : value
            );
            renderRecommendations(productResult.data.filter((product) =>
                isAvailable(product.is_available) && !["inactive", "removed"].includes(String(product.status || "").toLowerCase())
            ));
            return;
        }

        cartMessage.hidden = true;
        cartItems.replaceChildren();
        cartItems.setAttribute("aria-busy", "false");
        const cartLimitNote = document.createElement("p");
        cartLimitNote.className = "cart-limit-note";
        cartLimitNote.textContent = `${cartQuantity} of ${maxCartItems} items · Up to ${maxItemQuantity} of each menu item, including customizations.`;
        cartItems.appendChild(cartLimitNote);
        let total = 0;

        items.forEach((item) => {
            const product = products.get(Number(item.product_id));
            const row = document.createElement("article");
            row.className = "cart-row";

            const image = document.createElement("img");
            image.className = "cart-item-image";
            image.src = product?.image_url || "../../images/foodbyk.png";
            image.alt = product?.name ? `${product.name} from Food by K` : "Food by K menu item";
            image.addEventListener("error", () => { image.hidden = true; }, { once: true });

            const itemInfo = document.createElement("div");
            itemInfo.className = "cart-item-info";
            const name = document.createElement("strong");
            name.className = "cart-item-name";
            name.textContent = product?.name || `Item ${item.product_id}`;
            const customizations = Array.isArray(item.customizations) ? item.customizations : [];
            const customizationTotal = customizations.reduce((sum, option) => sum + Number(option.price_delta || 0), 0);
            const price = Number(product?.price ?? item.unit_price ?? 0) + customizationTotal;
            const unitPrice = document.createElement("span");
            unitPrice.className = "cart-item-unit-price";
            unitPrice.textContent = `R${price.toFixed(2)} each`;
            itemInfo.append(name, unitPrice);
            if (customizations.length) {
                const extras = document.createElement("span");
                extras.className = "cart-item-customizations";
                extras.textContent = `Extras: ${customizations.map((option) => option.name).join(", ")}`;
                itemInfo.appendChild(extras);
            }

            const currentQuantity = Number(item.quantity) || 1;
            const sameProductQuantity = items
                .filter((other) => Number(other.product_id) === Number(item.product_id))
                .reduce((sum, other) => sum + Number(other.quantity || 0), 0);
            const otherProductQuantity = sameProductQuantity - currentQuantity;
            const otherCartQuantity = cartQuantity - currentQuantity;
            const rowMaximum = Math.max(1, Math.min(
                maxItemQuantity - otherProductQuantity,
                maxCartItems - otherCartQuantity
            ));
            const quantity = document.createElement("input");
            // Use text so browsers do not retain an invalid step-mismatch value
            // (for example 1.5) before our whole-quantity handler can restore it.
            quantity.type = "text";
            quantity.pattern = "[0-9]*";
            quantity.max = String(Math.max(currentQuantity, rowMaximum));
            quantity.step = "1";
            quantity.inputMode = "numeric";
            quantity.value = String(currentQuantity);
            quantity.id = `cart-quantity-${item.id}`;
            quantity.setAttribute("aria-label", `Quantity for ${name.textContent}`);
            const quantityControl = document.createElement("div");
            quantityControl.className = "cart-quantity-control";
            const decrease = document.createElement("button");
            decrease.type = "button";
            decrease.className = "cart-quantity-button";
            decrease.textContent = "−";
            decrease.setAttribute("aria-label", `Decrease ${name.textContent} quantity`);
            decrease.disabled = currentQuantity <= 1;
            const increase = document.createElement("button");
            increase.type = "button";
            increase.className = "cart-quantity-button";
            increase.textContent = "+";
            increase.setAttribute("aria-label", `Increase ${name.textContent} quantity`);
            increase.disabled = currentQuantity >= rowMaximum;
            quantityControl.append(decrease, quantity, increase);
            const quantityLabel = document.createElement("div");
            quantityLabel.className = "cart-quantity-label";
            const quantityText = document.createElement("label");
            quantityText.htmlFor = quantity.id;
            quantityText.textContent = "Quantity";
            quantityLabel.append(quantityText, quantityControl);
            const lineTotal = price * Number(item.quantity);
            total += lineTotal;

            const subtotal = document.createElement("span");
            subtotal.className = "cart-line-total";
            subtotal.textContent = `R${lineTotal.toFixed(2)}`;

            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "cart-remove-button";
            remove.textContent = "Remove";
            remove.addEventListener("click", async () => {
                remove.disabled = true;
                const result = await apiDelete(`/cart/items/${item.id}`);
                if (result.success) {
                    await loadCart();
                    showMessage(`${name.textContent} removed from your cart.`, false, true);
                }
                else {
                    remove.disabled = false;
                    showMessage(result.error || "Unable to remove this item.", true);
                }
            });

            const updateQuantity = async (rawQuantity) => {
                const quantityText = String(rawQuantity);
                if (!/^\d+$/.test(quantityText)) {
                    quantity.value = String(item.quantity);
                    showMessage("Enter a whole-number quantity of at least 1.", true);
                    return;
                }
                const newQuantity = Number(quantityText);
                if (!Number.isSafeInteger(newQuantity) || newQuantity < 1 || (newQuantity > currentQuantity && newQuantity > rowMaximum)) {
                    quantity.value = String(item.quantity);
                    showMessage(newQuantity > rowMaximum
                        ? `This change exceeds your cart limit of ${maxCartItems} items or the per-item limit of ${maxItemQuantity}.`
                        : "Enter a whole-number quantity of at least 1.", true);
                    return;
                }
                if (newQuantity === currentQuantity) return;
                decrease.disabled = true;
                increase.disabled = true;
                quantity.disabled = true;
                const result = await apiPut(`/cart/items/${item.id}`, { quantity: newQuantity });
                if (result.success) {
                    await loadCart();
                    showMessage("Cart quantity updated.", false, true);
                }
                else {
                    if (result.status === 0) {
                        await loadCart();
                        showMessage("We couldn't confirm that update. Your cart has been refreshed from the server.", true);
                        return;
                    }
                    quantity.disabled = false;
                    decrease.disabled = newQuantity <= 1;
                    increase.disabled = newQuantity >= rowMaximum;
                    quantity.value = String(item.quantity);
                    showMessage(result.error || "Unable to update the quantity.", true);
                }
            };

            decrease.addEventListener("click", () => {
                const current = Number(quantity.value);
                updateQuantity(current > 99 ? 99 : current - 1);
            });
            increase.addEventListener("click", () => updateQuantity(Number(quantity.value) + 1));
            quantity.addEventListener("input", () => {
                if (quantity.value !== "" && !/^\d+$/.test(quantity.value)) {
                    updateQuantity(quantity.value);
                }
            });
            quantity.addEventListener("change", () => updateQuantity(quantity.value));

            row.append(image, itemInfo, quantityLabel, subtotal, remove);
            cartItems.appendChild(row);
        });

        try {
            const selectedPromotion = String(sessionStorage.getItem("foodbykSelectedPromotion") || "").trim().toUpperCase();
            if (selectedPromotion) {
                const promotionNote = document.createElement("p");
                promotionNote.className = "cart-promotion-note";
                promotionNote.textContent = `Promotion ${selectedPromotion} is saved for checkout. Checkout will confirm which items qualify, any BOGO free quantities, and the exact discount. Only one promotion code can be used per order.`;
                cartItems.appendChild(promotionNote);
            }
        } catch (error) {
            // Cart contents remain usable when browser storage is unavailable.
        }

        cartTotal.replaceChildren();
        const totalLabel = document.createElement("span");
        totalLabel.textContent = "Order total";
        const totalValue = document.createElement("strong");
        totalValue.textContent = `R${total.toFixed(2)}`;
        cartTotal.append(totalLabel, totalValue);
        cartTotal.hidden = false;
        checkoutLink.hidden = false;
    };

    await loadCart();
});

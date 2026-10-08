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

        if (items.length === 0) {
            cartItems.replaceChildren();
            cartItems.setAttribute("aria-busy", "false");
            showMessage("Your cart is empty.");
            return;
        }

        cartMessage.hidden = true;
        cartItems.setAttribute("aria-busy", "false");
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
            const price = Number(product?.price ?? item.unit_price ?? 0);
            const unitPrice = document.createElement("span");
            unitPrice.className = "cart-item-unit-price";
            unitPrice.textContent = `R${price.toFixed(2)} each`;
            itemInfo.append(name, unitPrice);

            const quantity = document.createElement("input");
            quantity.type = "number";
            quantity.min = "1";
            quantity.step = "1";
            quantity.value = String(item.quantity);
            quantity.setAttribute("aria-label", `Quantity for ${name.textContent}`);
            const quantityLabel = document.createElement("label");
            quantityLabel.className = "cart-quantity-label";
            quantityLabel.append("Quantity", quantity);
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

            quantity.addEventListener("change", async () => {
                const newQuantity = Number.parseInt(quantity.value, 10);
                if (!Number.isInteger(newQuantity) || newQuantity < 1) {
                    quantity.value = String(item.quantity);
                    return;
                }
                quantity.disabled = true;
                const result = await apiPut(`/cart/items/${item.id}`, { quantity: newQuantity });
                if (result.success) {
                    await loadCart();
                    showMessage("Cart quantity updated.", false, true);
                }
                else {
                    quantity.disabled = false;
                    quantity.value = String(item.quantity);
                    showMessage(result.error || "Unable to update the quantity.", true);
                }
            });

            row.append(image, itemInfo, quantityLabel, subtotal, remove);
            cartItems.appendChild(row);
        });

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

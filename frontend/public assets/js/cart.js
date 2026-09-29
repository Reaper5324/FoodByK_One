document.addEventListener("DOMContentLoaded", async () => {
    const cartItems = document.getElementById("cartItems");
    const cartMessage = document.getElementById("cartMessage");
    const cartTotal = document.getElementById("cartTotal");
    const checkoutLink = document.getElementById("checkoutLink");

    const showMessage = (message, isError = false) => {
        cartMessage.hidden = false;
        cartMessage.textContent = message;
        cartMessage.style.color = isError ? "var(--food-red-dark)" : "inherit";
    };

    const loadCart = async () => {
        cartItems.replaceChildren();
        cartTotal.hidden = true;
        checkoutLink.hidden = true;

        const [cartResult, productResult] = await Promise.all([
            apiGet("/cart"),
            apiGet("/products")
        ]);

        if (!cartResult.success) {
            showMessage(cartResult.error || "Please log in to view your cart.", true);
            const loginLink = document.createElement("a");
            loginLink.href = "../auth/login.html";
            loginLink.textContent = " Log in";
            cartMessage.appendChild(loginLink);
            return;
        }

        if (!productResult.success) {
            showMessage(productResult.error || "Unable to load product details.", true);
            return;
        }

        const products = new Map(productResult.data.map((product) => [Number(product.id), product]));
        const items = cartResult.data.items || [];

        if (items.length === 0) {
            showMessage("Your cart is empty.");
            return;
        }

        cartMessage.hidden = true;
        let total = 0;

        items.forEach((item) => {
            const product = products.get(Number(item.product_id));
            const row = document.createElement("article");
            row.className = "cart-row";

            const name = document.createElement("strong");
            name.textContent = product?.name || `Item ${item.product_id}`;

            const quantity = document.createElement("input");
            quantity.type = "number";
            quantity.min = "1";
            quantity.step = "1";
            quantity.value = String(item.quantity);
            quantity.setAttribute("aria-label", `Quantity for ${name.textContent}`);

            const price = Number(product?.price ?? item.unit_price ?? 0);
            const lineTotal = price * Number(item.quantity);
            total += lineTotal;

            const subtotal = document.createElement("span");
            subtotal.textContent = `R${lineTotal.toFixed(2)}`;

            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "btn btn-secondary";
            remove.textContent = "Remove";
            remove.addEventListener("click", async () => {
                remove.disabled = true;
                const result = await apiDelete(`/cart/items/${item.id}`);
                if (result.success) await loadCart();
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
                if (result.success) await loadCart();
                else {
                    quantity.disabled = false;
                    quantity.value = String(item.quantity);
                    showMessage(result.error || "Unable to update the quantity.", true);
                }
            });

            row.append(name, quantity, subtotal, remove);
            cartItems.appendChild(row);
        });

        cartTotal.textContent = `Total: R${total.toFixed(2)}`;
        cartTotal.hidden = false;
        checkoutLink.hidden = false;
    };

    await loadCart();
});

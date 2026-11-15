document.addEventListener("DOMContentLoaded", async () => {
    const thanksModal = document.getElementById("orderThanksModal");
    const menuQuery = new URLSearchParams(window.location.search);
    const completedOrderId = sessionStorage.getItem("foodByKCompletedOrder");
    if (thanksModal && menuQuery.get("order_complete") === "1"
        && completedOrderId && completedOrderId === menuQuery.get("order_id")) {
        sessionStorage.removeItem("foodByKCompletedOrder");
        sessionStorage.setItem("foodByKThankedOrder", completedOrderId);
        if (typeof thanksModal.showModal === "function") thanksModal.showModal();
        else thanksModal.setAttribute("open", "");
        document.getElementById("closeOrderThanks")?.addEventListener("click", () => thanksModal.close());
        thanksModal.addEventListener("click", (event) => {
            if (event.target === thanksModal) thanksModal.close();
        });
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete("order_complete");
        cleanUrl.searchParams.delete("order_id");
        window.history.replaceState({}, "", cleanUrl);
    }

    const categoryList = document.getElementById("categoryList");
    const menuGrid = document.getElementById("menuGrid");
    const menuMessage = document.getElementById("menuMessage");
    const searchForm = document.getElementById("menuSearchForm");
    const searchInput = document.getElementById("menuSearch");
    let selectedCategory = null;
    const activePromotionsPromise = apiGet("/promotions/active")
        .then((result) => result?.success && Array.isArray(result.data) ? result.data : [])
        .catch(() => []);

    const promotionTargetsProduct = (promotion, productId) => {
        const targets = Array.isArray(promotion.target_product_ids)
            ? promotion.target_product_ids.map(Number)
            : [];
        return targets.length === 0 || targets.includes(Number(productId));
    };

    const productPromotions = (product, promotions) => promotions
        .filter((promotion) => promotionTargetsProduct(promotion, product.id))
        .filter((promotion) => ["percentage", "fixed_amount", "buy_one_get_one", "free_delivery"].includes(promotion.discount_type))
        .filter((promotion) => promotion.discount_type !== "free_delivery"
            || (Array.isArray(promotion.target_product_ids) && promotion.target_product_ids.length > 0))
        .sort((left, right) => {
            const order = { percentage: 0, buy_one_get_one: 1, fixed_amount: 2, free_delivery: 3 };
            const typeOrder = order[left.discount_type] - order[right.discount_type];
            return typeOrder || Number(right.discount_value || 0) - Number(left.discount_value || 0);
        });

    const promotionLabel = (promotion) => {
        const value = Number(promotion.discount_value || 0);
        if (promotion.discount_type === "percentage") return `${value}% off`;
        if (promotion.discount_type === "fixed_amount") return `R${value.toFixed(2)} off eligible items`;
        if (promotion.discount_type === "buy_one_get_one") return "Buy one, get one free";
        if (promotion.discount_type === "free_delivery") return "Free delivery";
        return "Special offer";
    };

    const skeleton = (className) => {
        const node = document.createElement("div");
        node.className = `skeleton ${className}`;
        node.setAttribute("aria-hidden", "true");
        return node;
    };
    const showCategorySkeletons = () => {
        categoryList.setAttribute("aria-busy", "true");
        categoryList.replaceChildren(...Array.from({ length: 4 }, () => skeleton("skeleton-pill")));
    };
    const showProductSkeletons = () => {
        menuGrid.setAttribute("aria-busy", "true");
        menuGrid.replaceChildren(...Array.from({ length: 6 }, () => {
            const card = skeleton("skeleton-card");
            const media = skeleton("skeleton-media");
            card.appendChild(media);
            return card;
        }));
    };

    const showCartNotification = (message) => {
        let notification = document.getElementById("cartNotification");
        if (!notification) {
            notification = document.createElement("div");
            notification.id = "cartNotification";
            notification.className = "cart-notification";
            notification.setAttribute("role", "status");
            notification.setAttribute("aria-live", "polite");
            document.body.appendChild(notification);
        }

        notification.textContent = message;
        notification.classList.add("is-visible");
        clearTimeout(notification.hideTimer);
        notification.hideTimer = setTimeout(() => {
            notification.classList.remove("is-visible");
        }, 2800);
    };

    const showMessage = (message, isError = false) => {
        menuMessage.hidden = false;
        menuMessage.textContent = message;
        menuMessage.classList.toggle("is-error", isError);
        window.foodByKNotify?.(message, isError ? "error" : "success");
    };

    const clearMessage = () => {
        menuMessage.hidden = true;
        menuMessage.textContent = "";
    };

    const loadCategories = async () => {
        showCategorySkeletons();
        const result = await apiGet("/categories");
        if (!result.success) {
            categoryList.replaceChildren();
            categoryList.setAttribute("aria-busy", "false");
            showMessage(result.error || "Unable to load menu categories.", true);
            return;
        }

        categoryList.innerHTML = "";
        categoryList.setAttribute("aria-busy", "false");
        const allButton = document.createElement("button");
        allButton.type = "button";
        allButton.textContent = "All items";
        allButton.className = "active";
        allButton.addEventListener("click", () => selectCategory(null, allButton));
        categoryList.appendChild(allButton);

        result.data.forEach((category) => {
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = category.name;
            button.addEventListener("click", () => selectCategory(category.id, button));
            categoryList.appendChild(button);
        });
    };

    const selectCategory = async (categoryId, button) => {
        selectedCategory = categoryId;
        categoryList.querySelectorAll("button").forEach((item) => item.classList.remove("active"));
        button.classList.add("active");
        await loadProducts();
    };

    const loadProducts = async () => {
        clearMessage();
        showProductSkeletons();
        const query = searchInput.value.trim();
        let endpoint = selectedCategory === null ? "/products" : `/products/category/${selectedCategory}`;
        if (query !== "") {
            endpoint = `/products/search?q=${encodeURIComponent(query)}`;
        }

        const result = await apiGet(endpoint);
        if (!result.success) {
            menuGrid.replaceChildren();
            menuGrid.setAttribute("aria-busy", "false");
            showMessage(result.error || "Unable to load menu items.", true);
            return;
        }
        if (result.data.length === 0) {
            menuGrid.replaceChildren();
            menuGrid.setAttribute("aria-busy", "false");
            showMessage("No menu items match your search.");
            return;
        }

        const activePromotions = await activePromotionsPromise;
        menuGrid.replaceChildren();
        result.data.forEach((product) => renderProduct(product, activePromotions));
        menuGrid.setAttribute("aria-busy", "false");
    };

    const renderProduct = (product, promotions = []) => {
        const card = document.createElement("article");
        card.className = "menu-card";
        const detailUrl = `menu-item.html?id=${encodeURIComponent(product.id)}`;
        const imageLink = document.createElement("a");
        imageLink.className = "menu-card-detail-link";
        imageLink.href = detailUrl;
        imageLink.setAttribute("aria-label", `View details for ${product.name}`);

        const image = document.createElement("img");
        image.className = "menu-card-image";
        image.src = product.image_url || "";
        image.alt = product.name || "Food by K menu item";
        image.loading = "lazy";
        image.addEventListener("error", () => {
            const placeholder = document.createElement("div");
            placeholder.className = "menu-card-image menu-image-placeholder";
            placeholder.textContent = "Image unavailable";
            image.replaceWith(placeholder);
        }, { once: true });
        imageLink.appendChild(image);

        const body = document.createElement("div");
        body.className = "menu-card-body";
        const titleLink = document.createElement("a");
        titleLink.className = "menu-card-title-link";
        titleLink.href = detailUrl;
        const title = document.createElement("h2");
        title.textContent = product.name || "Menu item";
        titleLink.appendChild(title);

        const description = document.createElement("p");
        description.textContent = product.description || "A Food by K favourite.";
        const eligiblePromotions = productPromotions(product, promotions);
        const pricePromotion = eligiblePromotions.find((promotion) => promotion.discount_type === "percentage");
        let promotionList = null;
        if (eligiblePromotions.length) {
            promotionList = document.createElement("div");
            promotionList.className = "menu-promotion-list";
            eligiblePromotions.forEach((promotion) => {
                const badge = document.createElement("span");
                badge.className = "menu-promotion-badge";
                badge.textContent = promotion.code
                    ? `${promotionLabel(promotion)} · code ${String(promotion.code).toUpperCase()}`
                    : promotionLabel(promotion);
                promotionList.appendChild(badge);
            });
        }
        const footer = document.createElement("div");
        footer.className = "menu-card-footer";
        const priceWrap = document.createElement("div");
        priceWrap.className = "menu-price-wrap";
        const basePrice = Number(product.price);
        const price = document.createElement("span");
        price.className = "menu-price";
        if (pricePromotion && Number.isFinite(basePrice)) {
            const originalPrice = document.createElement("del");
            originalPrice.className = "menu-price-original";
            originalPrice.textContent = `R${basePrice.toFixed(2)}`;
            price.textContent = `R${(Math.round(basePrice * (1 - Number(pricePromotion.discount_value || 0) / 100) * 100) / 100).toFixed(2)}`;
            const condition = document.createElement("span");
            condition.className = "menu-price-condition";
            condition.textContent = `with code ${String(pricePromotion.code || "").toUpperCase()} at checkout`;
            priceWrap.append(originalPrice, price, condition);
        } else {
            price.textContent = `R${basePrice.toFixed(2)}`;
            priceWrap.appendChild(price);
        }
        const addButton = document.createElement("button");
        addButton.className = "btn btn-primary add-to-cart";
        addButton.type = "button";
        addButton.textContent = "Add to cart";
        addButton.addEventListener("click", async () => {
            addButton.disabled = true;
            const result = await apiPost("/cart/items", { product_id: product.id, quantity: 1 });
            if (result.success) {
                let notice = `${product.name} added to your cart`;
                const offerToSelect = eligiblePromotions[0];
                if (offerToSelect?.code) {
                    try {
                        const selectedCode = sessionStorage.getItem("foodbykSelectedPromotion");
                        if (!selectedCode) {
                            sessionStorage.setItem("foodbykSelectedPromotion", String(offerToSelect.code).toUpperCase());
                            notice += ` · offer code ${String(offerToSelect.code).toUpperCase()} saved for checkout`;
                        } else if (selectedCode.toUpperCase() !== String(offerToSelect.code).toUpperCase()) {
                            notice += ` · ${selectedCode.toUpperCase()} remains selected; only one offer code can be used per order`;
                        }
                    } catch (error) {
                        console.warn("Unable to save the promotion for checkout.", error);
                    }
                }
                addButton.textContent = "Added";
                showCartNotification(notice);
                setTimeout(() => {
                    addButton.textContent = "Add to cart";
                    addButton.disabled = false;
                }, 900);
                return;
            }

            addButton.disabled = false;
            showMessage(
                result.status === 401
                    ? "Please log in or create an account before adding items to your cart."
                    : (result.error || "Unable to add this item to your cart."),
                true
            );
        });

        const reportLink = document.createElement("a");
        reportLink.className = "menu-card-support-link";
        reportLink.href = `support.html?category=menu_item&product_id=${encodeURIComponent(product.id)}&context=${encodeURIComponent(product.name || `menu item ${product.id}`)}`;
        reportLink.textContent = "Report an issue with this item";

        footer.append(priceWrap, addButton);
        body.append(titleLink, description);
        if (promotionList) body.appendChild(promotionList);
        body.append(footer, reportLink);
        card.append(imageLink, body);
        menuGrid.appendChild(card);
    };

    searchForm.addEventListener("submit", (event) => {
        event.preventDefault();
        loadProducts();
    });

    await loadCategories();
    await loadProducts();
});

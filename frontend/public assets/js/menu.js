document.addEventListener("DOMContentLoaded", async () => {
    const thanksModal = document.getElementById("orderThanksModal");
    if (thanksModal && new URLSearchParams(window.location.search).get("order_complete") === "1") {
        if (typeof thanksModal.showModal === "function") thanksModal.showModal();
        else thanksModal.setAttribute("open", "");
        document.getElementById("closeOrderThanks")?.addEventListener("click", () => thanksModal.close());
        thanksModal.addEventListener("click", (event) => {
            if (event.target === thanksModal) thanksModal.close();
        });
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete("order_complete");
        window.history.replaceState({}, "", cleanUrl);
    }

    const categoryList = document.getElementById("categoryList");
    const menuGrid = document.getElementById("menuGrid");
    const menuMessage = document.getElementById("menuMessage");
    const searchForm = document.getElementById("menuSearchForm");
    const searchInput = document.getElementById("menuSearch");
    let selectedCategory = null;

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
        menuMessage.style.color = isError ? "var(--food-red-dark)" : "inherit";
    };

    const clearMessage = () => {
        menuMessage.hidden = true;
        menuMessage.textContent = "";
    };

    const loadCategories = async () => {
        const result = await apiGet("/categories");
        if (!result.success) {
            showMessage(result.error || "Unable to load menu categories.", true);
            return;
        }

        categoryList.innerHTML = "";
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
        menuGrid.innerHTML = "";
        const query = searchInput.value.trim();
        let endpoint = selectedCategory === null ? "/products" : `/products/category/${selectedCategory}`;
        if (query !== "") {
            endpoint = `/products/search?q=${encodeURIComponent(query)}`;
        }

        const result = await apiGet(endpoint);
        if (!result.success) {
            showMessage(result.error || "Unable to load menu items.", true);
            return;
        }
        if (result.data.length === 0) {
            showMessage("No menu items match your search.");
            return;
        }

        result.data.forEach((product) => renderProduct(product));
    };

    const renderProduct = (product) => {
        const card = document.createElement("article");
        card.className = "menu-card";
        const image = product.image_url || "../../images/menu-placeholder.jpg";
        card.innerHTML = `
            <img class="menu-card-image" src="${image}" alt="${escapeHtml(product.name)}" onerror="this.style.display='none'">
            <div class="menu-card-body">
                <h2>${escapeHtml(product.name)}</h2>
                <p>${escapeHtml(product.description || "A Food by K favourite.")}</p>
                <div class="menu-card-footer">
                    <span class="menu-price">R${Number(product.price).toFixed(2)}</span>
                    <button class="btn btn-primary add-to-cart" type="button">Add to cart</button>
                </div>
            </div>`;
  card.querySelector(".add-to-cart").addEventListener("click", async (event) => {
      const button = event.currentTarget;
      button.disabled = true;

      const result = await apiPost("/cart/items", {
          product_id: product.id,
          quantity: 1
      });

      if (result.success) {
          const originalLabel = button.textContent;
          button.textContent = "Added";
          showCartNotification(`${product.name} added to your cart`);
          setTimeout(() => {
              button.textContent = originalLabel;
              button.disabled = false;
          }, 900);
          return;
      }

      button.disabled = false;
      showMessage(
          result.status === 401
              ? "Please log in or create an account before adding items to your cart."
              : (result.error || "Unable to add this item to your cart."),
          true
      );
  });
        menuGrid.appendChild(card);
    };

    const escapeHtml = (value) => String(value)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");

    searchForm.addEventListener("submit", (event) => {
        event.preventDefault();
        loadProducts();
    });

    await loadCategories();
    await loadProducts();
});

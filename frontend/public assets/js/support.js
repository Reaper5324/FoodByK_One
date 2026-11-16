(() => {
  const form = document.getElementById("supportTicketForm");
  const list = document.getElementById("supportTickets");
  const feedback = document.getElementById("supportFeedback");
  const accessNotice = document.getElementById("supportAccessNotice");
  const historyPanel = document.querySelector(".support-history");
  if (!form || !list || !accessNotice || !historyPanel) return;
  const query = new URLSearchParams(window.location.search);
  const initialCategory = query.get("category");
  const context = query.get("context") || "";
  const initialOrderId = query.get("order_id");
  const initialProductId = query.get("product_id");
  const categorySelect = form.elements.namedItem("category");
  const orderSelect = document.getElementById("supportOrderLookup");
  const productSelect = document.getElementById("supportProductLookup");
  const orderSearch = document.getElementById("supportOrderSearch");
  const productSearch = document.getElementById("supportProductSearch");
  const orderLookupButton = document.getElementById("supportOrderLookupButton");
  const lookupStatus = document.getElementById("supportLookupStatus");
  if (initialCategory && [...categorySelect.options].some((option) => option.value === initialCategory)) {
    categorySelect.value = initialCategory;
  }
  if (context) {
    const subject = form.elements.namedItem("subject");
    subject.value = `Help with ${context}`.slice(0, 150);
    const notice = document.createElement("p");
    notice.className = "support-feedback";
    notice.textContent = `This report is about: ${context}`;
    form.querySelector("h2").insertAdjacentElement("afterend", notice);
  }

  const showMessage = (message, isError = false) => {
    feedback.textContent = message;
    feedback.hidden = false;
    feedback.classList.toggle("is-error", isError);
    window.foodByKNotify?.(message, isError ? "error" : "success");
  };
  const showAccessNotice = (message, links = []) => {
    accessNotice.replaceChildren();
    const text = document.createElement("p");
    text.textContent = message;
    accessNotice.appendChild(text);
    if (links.length) {
      const actions = document.createElement("div");
      actions.className = "support-access-actions";
      links.forEach(({ label, href }) => {
        const link = document.createElement("a");
        link.className = "btn btn-secondary";
        link.href = href;
        link.textContent = label;
        actions.appendChild(link);
      });
      accessNotice.appendChild(actions);
    }
    accessNotice.hidden = false;
    form.hidden = true;
    historyPanel.hidden = true;
  };
  const renderTickets = (tickets) => {
    list.replaceChildren();
    if (!tickets.length) {
      const empty = document.createElement("p");
      empty.className = "support-empty";
      empty.textContent = "You haven’t sent a support request yet. If you need a hand, send us a message and we’ll reply here.";
      list.appendChild(empty);
      return;
    }
    tickets.forEach((ticket) => {
      const card = document.createElement("article");
      card.className = "support-ticket";
      const header = document.createElement("header");
      const heading = document.createElement("h3");
      heading.textContent = ticket.subject;
      const status = document.createElement("span");
      status.className = "support-status";
      status.textContent = String(ticket.status).replaceAll("_", " ");
      header.append(heading, status);
      const category = document.createElement("small");
      category.textContent = `${ticket.category} · ${String(ticket.created_at || "").replace("T", " ")}`;
      const references = [];
      if (ticket.related_order_id) references.push(`Order #${ticket.related_order_id}`);
      if (ticket.related_product_id) references.push(`Menu item #${ticket.related_product_id}`);
      if (references.length) category.textContent += ` · ${references.join(" · ")}`;
      const message = document.createElement("p");
      message.textContent = ticket.message;
      card.append(header, category, message);
      (ticket.replies || []).forEach((reply) => {
        const replyBox = document.createElement("div");
        replyBox.className = "support-reply";
        const byline = document.createElement("strong");
        byline.textContent = `Food by K · ${reply.responder_name}`;
        const replyText = document.createElement("p");
        replyText.textContent = reply.reply_message;
        replyBox.append(byline, replyText);
        card.appendChild(replyBox);
      });
      list.appendChild(card);
    });
  };
  const loadTickets = async () => {
    list.setAttribute("aria-busy", "true");
    const result = await apiGet("/support/tickets");
    list.setAttribute("aria-busy", "false");
    if (!result?.success) {
      list.replaceChildren();
      const error = document.createElement("p");
      error.className = "support-empty";
      error.textContent = result?.error || "Sign in to view your support requests.";
      list.appendChild(error);
      return;
    }
    renderTickets(result.data || []);
  };

  const setLookupStatus = (message, isError = false) => {
    lookupStatus.textContent = message;
    lookupStatus.classList.toggle("is-error", isError);
  };
  const addOrderOption = (order) => {
    const id = String(order.id);
    let option = [...orderSelect.options].find((entry) => entry.value === id);
    if (!option) {
      option = document.createElement("option");
      option.value = id;
      const date = String(order.created_at || "").slice(0, 10);
      option.textContent = `Order #${id} · ${String(order.status || "order").replaceAll("_", " ")}${date ? ` · ${date}` : ""}`;
      orderSelect.appendChild(option);
    }
    orderSelect.value = id;
    setLookupStatus(`Order #${id} selected. The team can review it with your request.`);
  };
  const loadLookups = async () => {
    setLookupStatus("Loading your orders and menu items…");
    const [ordersResult, productsResult] = await Promise.all([
      apiGet("/orders?limit=100&offset=0"),
      apiGet("/products")
    ]);
    let lookupError = false;
    if (ordersResult?.success && Array.isArray(ordersResult.data?.orders)) {
      ordersResult.data.orders.forEach((order) => {
        const option = document.createElement("option");
        option.value = String(order.id);
        const date = String(order.created_at || "").slice(0, 10);
        option.textContent = `Order #${order.id} · ${String(order.status || "order").replaceAll("_", " ")}${date ? ` · ${date}` : ""}`;
        orderSelect.appendChild(option);
      });
    } else {
      lookupError = true;
    }
    if (productsResult?.success && Array.isArray(productsResult.data)) {
      productsResult.data.forEach((product) => {
        const option = document.createElement("option");
        option.value = String(product.id);
        option.textContent = `${product.name || `Menu item #${product.id}`} · R${Number(product.price || 0).toFixed(2)}`;
        productSelect.appendChild(option);
      });
    } else {
      lookupError = true;
    }
    if (initialProductId && /^\d+$/.test(initialProductId)) {
      const found = [...productSelect.options].some((option) => option.value === initialProductId);
      if (found) productSelect.value = initialProductId;
      else {
        const itemResult = await apiGet(`/products/${encodeURIComponent(initialProductId)}`).catch(() => null);
        if (itemResult?.success && itemResult.data) {
          const option = document.createElement("option");
          option.value = initialProductId;
          option.textContent = `${itemResult.data.name || `Menu item #${initialProductId}`} · R${Number(itemResult.data.price || 0).toFixed(2)}`;
          productSelect.appendChild(option);
          productSelect.value = initialProductId;
        }
      }
    }
    if (initialOrderId && /^\d+$/.test(initialOrderId)) {
      orderSearch.value = initialOrderId;
      await lookupOrder();
    } else {
      setLookupStatus(lookupError
        ? "Some lookups could not load. You can still send your support request."
        : "Choose one of your recent orders or search for a menu item.", lookupError);
    }
  };
  const filterProducts = () => {
    const search = productSearch.value.trim().toLocaleLowerCase();
    [...productSelect.options].forEach((option) => {
      if (option.value === "") return;
      option.hidden = search !== "" && !option.textContent.toLocaleLowerCase().includes(search);
    });
    if (productSelect.selectedOptions[0]?.hidden) productSelect.value = "";
  };
  const lookupOrder = async () => {
    const orderId = orderSearch.value.trim();
    if (!/^\d+$/.test(orderId) || Number(orderId) < 1) {
      setLookupStatus("Enter a valid order number to look it up.", true);
      return;
    }
    orderLookupButton.disabled = true;
    const originalText = orderLookupButton.textContent;
    orderLookupButton.textContent = "Looking up…";
    const result = await apiGet(`/orders/${encodeURIComponent(orderId)}`).catch(() => null);
    orderLookupButton.disabled = false;
    orderLookupButton.textContent = originalText;
    if (!result?.success || !result.data?.order) {
      setLookupStatus(`Order #${orderId} wasn’t found on your account or is not ready for lookup. Check the number and try again.`, true);
      return;
    }
    addOrderOption(result.data.order);
  };
  orderLookupButton.addEventListener("click", lookupOrder);
  orderSelect.addEventListener("change", () => {
    if (orderSelect.value) setLookupStatus(`Order #${orderSelect.value} will be linked to your request.`);
  });
  orderSearch.addEventListener("keydown", (event) => {
    if (event.key === "Enter") { event.preventDefault(); lookupOrder(); }
  });
  productSearch.addEventListener("input", filterProducts);

  const initialiseSupport = async () => {
    if (typeof getCurrentUser !== "function") {
      showAccessNotice("We couldn’t check your sign-in. Refresh the page and try again.");
      return;
    }

    const result = await getCurrentUser();
    if (!result?.success || !result.data) {
      if (result?.status === 401) {
        showAccessNotice("Sign in to send a support request and follow replies from our team.", [
          { label: "Sign in", href: "../auth/login.html" },
          { label: "Create an account", href: "../auth/register.html" }
        ]);
      } else {
        showAccessNotice("We couldn’t check your account right now. Please refresh and try again.");
      }
      return;
    }

    if (String(result.data.role || "").toLowerCase() !== "customer") {
      showAccessNotice("Support requests are available to customer accounts.");
      return;
    }

    if (result.data.policy_acceptance_required) {
      showAccessNotice("Please review the current account policies before contacting support.", [
        { label: "Review account policies", href: "../auth/account.html?review-policies=1" }
      ]);
      return;
    }

    accessNotice.hidden = true;
    form.hidden = false;
    historyPanel.hidden = false;
    await Promise.all([loadTickets(), loadLookups()]);
  };

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector("button[type=submit]");
    button.disabled = true;
    button.textContent = "Sending…";
    const payload = Object.fromEntries(new FormData(form));
    if (context) payload.message = `Page context: ${context}\n\n${payload.message}`.slice(0, 5000);
    const result = await apiPost("/support/tickets", payload);
    button.disabled = false;
    button.textContent = "Send message";
    if (!result?.success) {
      showMessage(result?.error || "Your message could not be sent. Please try again.", true);
      return;
    }
    form.reset();
    showMessage("Your message has been sent. We’ll reply here as soon as we can.");
    await loadTickets();
  });
  initialiseSupport();
})();

(() => {
  const form = document.getElementById("supportTicketForm");
  const list = document.getElementById("supportTickets");
  const feedback = document.getElementById("supportFeedback");
  if (!form || !list) return;
  const query = new URLSearchParams(window.location.search);
  const initialCategory = query.get("category");
  const context = query.get("context") || "";
  const categorySelect = form.elements.namedItem("category");
  if (initialCategory && [...categorySelect.options].some((option) => option.value === initialCategory)) {
    categorySelect.value = initialCategory;
  }
  ["order_id", "product_id"].forEach((key) => {
    const value = query.get(key);
    if (!value || !/^\d+$/.test(value)) return;
    const input = document.createElement("input");
    input.type = "hidden"; input.name = key; input.value = value;
    form.appendChild(input);
  });
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
  loadTickets();
})();

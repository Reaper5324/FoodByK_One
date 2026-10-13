(function initializeFoodByKAssistant() {
    if (document.querySelector("[data-foodbyk-assistant]")) return;

    const root = document.createElement("div");
    root.className = "foodbyk-assistant";
    root.dataset.foodbykAssistant = "true";
    root.innerHTML = `
        <section class="foodbyk-assistant-panel" id="foodByKAssistantPanel" role="dialog" aria-modal="true" aria-labelledby="foodByKAssistantTitle" hidden>
            <header class="foodbyk-assistant-header">
                <div><p class="foodbyk-assistant-kicker">FOOD BY K</p><h2 id="foodByKAssistantTitle">What can I help you find?</h2></div>
                <button class="foodbyk-assistant-close" type="button" aria-label="Close assistant">×</button>
            </header>
            <p class="foodbyk-assistant-intro">Looking for something tasty or have a question about Food by K? Ask away. For help with an order or payment, our team can help directly. Please keep personal details out of this chat.</p>
            <div class="foodbyk-assistant-prompts" aria-label="Suggested questions">
                <button type="button">What is on the menu?</button>
                <button type="button">Where are you located?</button>
                <button type="button">What are your opening hours?</button>
            </div>
            <div class="foodbyk-assistant-messages" aria-live="polite" aria-relevant="additions"></div>
            <form class="foodbyk-assistant-form">
                <label class="foodbyk-assistant-label" for="foodByKAssistantQuestion">Your question</label>
                <textarea id="foodByKAssistantQuestion" maxlength="500" rows="2" placeholder="Ask me anything about the menu or FAQs" required></textarea>
                <div class="foodbyk-assistant-form-footer">
                    <small>Menu and general FAQs only</small>
                    <button class="foodbyk-assistant-send" type="submit">Ask</button>
                </div>
            </form>
            <div class="foodbyk-assistant-links">
                <a href="/src/index.html#contact">Contact the team</a>
                <a href="/pages/customer/menu.html">Browse the menu <span class="ui-arrow ui-arrow-right" aria-hidden="true"></span></a>
            </div>
        </section>
        <button class="foodbyk-assistant-launcher" type="button" aria-controls="foodByKAssistantPanel" aria-expanded="false">
            <span class="foodbyk-assistant-launcher-mark" aria-hidden="true">K</span><span>Ask Food by K</span>
        </button>`;
    document.body.appendChild(root);

    const panel = root.querySelector(".foodbyk-assistant-panel");
    const launcher = root.querySelector(".foodbyk-assistant-launcher");
    const close = root.querySelector(".foodbyk-assistant-close");
    const form = root.querySelector(".foodbyk-assistant-form");
    const input = root.querySelector("#foodByKAssistantQuestion");
    const sendButton = root.querySelector(".foodbyk-assistant-send");
    const messages = root.querySelector(".foodbyk-assistant-messages");

    function setOpen(open) {
        panel.hidden = !open;
        root.classList.toggle("is-open", open);
        launcher.setAttribute("aria-expanded", String(open));
        if (open) close.focus();
        else launcher.focus();
    }

    function addMessage(text, type, sources = []) {
        const message = document.createElement("article");
        message.className = `foodbyk-assistant-message is-${type}`;
        const label = document.createElement("strong");
        label.textContent = type === "customer" ? "You" : "Food by K";
        const body = document.createElement("p");
        body.textContent = text;
        message.append(label, body);
        if (sources.length) {
            const sourceList = document.createElement("small");
            sourceList.textContent = `Based on: ${sources.map((source) => source.source).filter(Boolean).join(", ")}`;
            message.appendChild(sourceList);
        }
        messages.appendChild(message);
        messages.scrollTop = messages.scrollHeight;
        message.scrollIntoView({ block: "nearest" });
        return message;
    }

    async function ask(question) {
        const cleaned = String(question || "").trim();
        if (!cleaned || cleaned.length > 500 || sendButton.disabled) return;
        root.classList.add("has-conversation");
        addMessage(cleaned, "customer");
        input.value = "";
        input.disabled = true;
        sendButton.disabled = true;
        sendButton.textContent = "…";
        const pending = addMessage("One moment, I’m checking the menu and FAQs for you…", "assistant is-pending");

        try {
            const result = await apiPost("/assistant/ask", { question: cleaned });
            pending.remove();
            if (!result?.success || !result?.data?.answer) {
                addMessage(result?.error || "The assistant is unavailable just now. Please try again shortly.", "assistant is-error");
                return;
            }
            addMessage(result.data.answer, "assistant", Array.isArray(result.data.sources) ? result.data.sources : []);
        } catch (error) {
            pending.remove();
            addMessage("The assistant is unavailable just now. Please try again shortly.", "assistant is-error");
        } finally {
            input.disabled = false;
            sendButton.disabled = false;
            sendButton.textContent = "Ask";
            input.focus();
        }
    }

    launcher.addEventListener("click", () => setOpen(true));
    close.addEventListener("click", () => setOpen(false));
    root.addEventListener("click", (event) => {
        if (event.target === root && !panel.hidden) setOpen(false);
    });
    form.addEventListener("submit", (event) => {
        event.preventDefault();
        ask(input.value);
    });
    root.querySelectorAll(".foodbyk-assistant-prompts button").forEach((button) => {
        button.addEventListener("click", () => ask(button.textContent));
    });
    document.addEventListener("keydown", (event) => {
        if (panel.hidden) return;
        if (event.key === "Escape") {
            setOpen(false);
            return;
        }
        if (event.key !== "Tab") return;

        const focusable = [...panel.querySelectorAll("button, textarea, a[href]")]
            .filter((element) => !element.disabled && element.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    });
})();

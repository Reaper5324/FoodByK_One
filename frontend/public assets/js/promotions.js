document.addEventListener("DOMContentLoaded", async () => {
    const list = document.getElementById("promotionList");
    const message = document.getElementById("promotionsMessage");
    if (!list || !message) return;

    const setMessage = (text, isError = false) => {
        message.hidden = !text;
        message.textContent = text;
        message.classList.toggle("is-error", isError);
    };

    const formatDate = (value) => {
        if (!value) return null;
        const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
        return Number.isNaN(date.getTime())
            ? null
            : date.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
    };

    const discountLabel = (promotion) => {
        const value = Number(promotion.discount_value);
        switch (promotion.discount_type) {
            case "percentage":
                return `${value}% off`;
            case "fixed_amount":
                return `R${value.toFixed(2)} off`;
            case "buy_one_get_one":
                return "Buy one, get one free";
            case "free_delivery":
                return "Free delivery";
            default:
                return "Special offer";
        }
    };

    const createCard = (promotion) => {
        const article = document.createElement("article");
        article.className = "promotion-card";

        const kicker = document.createElement("p");
        kicker.className = "promotion-card-kicker";
        kicker.textContent = "Food by K special";

        const title = document.createElement("h2");
        title.textContent = discountLabel(promotion);

        const eligibility = document.createElement("p");
        eligibility.className = "promotion-eligibility";
        const targets = Array.isArray(promotion.target_product_names) ? promotion.target_product_names : [];
        const appliesTo = targets.length ? `Applies to: ${targets.join(", ")}. ` : "Applies across eligible orders. ";
        const terms = promotion.discount_type === "buy_one_get_one"
            ? "Buy two of the same eligible item; the lower-priced one is free."
            : promotion.discount_type === "free_delivery"
                ? "For delivery orders only."
                : "Discount is calculated at checkout.";
        eligibility.textContent = appliesTo + terms;

        const code = document.createElement("p");
        code.className = "promotion-code";
        code.textContent = String(promotion.code || "").toUpperCase();

        const validity = document.createElement("p");
        validity.className = "promotion-validity";
        const start = formatDate(promotion.start_date);
        const end = formatDate(promotion.end_date);
        validity.textContent = start && end
            ? `Valid ${start} – ${end}`
            : end
                ? `Valid until ${end}`
                : start
                    ? `Available from ${start}`
                    : "Available for a limited time. Check your total at checkout.";

        const apply = document.createElement("button");
        apply.type = "button";
        apply.className = "btn btn-primary";
        apply.append("Add to checkout ");
        const arrow = document.createElement("span");
        arrow.className = "ui-arrow ui-arrow-right";
        arrow.setAttribute("aria-hidden", "true");
        apply.appendChild(arrow);
        apply.addEventListener("click", () => {
            try {
                sessionStorage.setItem("foodbykSelectedPromotion", String(promotion.code || "").toUpperCase());
                window.location.assign("/pages/customer/checkout.html");
            } catch (error) {
                setMessage("Your browser could not save the offer. Please copy the code and enter it at checkout.", true);
            }
        });

        article.append(kicker, title, eligibility, code, validity, apply);
        return article;
    };

    try {
        const result = await apiGet("/promotions/active");
        if (!result?.success || !Array.isArray(result.data)) {
            throw new Error(result?.error || "We couldn’t load the current offers.");
        }
        list.replaceChildren();
        list.setAttribute("aria-busy", "false");
        if (result.data.length === 0) {
            setMessage("There are no active promotions right now. Check back soon or browse the menu.");
            return;
        }
        result.data.forEach((promotion) => list.appendChild(createCard(promotion)));
        setMessage("");
    } catch (error) {
        list.setAttribute("aria-busy", "false");
        setMessage(error.message || "We couldn’t load the current offers. Please try again later.", true);
    }
});

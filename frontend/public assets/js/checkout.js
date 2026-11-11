
const requestPreview = async () => {
    message.hidden = true;
    clearPreview();

    if (!fulfilment.value) {
        showMessage(
            "Choose collection or delivery before reviewing your order.",
            true
        );
        return;
    }

    if (!slotSelect.value) {
        showMessage("Choose an available fulfilment time.", true);
        return;
    }

    if (fulfilment.value === "delivery" && !addressSelect.value) {
        showMessage("Choose a delivery address or add one to your account.", true);
        return;
    }

    const payload = {
        fulfilment_type: fulfilment.value,
        address_id: fulfilment.value === "delivery" ? Number(addressSelect.value) : null,
        promotion_code: promotionInput.value.trim() || null,
        loyalty_points_to_redeem: Number(loyaltyPointsInput?.value || 0)
    };

    previewButton.disabled = true;
    previewButton.textContent = "Reviewing your total…";
    summary.setAttribute("aria-busy", "true");

    summary.replaceChildren(...Array.from({ length: 4 }, () => {
        const row = document.createElement("div");
        row.className = "skeleton skeleton-line";
        row.setAttribute("aria-hidden", "true");
        return row;
    }));

    summary.hidden = false;

    const result = await apiPost("/checkout/preview", payload);

    previewButton.disabled = false;
    previewButton.innerHTML = 'Review total <span class="ui-arrow ui-arrow-right" aria-hidden="true"></span>';

    if (!result.success) {
        summary.hidden = true;
        summary.setAttribute("aria-busy", "false");

        const deliveryErrors = [
            "Delivery is currently unavailable.",
            "A geocoded delivery address is required.",
            "Outside the delivery area - collection is available instead.",
            "This address is outside our service area.",
            "Delivery address not found."
        ];

        const errorMessage = result.error || "Unable to review this order.";

        if (
            fulfilment.value === "delivery" &&
            deliveryErrors.includes(errorMessage)
        ) {
            showDeliveryMessage(errorMessage);
        } else {
            showMessage(errorMessage, true);
        }

        return;
    }

    const data = result.data;

    summary.replaceChildren();
    summary.setAttribute("aria-busy", "false");

    const summaryHeading = document.createElement("div");
    summaryHeading.className = "checkout-summary-heading";

    const summaryKicker = document.createElement("p");
    summaryKicker.textContent = "Price breakdown";

    const summaryTitle = document.createElement("h2");
    summaryTitle.textContent = "Review your total";

    summaryHeading.append(summaryKicker, summaryTitle);
    summary.appendChild(summaryHeading);

    [
        ["Subtotal", data.subtotal],
        [data.discount_reason || "Promotion discount", -Number(data.discount || 0)],
        ["Delivery fee", data.delivery_fee]
    ].forEach(([label, amount]) => {
        const row = document.createElement("p");
        row.className = "checkout-summary-row";

        const title = document.createElement("span");
        const value = document.createElement("strong");

        title.textContent = label;
        value.textContent = Number(amount || 0) < 0
            ? `-R${Math.abs(Number(amount)).toFixed(2)}`
            : `R${Number(amount || 0).toFixed(2)}`;

        row.append(title, value);
        summary.appendChild(row);
    });

    if (Number(data.loyalty_points_redeemed || 0) > 0) {
        const rewardsRow = document.createElement("p");
        rewardsRow.className = "checkout-summary-row";

        const rewardsLabel = document.createElement("span");
        const rewardsValue = document.createElement("strong");

        rewardsLabel.textContent = `Loyalty rewards (${formatPoints(data.loyalty_points_redeemed)} points)`;
        rewardsValue.textContent = `-R${Number(data.loyalty_discount || 0).toFixed(2)}`;

        rewardsRow.append(rewardsLabel, rewardsValue);
        summary.appendChild(rewardsRow);
    }

    const total = document.createElement("p");
    total.className = "checkout-summary-row checkout-summary-total";

    const totalLabel = document.createElement("strong");
    const totalValue = document.createElement("strong");

    totalLabel.textContent = "Total";
    totalValue.textContent = `R${Number(data.total).toFixed(2)}`;

    total.append(totalLabel, totalValue);
    summary.appendChild(total);

    if (data.promotion && Number(data.discount || 0) === 0) {
        const promoNote = document.createElement("p");
        promoNote.className = "checkout-summary-note";

        if (data.promotion.discount_type === "free_delivery" && data.fulfillment_type !== "delivery") {
            promoNote.textContent = "Free-delivery offers apply to delivery orders only.";
        } else if (data.promotion.discount_type === "buy_one_get_one") {
            promoNote.textContent = "Buy-one-get-one offers need two of the same eligible menu item.";
        } else if (data.promotion.target_product_names?.length) {
            promoNote.textContent = `This offer applies to ${data.promotion.target_product_names.join(", ")}. Check that your basket meets the offer conditions.`;
        } else {
            promoNote.textContent = "This offer does not reduce this order total.";
        }

        summary.appendChild(promoNote);
    }

    const summaryNote = document.createElement("p");
    summaryNote.className = "checkout-summary-note";
    summaryNote.textContent = "Your card is set up securely in the next step and is not charged while the team reviews your order.";

    summary.appendChild(summaryNote);

    summary.hidden = false;
    submitButton.hidden = false;
    previewReady = true;

    showMessage("Total reviewed. Check the breakdown below before continuing.");
};

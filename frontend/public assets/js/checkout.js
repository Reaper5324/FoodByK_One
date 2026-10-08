document.addEventListener("DOMContentLoaded", async () => {
    const form = document.getElementById("checkoutForm");
    const fulfilment = document.getElementById("fulfilmentType");
    const addressField = document.getElementById("addressField");
    const addressSelect = document.getElementById("addressId");
    const dateInput = document.getElementById("slotDate");
    const slotSelect = document.getElementById("slotSelect");
    const promotionInput = document.getElementById("promotionCode");
    const message = document.getElementById("checkoutMessage");
    const summary = document.getElementById("checkoutSummary");
    const previewButton = document.getElementById("previewButton");
    const submitButton = document.getElementById("submitButton");
    let previewReady = false;

    const showMessage = (text, isError = false) => {
        message.hidden = false;
        message.textContent = text;
        message.classList.toggle("is-error", isError);
        message.classList.toggle("is-success", !isError);
    };

    const clearPreview = () => {
        previewReady = false;
        summary.hidden = true;
        submitButton.hidden = true;
        message.hidden = true;
    };

    const getLocalDate = () => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
    };

    const loadAddresses = async () => {
        const result = await apiGet("/addresses");
        if (!result.success) {
            showMessage(result.error || "Log in to continue to checkout.", true);
            return false;
        }

        addressSelect.replaceChildren();
        result.data.forEach((address) => {
            const option = document.createElement("option");
            option.value = address.id;
            option.textContent = address.raw_address || [address.street, address.city, address.postal_code].filter(Boolean).join(", ");
            option.selected = Boolean(address.is_default);
            addressSelect.appendChild(option);
        });
        if (addressSelect.options.length === 0) {
            showMessage("Add a delivery address to your account before choosing delivery.", true);
            return false;
        }
        return true;
    };

    const loadSlots = async () => {
        const date = dateInput.value;
        slotSelect.replaceChildren();
        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "Loading available times…";
        slotSelect.appendChild(placeholder);

        const result = await apiGet(`/checkout/slots?date=${encodeURIComponent(date)}`);
        slotSelect.replaceChildren();
        if (!result.success) {
            const failed = document.createElement("option");
            failed.value = "";
            failed.textContent = "Unable to load times";
            slotSelect.appendChild(failed);
            showMessage(result.error || "Unable to load available times.", true);
            return;
        }

        const slots = result.data.filter((slot) => slot.available);
        if (slots.length === 0) {
            const none = document.createElement("option");
            none.value = "";
            none.textContent = "No available times for this date";
            slotSelect.appendChild(none);
            showMessage("No time slots are available for this date. Choose another date.");
            return;
        }

        const choose = document.createElement("option");
        choose.value = "";
        choose.textContent = "Choose a time";
        slotSelect.appendChild(choose);
        slots.forEach((slot) => {
            const option = document.createElement("option");
            option.value = JSON.stringify({ start: slot.start, end: slot.end });
            option.textContent = `${new Date(slot.start).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })} – ${new Date(slot.end).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`;
            slotSelect.appendChild(option);
        });
    };

    const requestPreview = async () => {
        message.hidden = true;
        clearPreview();
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
            promotion_code: promotionInput.value.trim() || null
        };
        previewButton.disabled = true;
        previewButton.textContent = "Reviewing your total…";
        const result = await apiPost("/checkout/preview", payload);
        previewButton.disabled = false;
        previewButton.innerHTML = 'Review total <span aria-hidden="true">→</span>';
        if (!result.success) {
            showMessage(result.error || "Unable to review this order.", true);
            return;
        }

        const data = result.data;
        summary.replaceChildren();
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
            ["Discount", -Number(data.discount || 0)],
            ["Delivery fee", data.delivery_fee]
        ].forEach(([label, amount]) => {
            const row = document.createElement("p");
            row.className = "checkout-summary-row";
            const title = document.createElement("span");
            const value = document.createElement("strong");
            title.textContent = label;
            value.textContent = `R${Number(amount || 0).toFixed(2)}`;
            row.append(title, value);
            summary.appendChild(row);
        });
        const total = document.createElement("p");
        total.className = "checkout-summary-row checkout-summary-total";
        const totalLabel = document.createElement("strong");
        const totalValue = document.createElement("strong");
        totalLabel.textContent = "Total";
        totalValue.textContent = `R${Number(data.total).toFixed(2)}`;
        total.append(totalLabel, totalValue);
        summary.appendChild(total);
        const summaryNote = document.createElement("p");
        summaryNote.className = "checkout-summary-note";
        summaryNote.textContent = "Your card is set up securely in the next step and is not charged while the team reviews your order.";
        summary.appendChild(summaryNote);
        summary.hidden = false;
        submitButton.hidden = false;
        previewReady = true;
        showMessage("Total reviewed. Check the breakdown below before continuing.");
    };

    fulfilment.addEventListener("change", async () => {
        addressField.hidden = fulfilment.value !== "delivery";
        addressSelect.required = fulfilment.value === "delivery";
        clearPreview();
        if (fulfilment.value === "delivery" && addressSelect.options.length === 0) await loadAddresses();
    });
    dateInput.addEventListener("change", async () => {
        clearPreview();
        await loadSlots();
    });
    [addressSelect, slotSelect, promotionInput].forEach((input) => input.addEventListener("change", clearPreview));
    previewButton.addEventListener("click", requestPreview);

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!previewReady) {
            showMessage("Review the order total before continuing.", true);
            return;
        }
        if (!slotSelect.value) {
            showMessage("Choose an available fulfilment time.", true);
            return;
        }
        const selectedSlot = JSON.parse(slotSelect.value);
        submitButton.disabled = true;
        const result = await apiPost("/checkout/submit", {
            fulfilment_type: fulfilment.value,
            address_id: fulfilment.value === "delivery" ? Number(addressSelect.value) : null,
            requested_window_start: selectedSlot.start,
            requested_window_end: selectedSlot.end,
            promotion_code: promotionInput.value.trim() || null
        });

        const setup = result.data?.payment_setup;
        if (!result.success || !setup?.redirect_url || !setup?.fields) {
            submitButton.disabled = false;
            showMessage(result.error || "Unable to start secure card setup. Please contact the restaurant before retrying.", true);
            return;
        }

        const paymentForm = document.createElement("form");
        paymentForm.method = "POST";
        paymentForm.action = setup.redirect_url;
        Object.entries(setup.fields).forEach(([name, value]) => {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = name;
            input.value = String(value);
            paymentForm.appendChild(input);
        });
        document.body.appendChild(paymentForm);
        paymentForm.submit();
    });

    addressField.hidden = true;
    dateInput.min = getLocalDate();
    dateInput.value = getLocalDate();
    await loadAddresses();
    await loadSlots();
});

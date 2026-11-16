document.addEventListener("DOMContentLoaded", async () => {
    const form = document.getElementById("checkoutForm");
    const fulfilment = document.getElementById("fulfilmentType");
    const addressField = document.getElementById("addressField");
    const addressSelect = document.getElementById("addressId");

    const showNewAddressButton = document.getElementById("showNewAddressButton");
    const newAddressSection = document.getElementById("newAddressSection");
    const newAddressMessage = document.getElementById("newAddressMessage");
    const saveNewAddressButton = document.getElementById("saveNewAddressButton");
    const closeNewAddressButton = document.getElementById("closeNewAddressButton");

    const newDeliveryStreet = document.getElementById("newDeliveryStreet");
    const newDeliveryCity = document.getElementById("newDeliveryCity");
    const newDeliveryProvince = document.getElementById("newDeliveryProvince");
    const newDeliveryPostalCode = document.getElementById("newDeliveryPostalCode");
    const newDeliveryAddressDefault = document.getElementById("newDeliveryAddressDefault");

    const deliveryMessage = document.getElementById("deliveryMessage");
    const dateInput = document.getElementById("slotDate");
    const slotSelect = document.getElementById("slotSelect");
    const promotionInput = document.getElementById("promotionCode");
    const message = document.getElementById("checkoutMessage");
    const summary = document.getElementById("checkoutSummary");
    const previewButton = document.getElementById("previewButton");
    const submitButton = document.getElementById("submitButton");
    const loyaltyPointsInput = document.getElementById("loyaltyPointsToRedeem");
    const loyaltyBalanceMessage = document.getElementById("loyaltyBalanceMessage");

    const selectedPromotionKey = "foodbykSelectedPromotion";
    let cartPreviewItems = [];

    let previewReady = false;
    let addressesLoaded = false;
    let previewRequestId = 0;
    let previewTimer = null;

    const formatPoints = (amount) => Number(amount || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    const loadLoyaltyBalance = async () => {
        if (!loyaltyPointsInput || !loyaltyBalanceMessage) return;

        const result = await apiGet("/loyalty/balance");

        if (!result?.success || !result.data) {
            loyaltyBalanceMessage.textContent = "Sign in to view and use your loyalty points.";
            loyaltyPointsInput.disabled = true;
            return;
        }

        const balance = Number(result.data.points || 0);

        loyaltyPointsInput.max = balance.toFixed(2);
        loyaltyPointsInput.disabled = balance <= 0;

        loyaltyBalanceMessage.textContent =
            `You have ${formatPoints(balance)} points worth R${Number(result.data.estimated_value || 0).toFixed(2)}.`;
    };

    try {
        const selectedPromotion = sessionStorage.getItem(selectedPromotionKey);
        if (selectedPromotion) promotionInput.value = selectedPromotion;
    } catch (error) {
        console.warn("Unable to restore the selected promotion code.", error);
    }

    const showDeliveryMessage = (text) => {
        deliveryMessage.textContent = text;
        deliveryMessage.hidden = !text;
    };

    const showMessage = (text, isError = false) => {
        message.hidden = false;
        message.textContent = text;
        message.classList.toggle("is-error", isError);
        message.classList.toggle("is-success", !isError);
        window.foodByKNotify?.(text, isError ? "error" : "success");
    };

    const showSummaryMessage = (text) => {
        summary.replaceChildren();
        const heading = document.createElement("div");
        heading.className = "checkout-summary-heading";
        const kicker = document.createElement("p");
        kicker.textContent = "Live price breakdown";
        const title = document.createElement("h2");
        title.textContent = "Your order total";
        heading.append(kicker, title);
        const note = document.createElement("p");
        note.className = "checkout-summary-note";
        note.textContent = text;
        summary.append(heading, note);
        summary.hidden = false;
        summary.setAttribute("aria-busy", "false");
    };

    const clearPreview = (prompt = "Update the checkout details to see your latest total.") => {
        previewRequestId++;
        previewReady = false;
        previewButton.disabled = false;
        previewButton.innerHTML = 'Review total <span class="ui-arrow ui-arrow-right" aria-hidden="true"></span>';
        submitButton.hidden = true;
        message.hidden = true;
        showDeliveryMessage("");
        showSummaryMessage(prompt);
    };

    const schedulePreview = () => {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(() => requestPreview({ silent: true }), 350);
    };

    const renderCartPreview = () => {
        const host = document.getElementById("checkoutCartPreview");
        if (!host) return;
        host.replaceChildren();
        cartPreviewItems.forEach((item) => {
            const row = document.createElement("p");
            row.className = "checkout-summary-row";
            const name = item.name || item.product_name || `Menu item ${item.product_id || ""}`;
            row.textContent = `${name} × ${Number(item.quantity || 1)} · R${Number(item.line_total || 0).toFixed(2)}`;
            host.appendChild(row);
            (item.customizations || []).forEach((option) => {
                const extra = document.createElement("p");
                extra.className = "checkout-summary-note checkout-summary-customization";
                extra.textContent = `Extras: ${option.name || "Selected extra"}`;
                host.appendChild(extra);
            });
        });
        if (!cartPreviewItems.length) {
            const empty = document.createElement("p");
            empty.className = "checkout-summary-note";
            empty.textContent = "Your cart is empty. Add menu items to see your order total.";
            host.appendChild(empty);
        }
    };

    const loadCartPreview = async () => {
        const host = document.getElementById("checkoutCartPreview");
        if (host && cartPreviewItems.length === 0) host.textContent = "Loading your cart items…";
        const result = await apiGet("/cart").catch(() => null);
        if (!result?.success || !Array.isArray(result.data?.items)) {
            if (host) host.textContent = result?.error || "Could not load your cart. Refresh the page to try again.";
            return;
        }
        cartPreviewItems = result.data.items;
        renderCartPreview();
    };

    // Show the new address form
    showNewAddressButton.addEventListener("click", () => {
        newAddressSection.hidden = false;
        showNewAddressButton.hidden = true;

        newAddressMessage.hidden = true;
        newAddressMessage.textContent = "";
        newAddressMessage.classList.remove("is-error");

        clearPreview();
    });
    // Close the new address form
    closeNewAddressButton.addEventListener("click", () => {
        newAddressSection.hidden = true;
        showNewAddressButton.hidden = false;

        newAddressMessage.hidden = true;
        newAddressMessage.textContent = "";
        newAddressMessage.classList.remove("is-error");

        newDeliveryStreet.value = "";
        newDeliveryCity.value = "";
        newDeliveryProvince.value = "";
        newDeliveryPostalCode.value = "";
        newDeliveryAddressDefault.checked = false;

        clearPreview();
        schedulePreview();
    });

    // Save the new delivery address
    saveNewAddressButton.addEventListener("click", async () => {
        newAddressMessage.hidden = true;
        newAddressMessage.textContent = "";
        newAddressMessage.classList.remove("is-error");

        const street = newDeliveryStreet.value.trim();
        const city = newDeliveryCity.value.trim();
        const province = newDeliveryProvince.value.trim();

        if (!street || !city || !province) {
            newAddressMessage.textContent =
                "Please enter your street address, city, and province.";

            newAddressMessage.classList.add("is-error");
            newAddressMessage.hidden = false;
            return;
        }

        const payload = {
            street: street,
            city: city,
            province: province,
            postal_code: newDeliveryPostalCode.value.trim(),
            is_default: newDeliveryAddressDefault.checked
        };

        saveNewAddressButton.disabled = true;
        saveNewAddressButton.textContent = "Saving...";

        const result = await apiPost("/addresses", payload);

        saveNewAddressButton.disabled = false;
        saveNewAddressButton.textContent = "Save address";

        if (!result.success || !result.data?.id) {
            newAddressMessage.textContent =
                result.error || "Unable to save this address.";

            newAddressMessage.classList.add("is-error");
            newAddressMessage.hidden = false;
            return;
        }

        const savedAddress = result.data;

        const option = document.createElement("option");
        option.value = savedAddress.id;
        option.textContent = savedAddress.raw_address ||
            [savedAddress.street, savedAddress.city, savedAddress.postal_code]
                .filter(Boolean)
                .join(", ");

        addressSelect.appendChild(option);
        addressSelect.value = String(savedAddress.id);

        newAddressSection.hidden = true;
        showNewAddressButton.hidden = false;

        newDeliveryStreet.value = "";
        newDeliveryCity.value = "";
        newDeliveryProvince.value = "";
        newDeliveryPostalCode.value = "";
        newDeliveryAddressDefault.checked = false;

        clearPreview();
        schedulePreview();

        if (savedAddress.has_coordinates === false) {
            showMessage(
                "Address saved, but it couldn't be located for delivery. Please check the address details.",
                true
            );
        } else {
            showMessage("Delivery address saved successfully.");
        }
    });

    const getLocalDate = () => {
        const now = new Date();

        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
    };

    const loadAddresses = async () => {
        if (addressesLoaded) {
            return addressSelect.options.length > 0;
        }

        addressSelect.setAttribute("aria-busy", "true");
        addressSelect.replaceChildren(
            new Option("Loading saved addresses…", "")
        );

        const result = await apiGet("/addresses");

        if (!result.success) {
            addressSelect.replaceChildren(
                new Option("Unable to load addresses", "")
            );

            addressSelect.setAttribute("aria-busy", "false");

            showMessage(
                result.error || "Log in to continue to checkout.",
                true
            );

            return false;
        }

        addressesLoaded = true;
        addressSelect.replaceChildren();

        result.data.forEach((address) => {
            const option = document.createElement("option");

            option.value = address.id;
            option.textContent = address.raw_address ||
                [address.street, address.city, address.postal_code]
                    .filter(Boolean)
                    .join(", ");

            option.selected = Boolean(address.is_default);
            addressSelect.appendChild(option);
        });

        addressSelect.setAttribute("aria-busy", "false");

        if (addressSelect.options.length === 0) {
            addressSelect.appendChild(
                new Option("No saved addresses available", "")
            );

            showMessage(
                "Add a delivery address to your account before choosing delivery.",
                true
            );

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
        slotSelect.setAttribute("aria-busy", "true");

        const result = await apiGet(
            `/checkout/slots?date=${encodeURIComponent(date)}`
        );

        slotSelect.replaceChildren();
        slotSelect.setAttribute("aria-busy", "false");

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

            option.value = JSON.stringify({
                start: slot.start,
                end: slot.end
            });

            option.textContent =
                `${new Date(slot.start).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })} – ${new Date(slot.end).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`;

            slotSelect.appendChild(option);
        });
    };

    const requestPreview = async ({ silent = false } = {}) => {
        message.hidden = true;
        previewReady = false;
        submitButton.hidden = true;
        const requestId = ++previewRequestId;

        const explain = (text, isError = true) => {
            if (requestId !== previewRequestId) return;
            showSummaryMessage(text);
            if (!silent) showMessage(text, isError);
        };

        if (!fulfilment.value) {
            explain("Choose collection or delivery to calculate your order total.", false);
            return;
        }

        if (!slotSelect.value) {
            // Price totals do not depend on the selected time. Preview anyway;
            // the submit action remains gated on a valid slot.
        }

        if (fulfilment.value === "delivery" && !addressSelect.value) {
            explain("Choose a delivery address to include the delivery fee in your total.");
            return;
        }

        if (fulfilment.value === "delivery" && !newAddressSection.hidden) {
            explain("Save your new delivery address to calculate the delivery fee.");
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


        const result = await apiPost("/checkout/preview", payload);

        if (requestId !== previewRequestId) return;

        previewButton.disabled = false;
        previewButton.innerHTML =
            'Review total <span class="ui-arrow ui-arrow-right" aria-hidden="true"></span>';

        if (!result.success) {
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
            }
            showSummaryMessage(errorMessage);
            if (!silent && !(fulfilment.value === "delivery" && deliveryErrors.includes(errorMessage))) {
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

        if (Array.isArray(data.items) && data.items.length) {
            const itemsHeading = document.createElement("p");
            itemsHeading.className = "checkout-summary-row checkout-summary-items-heading";
            itemsHeading.textContent = "Items in your order";
            summary.appendChild(itemsHeading);
            data.items.forEach((item) => {
                const row = document.createElement("p");
                row.className = "checkout-summary-row";
                const details = document.createElement("span");
                const freeQuantity = Number(item.free_quantity || 0);
                details.textContent = `${item.name} × ${item.quantity}${freeQuantity ? ` (${freeQuantity} free)` : ""}`;
                const price = document.createElement("strong");
                price.textContent = `R${Number(item.line_total || 0).toFixed(2)}`;
                row.append(details, price);
                summary.appendChild(row);
                (item.customizations || []).forEach((customization) => {
                    const option = document.createElement("p");
                    option.className = "checkout-summary-note checkout-summary-customization";
                    option.textContent = `↳ ${customization.name || customization.option_name || "Extra"}`;
                    summary.appendChild(option);
                });
            });
        }

       // Keep delivery waivers attached to the delivery line so the customer
       // sees the fee they will actually pay.
       const discount = Number(data.discount || 0);
       const isFreeDelivery = data.promotion?.discount_type === "free_delivery";
       const deliveryFee = Number(data.delivery_fee || 0);
       const deliveryDiscount = isFreeDelivery ? Math.min(deliveryFee, discount) : 0;
       const itemDiscount = discount - deliveryDiscount;
       const priceRows = [["Subtotal", Number(data.subtotal || 0)]];

       if (itemDiscount > 0) {
           priceRows.push([data.discount_reason || "Promotion discount", -itemDiscount]);
       }

       priceRows.push([
           data.fulfillment_type === "delivery"
               ? (deliveryDiscount > 0 || data.free_delivery_applied ? "Delivery fee (free delivery applied)" : "Delivery fee")
               : "Delivery fee (not applicable)",
           data.fulfillment_type === "delivery" ? deliveryFee - deliveryDiscount : 0
       ]);

       priceRows.forEach(([label, amount]) => {
           const row = document.createElement("p");
           row.className = "checkout-summary-row";

           const title = document.createElement("span");
           const value = document.createElement("strong");

           title.textContent = label;

           value.textContent = amount < 0
               ? `-R${Math.abs(amount).toFixed(2)}`
               : `R${amount.toFixed(2)}`;

           row.append(title, value);
           summary.appendChild(row);
       });

        if (Number(data.loyalty_points_redeemed || 0) > 0) {
            const rewardsRow = document.createElement("p");
            rewardsRow.className = "checkout-summary-row";

            const rewardsLabel = document.createElement("span");
            const rewardsValue = document.createElement("strong");

            rewardsLabel.textContent =
                `Loyalty rewards (${formatPoints(data.loyalty_points_redeemed)} points)`;

            rewardsValue.textContent =
                `-R${Number(data.loyalty_discount || 0).toFixed(2)}`;

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

        if (data.promotion) {
            const promoNote = document.createElement("p");
            promoNote.className = "checkout-summary-note";

            const discountApplied = Number(data.discount || 0) > 0;
            const appliedNames = Array.isArray(data.promotion.applied_product_names)
                ? data.promotion.applied_product_names
                : [];
            if (discountApplied && data.promotion.discount_type === "free_delivery") {
                promoNote.textContent = `Free delivery applied with code ${data.promotion.code}.`;
            } else if (discountApplied && data.promotion.discount_type === "buy_one_get_one") {
                promoNote.textContent = appliedNames.length
                    ? `Buy-one-get-one offer applied to: ${appliedNames.join(", ")}.`
                    : `Buy-one-get-one offer applied with code ${data.promotion.code}.`;
            } else if (discountApplied && appliedNames.length) {
                promoNote.textContent = `Promotion applied to: ${appliedNames.join(", ")}.`;
            } else if (discountApplied) {
                promoNote.textContent = `Promotion ${data.promotion.code} applied to eligible items.`;
            } else if (data.promotion.discount_type === "free_delivery" && data.fulfillment_type !== "delivery") {
                promoNote.textContent = "Free-delivery offers apply to delivery orders only.";
            } else if (data.promotion.discount_type === "buy_one_get_one") {
                promoNote.textContent = "Add an eligible menu item to receive one additional free unit. The offer applies once per item per order.";
            } else if (data.promotion.target_product_names?.length) {
                promoNote.textContent =
                    `This offer applies to ${data.promotion.target_product_names.join(", ")}. Check that your basket meets the offer conditions.`;
            } else {
                promoNote.textContent = "This offer does not reduce this order total.";
            }

            summary.appendChild(promoNote);
        }

        const summaryNote = document.createElement("p");
        summaryNote.className = "checkout-summary-note";
        summaryNote.textContent =
            "Your card is set up securely in the next step and is not charged while the team reviews your order.";

        summary.appendChild(summaryNote);

        summary.hidden = false;
        submitButton.hidden = !slotSelect.value;
        previewReady = true;

        if (!silent) showMessage("Total updated. Check the breakdown before continuing.");
    };

    fulfilment.addEventListener("change", async () => {
        addressField.hidden = fulfilment.value !== "delivery";
        addressSelect.required = fulfilment.value === "delivery";

        clearPreview();

        if (fulfilment.value === "delivery" && !addressesLoaded) {
            await loadAddresses();
        }
        schedulePreview();
    });

    dateInput.addEventListener("change", async () => {
        clearPreview();
        await loadSlots();
        schedulePreview();
    });

    [addressSelect, slotSelect, promotionInput, loyaltyPointsInput]
        .filter(Boolean)
        .forEach((input) => {
            input.addEventListener("change", () => { clearPreview(); schedulePreview(); });
            input.addEventListener("input", () => { clearPreview(); schedulePreview(); });
        });

    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) loadCartPreview();
    });

    promotionInput.addEventListener("input", () => {
        try {
            const savedPromotion = sessionStorage.getItem(selectedPromotionKey);

            if (
                savedPromotion &&
                promotionInput.value.trim().toUpperCase() !== savedPromotion.toUpperCase()
            ) {
                sessionStorage.removeItem(selectedPromotionKey);
            }
        } catch (error) {
            console.warn("Unable to update the selected promotion code.", error);
        }

        clearPreview();
        schedulePreview();
    });

    previewButton.addEventListener("click", () => requestPreview());

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
            promotion_code: promotionInput.value.trim() || null,
            loyalty_points_to_redeem: Number(loyaltyPointsInput?.value || 0)
        });

        if (!result.success || !result.data?.order || !result.data?.payment_setup?.redirect_url || !result.data?.payment_setup?.fields) {
            submitButton.disabled = false;

            showMessage(
                result.error || "Unable to place your order. Please try again.",
                true
            );

            return;
        }

        try {
            sessionStorage.removeItem(selectedPromotionKey);
        } catch (error) {
            console.warn("Unable to clear the selected promotion code after order submission.", error);
        }

        showMessage("Order details saved. Redirecting to PayFast to securely set up your card…");
        const setup = result.data.payment_setup;
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
    addressSelect.required = false;

    dateInput.min = getLocalDate();
    dateInput.value = getLocalDate();

    await loadCartPreview();
    renderCartPreview();
    const cartRefreshTimer = window.setInterval(loadCartPreview, 10000);
    window.addEventListener("pagehide", () => window.clearInterval(cartRefreshTimer), { once: true });
    await loadSlots();
    await loadLoyaltyBalance();
    showSummaryMessage("Choose collection or delivery, an available time, and any offer or rewards to see your live total.");
    schedulePreview();
});

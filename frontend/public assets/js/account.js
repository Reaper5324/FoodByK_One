document.addEventListener("DOMContentLoaded", () => {
    const byId = (id) => document.getElementById(id);
    const profileForm = byId("profileForm");
    const addressForm = byId("deliveryAddressForm");
    const addressList = byId("deliveryAddressList");
    const profileMessage = byId("profileMessage");
    const addressMessage = byId("addressMessage");
    if (!profileForm || !addressForm || !addressList) return;

    let addresses = [];

    const showMessage = (node, message, isError = false) => {
        node.textContent = message;
        node.hidden = false;
        node.classList.toggle("is-error", isError);
    };

    const setProfile = (user) => {
        const fullName = String(user.full_name || user.name || "").trim();
        const nameParts = fullName.split(/\s+/).filter(Boolean);
        byId("profileName").value = fullName;
        byId("profileEmail").value = user.email || "";
        byId("profilePhone").value = user.phone || "";
        byId("accountFirstName").textContent = nameParts[0] || "—";
        byId("accountLastName").textContent = nameParts.slice(1).join(" ") || "—";
        byId("accountEmail").textContent = user.email || "—";
        byId("accountPhone").textContent = user.phone || "Not added";
    };

    const setFormDisabled = (form, disabled) => {
        form.querySelectorAll("input, button").forEach((field) => {
            field.disabled = disabled;
        });
    };

    const resetAddressForm = () => {
        addressForm.reset();
        byId("deliveryAddressId").value = "";
        byId("addressFormHeading").textContent = "Add a delivery address";
        byId("saveAddressButton").textContent = "Save address";
        byId("cancelAddressEdit").hidden = true;
    };

    function renderAddresses() {
        addressList.replaceChildren();
        if (!addresses.length) {
            const empty = document.createElement("p");
            empty.className = "account-muted";
            empty.textContent = "You haven't saved a delivery address yet.";
            addressList.appendChild(empty);
            return;
        }

        addresses.forEach((address) => {
            const card = document.createElement("article");
            card.className = "account-address-card";
            const details = document.createElement("div");
            if (address.is_default) {
                const label = document.createElement("strong");
                label.textContent = "Default address";
                details.appendChild(label);
            }

            const text = document.createElement("p");
            text.textContent = address.raw_address || [address.street, address.city, address.province, address.postal_code]
                .filter(Boolean).join(", ");
            details.appendChild(text);

            if (!address.has_coordinates) {
                const locationNote = document.createElement("small");
                locationNote.className = "account-address-note";
                locationNote.textContent = "We couldn't locate this address for delivery. Check the details and save it again.";
                details.appendChild(locationNote);
            }

            const actions = document.createElement("div");
            actions.className = "account-address-actions";
            const edit = document.createElement("button");
            edit.type = "button";
            edit.textContent = "Edit";
            edit.addEventListener("click", () => {
                byId("deliveryAddressId").value = String(address.id);
                byId("deliveryStreet").value = address.street || address.raw_address || "";
                byId("deliveryCity").value = address.city || "";
                byId("deliveryProvince").value = address.province || "";
                byId("deliveryPostalCode").value = address.postal_code || "";
                byId("deliveryAddressDefault").checked = Boolean(address.is_default);
                byId("addressFormHeading").textContent = "Edit delivery address";
                byId("saveAddressButton").textContent = "Update address";
                byId("cancelAddressEdit").hidden = false;
                byId("deliveryStreet").focus();
            });
            actions.appendChild(edit);

            if (!address.is_default) {
                const makeDefault = document.createElement("button");
                makeDefault.type = "button";
                makeDefault.textContent = "Make default";
                makeDefault.addEventListener("click", async () => {
                    makeDefault.disabled = true;
                    const result = await apiPost(`/addresses/${address.id}/default`, {});
                    if (!result.success) {
                        showMessage(addressMessage, result.error || "Unable to set the default address.", true);
                    } else {
                        showMessage(addressMessage, "Default delivery address updated.");
                        await loadAddresses();
                    }
                    makeDefault.disabled = false;
                });
                actions.appendChild(makeDefault);
            }

            const remove = document.createElement("button");
            remove.type = "button";
            remove.textContent = "Remove";
            remove.addEventListener("click", async () => {
                if (!window.confirm("Remove this delivery address?")) return;
                remove.disabled = true;
                const result = await apiDelete(`/addresses/${address.id}`);
                if (!result.success) {
                    showMessage(addressMessage, result.error || "Unable to remove the address.", true);
                    remove.disabled = false;
                } else {
                    showMessage(addressMessage, "Delivery address removed.");
                    await loadAddresses();
                }
            });
            actions.appendChild(remove);
            card.append(details, actions);
            addressList.appendChild(card);
        });
    }

    async function loadAddresses() {
        const result = await apiGet("/addresses");
        if (!result.success) {
            showMessage(addressMessage, result.error || "Unable to load delivery addresses.", true);
            return false;
        }
        addresses = Array.isArray(result.data) ? result.data : [];
        renderAddresses();
        return true;
    }

    async function loadAccount() {
        const result = await getCurrentUser();
        if (!result.success) {
            showMessage(profileMessage, result.error || "Log in to manage your account.", true);
            showMessage(addressMessage, "Log in to manage your delivery addresses.", true);
            setFormDisabled(profileForm, true);
            setFormDisabled(addressForm, true);
            return;
        }

        setProfile(result.data);
        localStorage.setItem("foodByKUser", JSON.stringify(result.data));
        const addressesLoaded = await loadAddresses();
        if (addressesLoaded && addresses.length === 0 && (result.data.address || result.data.city || result.data.province)) {
            byId("deliveryStreet").value = result.data.address || "";
            byId("deliveryCity").value = result.data.city || "";
            byId("deliveryProvince").value = result.data.province || "";
            showMessage(addressMessage, "Your older profile address is not saved for delivery yet. Review these details and save them below.");
        }
    }

    loadAccount();

    profileForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const button = byId("saveProfileButton");
        button.disabled = true;
        button.textContent = "Saving...";
        const result = await apiPut("/account/profile", {
            full_name: byId("profileName").value.trim(),
            email: byId("profileEmail").value.trim(),
            phone: byId("profilePhone").value.trim() || null
        });
        button.disabled = false;
        button.textContent = "Save account details";

        if (!result.success) {
            showMessage(profileMessage, result.error || "Unable to save your account details.", true);
            return;
        }

        setProfile(result.data);
        localStorage.setItem("foodByKUser", JSON.stringify(result.data));
        showMessage(profileMessage, "Your account details have been saved.");
    });

    addressForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const id = byId("deliveryAddressId").value;
        const payload = {
            street: byId("deliveryStreet").value.trim(),
            city: byId("deliveryCity").value.trim(),
            province: byId("deliveryProvince").value.trim(),
            postal_code: byId("deliveryPostalCode").value.trim(),
            is_default: byId("deliveryAddressDefault").checked
        };
        const button = byId("saveAddressButton");
        button.disabled = true;
        button.textContent = id ? "Updating..." : "Saving...";
        const result = id
            ? await apiPut(`/addresses/${id}`, payload)
            : await apiPost("/addresses", payload);
        button.disabled = false;

        if (!result.success) {
            button.textContent = id ? "Update address" : "Save address";
            showMessage(addressMessage, result.error || "Unable to save this address.", true);
            return;
        }

        resetAddressForm();
        await loadAddresses();
        const savedAddress = result.data;
        showMessage(addressMessage, savedAddress?.has_coordinates === false
            ? "Address saved, but it couldn't be located for delivery. Check the details and edit it before choosing delivery."
            : (id ? "Delivery address updated." : "Delivery address saved."),
            savedAddress?.has_coordinates === false);
    });

    byId("cancelAddressEdit").addEventListener("click", resetAddressForm);
});

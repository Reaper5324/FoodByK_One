document.addEventListener("DOMContentLoaded", () => {
    const profileForm = document.getElementById("profileForm");
    const profileMessage = document.getElementById("profileMessage");
    const addressForm = document.getElementById("deliveryAddressForm");
    const addressList = document.getElementById("deliveryAddressList");
    const addressMessage = document.getElementById("addressMessage");
    if (!profileForm || !addressForm) return;

    const byId = (id) => document.getElementById(id);
    let addresses = [];

    const showMessage = (node, message, isError = false) => {
        node.textContent = message;
        node.hidden = false;
        node.classList.toggle("is-error", isError);
    };
    const setProfile = (user) => {
        byId("profileName").value = user.full_name || "";
        byId("profileEmail").value = user.email || "";
        byId("profilePhone").value = user.phone || "";
        byId("profileAddress").value = user.address || "";
        byId("profileCity").value = user.city || "";
        byId("profileProvince").value = user.province || "";
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
            empty.textContent = "You haven’t saved a delivery address yet.";
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
            text.textContent = address.raw_address;
            details.appendChild(text);

            const actions = document.createElement("div");
            actions.className = "account-address-actions";
            const edit = document.createElement("button");
            edit.type = "button";
            edit.textContent = "Edit";
            edit.addEventListener("click", () => {
                byId("deliveryAddressId").value = String(address.id);
                byId("deliveryAddressText").value = address.raw_address || "";
                byId("deliveryAddressDefault").checked = Boolean(address.is_default);
                byId("addressFormHeading").textContent = "Edit delivery address";
                byId("saveAddressButton").textContent = "Update address";
                byId("cancelAddressEdit").hidden = false;
                byId("deliveryAddressText").focus();
            });
            actions.appendChild(edit);

            if (!address.is_default) {
                const makeDefault = document.createElement("button");
                makeDefault.type = "button";
                makeDefault.textContent = "Make default";
                makeDefault.addEventListener("click", async () => {
                    makeDefault.disabled = true;
                    const result = await apiPost(`/addresses/${address.id}/default`, {});
                    if (!result.success) showMessage(addressMessage, result.error || "Unable to set the default address.", true);
                    else await loadAddresses();
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
            return;
        }
        addresses = result.data || [];
        renderAddresses();
    }

    getCurrentUser().then((result) => {
        if (!result.success) {
            showMessage(profileMessage, result.error || "Log in to manage your account.", true);
            profileForm.querySelectorAll("input, button").forEach((field) => { field.disabled = true; });
            return;
        }
        setProfile(result.data);
    });
    loadAddresses();

    profileForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const button = byId("saveProfileButton");
        button.disabled = true;
        const result = await apiPut("/account/profile", {
            full_name: byId("profileName").value.trim(),
            email: byId("profileEmail").value.trim(),
            phone: byId("profilePhone").value.trim() || null,
            address: byId("profileAddress").value.trim(),
            city: byId("profileCity").value.trim(),
            province: byId("profileProvince").value.trim()
        });
        button.disabled = false;
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
            raw_address: byId("deliveryAddressText").value.trim(),
            is_default: byId("deliveryAddressDefault").checked
        };
        const button = byId("saveAddressButton");
        button.disabled = true;
        const result = id
            ? await apiPut(`/addresses/${id}`, payload)
            : await apiPost("/addresses", payload);
        button.disabled = false;
        if (!result.success) {
            showMessage(addressMessage, result.error || "Unable to save this address.", true);
            return;
        }
        resetAddressForm();
        showMessage(addressMessage, id ? "Delivery address updated." : "Delivery address saved.");
        await loadAddresses();
    });

    byId("cancelAddressEdit").addEventListener("click", resetAddressForm);
});

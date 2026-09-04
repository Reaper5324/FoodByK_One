document.addEventListener("DOMContentLoaded", () => {
    const fulfilmentSelect = document.querySelector("select[name='fulfilment_type']");
    const addressField = document.querySelector("input[name='address_id']");

    if (fulfilmentSelect) {
        fulfilmentSelect.addEventListener("change", () => {
            if (fulfilmentSelect.value === "delivery") {
                addressField.style.display = "block";
            } else {
                addressField.style.display = "none";
            }
        });
    }
});

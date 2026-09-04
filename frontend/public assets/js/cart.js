document.addEventListener("DOMContentLoaded", () => {
    const qtyInputs = document.querySelectorAll("input[type='number']");
    qtyInputs.forEach(input => {
        input.addEventListener("change", () => {
            console.log("Quantity updated:", input.value);
            // TODO: call PUT /cart/items/{id}
        });
    });
});

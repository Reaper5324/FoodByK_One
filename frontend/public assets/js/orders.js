document.addEventListener("DOMContentLoaded", () => {
    const cancelForms = document.querySelectorAll("form[action='orders.php']");
    cancelForms.forEach(form => {
        form.addEventListener("submit", () => {
            console.log("Cancelling order...");
            // TODO: call POST /orders/{id}/cancel
        });
    });
});

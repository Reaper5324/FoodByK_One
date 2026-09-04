document.addEventListener("DOMContentLoaded", () => {
    const searchForm = document.querySelector("form[action='menu.php']");
    if (searchForm) {
        searchForm.addEventListener("submit", () => {
            console.log("Searching menu...");
        });
    }
});

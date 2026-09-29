document.addEventListener("DOMContentLoaded", function () {
    const currentUrl = new URL(window.location.href);

    document.querySelectorAll(".navbar nav a").forEach(function (link) {
        const targetUrl = new URL(link.href, currentUrl);

        if (targetUrl.pathname === currentUrl.pathname && !targetUrl.hash) {
            link.classList.add("active");
        }
    });
});

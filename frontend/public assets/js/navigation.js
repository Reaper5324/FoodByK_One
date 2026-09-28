```javascript
/* =========================================
   FOOD BY K - SHARED NAVIGATION
   ========================================= */

document.addEventListener("DOMContentLoaded", function () {

    /*
     * Automatically highlight the current page
     */
    const currentPage = window.location.pathname.split("/").pop();

    const navLinks = document.querySelectorAll(".navbar nav a");

    navLinks.forEach(function (link) {

        const linkPage = link.getAttribute("href");

        if (linkPage === currentPage) {
            link.classList.add("active");
        }

    });


    /*
     * Navigation links
     *
     * These paths are based on the Food By K
     * frontend folder structure.
     */

    const navigationLinks = {
        menu: "pages/menu/menu.html",
        about: "pages/about/about.html",
        contact: "pages/contact/contact.html",
        login: "pages/auth/login.html",
        register: "pages/auth/register.html"
    };


    /*
     * Find the navigation buttons
     */
    const menuLink = document.querySelector('a[data-page="menu"]');
    const aboutLink = document.querySelector('a[data-page="about"]');
    const contactLink = document.querySelector('a[data-page="contact"]');
    const loginLink = document.querySelector('a[data-page="login"]');
    const registerLink = document.querySelector('a[data-page="register"]');


    /*
     * Assign the correct destinations
     */
    if (menuLink) {
        menuLink.href = navigationLinks.menu;
    }

    if (aboutLink) {
        aboutLink.href = navigationLinks.about;
    }

    if (contactLink) {
        contactLink.href = navigationLinks.contact;
    }

    if (loginLink) {
        loginLink.href = navigationLinks.login;
    }

    if (registerLink) {
        registerLink.href = navigationLinks.register;
    }

});


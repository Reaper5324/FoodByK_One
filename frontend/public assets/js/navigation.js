```javascript
/* =========================================
   FOOD BY K - NAVIGATION
   ========================================= */


/* =========================================
   WHEN PAGE LOADS
   ========================================= */

document.addEventListener("DOMContentLoaded", async function () {

    /* Highlight the current page */
    setActiveNavigationLink();


    /* Check whether the user is logged in */
    await updateNavigationForUser();

});


/* =========================================
   ACTIVE NAVIGATION LINK
   ========================================= */

function setActiveNavigationLink() {

    const currentUrl =
        new URL(window.location.href);


    document
        .querySelectorAll(".navbar nav a")
        .forEach(function (link) {

            const targetUrl =
                new URL(link.href, currentUrl);


            if (
                targetUrl.pathname ===
                    currentUrl.pathname &&
                !targetUrl.hash
            ) {

                link.classList.add("active");

            }

        });

}


/* =========================================
   CHECK LOGIN STATUS
   ========================================= */

async function updateNavigationForUser() {

    try {

        /*
         * Ask the backend:
         *
         * "Is somebody currently logged in?"
         */
        const result =
            await getCurrentUser();


        /* =====================================
           USER IS LOGGED IN
           ===================================== */

        if (
            result &&
            result.success &&
            result.data
        ) {

            const user =
                result.data;


            /*
             * Save the current user locally.
             */
            localStorage.setItem(
                "foodByKUser",
                JSON.stringify(user)
            );


            /*
             * Change Login → Account.
             */
            showAccountLink();


            /*
             * Change Register → Logout.
             */
            showLogoutLink();


            return;

        }


        /* =====================================
           USER IS NOT LOGGED IN
           ===================================== */

        localStorage.removeItem(
            "foodByKUser"
        );


        showLoginAndRegister();


    } catch (error) {

        console.error(
            "Navigation authentication error:",
            error
        );


        /*
         * If the backend cannot be reached,
         * check whether we have a stored user.
         *
         * This prevents the navbar from
         * immediately hiding Logout when the
         * API is temporarily unavailable.
         */
        const storedUser =
            localStorage.getItem(
                "foodByKUser"
            );


        if (storedUser) {

            showAccountLink();
            showLogoutLink();

        } else {

            showLoginAndRegister();

        }

    }

}


/* =========================================
   LOGIN → ACCOUNT
   ========================================= */

function showAccountLink() {

    /*
     * Find the Login link.
     */
    const loginLink =
        document.querySelector(
            '.navbar nav a[href*="login.html"]'
        );


    if (!loginLink) {

        console.warn(
            "Food By K: Login link was not found."
        );

        return;

    }


    /*
     * Change Login to Account.
     */
    loginLink.textContent =
        "Account";


    /*
     * Change the destination.
     *
     * Your current link is:
     *
     * ../pages/auth/login.html
     *
     * so we replace login.html with
     * account.html.
     */
    loginLink.href =
        loginLink.href.replace(
            "login.html",
            "account.html"
        );

}


/* =========================================
   REGISTER → LOGOUT
   ========================================= */

function showLogoutLink() {

    /*
     * IMPORTANT:
     *
     * Your HTML already gives the Register
     * link this class:
     *
     * class="nav-register"
     *
     * So we target that directly.
     */
    const registerLink =
        document.querySelector(
            ".navbar nav a.nav-register"
        );


    if (!registerLink) {

        console.warn(
            "Food By K: Register link was not found."
        );

        return;

    }


    /*
     * Change Register → Logout.
     */
    registerLink.textContent =
        "Logout";


    /*
     * Prevent it from going to register.html.
     */
    registerLink.href =
        "#";


    /*
     * Make sure we don't add the same
     * event listener more than once.
     */
    registerLink.removeEventListener(
        "click",
        handleLogout
    );


    /*
     * Add the Logout functionality.
     */
    registerLink.addEventListener(
        "click",
        handleLogout
    );

}


/* =========================================
   LOGOUT
   ========================================= */

async function handleLogout(event) {

    /*
     * Stop the "#" link from moving
     * the user to the top of the page.
     */
    event.preventDefault();


    const logoutLink =
        event.currentTarget;


    /*
     * Prevent multiple clicks.
     */
    logoutLink.style.pointerEvents =
        "none";


    logoutLink.textContent =
        "Logging out...";


    try {

        /*
         * Call your existing API function.
         *
         * api/auth.js already has:
         *
         * logoutUser()
         */
        const result =
            await logoutUser();


        console.log(
            "Logout response:",
            result
        );


        /*
         * Remove locally stored user.
         */
        localStorage.removeItem(
            "foodByKUser"
        );


        /*
         * Go back to homepage.
         */
        window.location.href =
            getHomePage();


    } catch (error) {

        console.error(
            "Logout error:",
            error
        );


        /*
         * Remove local user anyway.
         */
        localStorage.removeItem(
            "foodByKUser"
        );


        /*
         * Return to homepage.
         */
        window.location.href =
            getHomePage();

    }

}


/* =========================================
   RESTORE LOGIN + REGISTER
   ========================================= */

function showLoginAndRegister() {

    /*
     * Find the Login/Account link.
     */
    const loginLink =
        document.querySelector(
            '.navbar nav a[href*="login.html"], .navbar nav a[href*="account.html"]'
        );


    if (loginLink) {

        loginLink.textContent =
            "Login";


        loginLink.href =
            getLoginPage();

    }


    /*
     * Find Register/Logout.
     */
    const registerLink =
        document.querySelector(
            ".navbar nav a.nav-register"
        );


    if (registerLink) {

        registerLink.textContent =
            "Register";


        registerLink.href =
            getRegisterPage();


        registerLink.removeEventListener(
            "click",
            handleLogout
        );


        registerLink.style.pointerEvents =
            "auto";

    }

}


/* =========================================
   GET LOGIN PAGE
   ========================================= */

function getLoginPage() {

    /*
     * We are currently in src/index.html.
     *
     * Therefore:
     *
     * ../pages/auth/login.html
     */
    if (
        window.location.pathname.includes(
            "/src/"
        )
    ) {

        return "../pages/auth/login.html";

    }


    /*
     * If we're already inside pages/auth/
     */
    if (
        window.location.pathname.includes(
            "/pages/auth/"
        )
    ) {

        return "login.html";

    }


    return "../pages/auth/login.html";

}


/* =========================================
   GET REGISTER PAGE
   ========================================= */

function getRegisterPage() {

    if (
        window.location.pathname.includes(
            "/src/"
        )
    ) {

        return "../pages/auth/register.html";

    }


    if (
        window.location.pathname.includes(
            "/pages/auth/"
        )
    ) {

        return "register.html";

    }


    return "../pages/auth/register.html";

}


/* =========================================
   GET HOMEPAGE
   ========================================= */

function getHomePage() {

    /*
     * From pages/auth/account.html
     * back to src/index.html:
     *
     * ../../src/index.html
     */
    if (
        window.location.pathname.includes(
            "/pages/auth/"
        )
    ) {

        return "../../src/index.html";

    }


    /*
     * Already inside src/.
     */
    if (
        window.location.pathname.includes(
            "/src/"
        )
    ) {

        return "index.html";

    }


    return "../src/index.html";

}
/* =========================================
   LOGOUT BUTTON
   ========================================= */

const logoutButton =
    document.querySelector(".nav-logout");

if (logoutButton) {

    logoutButton.addEventListener(
        "click",
        handleLogout
    );

}
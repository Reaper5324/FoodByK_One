/* =========================================
   FOOD BY K - AUTHENTICATION PAGE LOGIC
   ========================================= */


/* ---------- LOGIN ---------- */

const loginForm = document.getElementById("loginForm");

if (loginForm) {

    loginForm.addEventListener("submit", async function (event) {

        // Stop the browser from reloading the page
        event.preventDefault();


        // Get form values
        const email = document
            .getElementById("loginEmail")
            .value
            .trim();

        const password = document
            .getElementById("loginPassword")
            .value;


        // Message container
        const messageContainer =
            document.getElementById("message");


        // Clear previous messages
        messageContainer.innerHTML = "";


        // Basic frontend validation
        if (!email || !password) {

            showMessage(
                "Please enter your email and password.",
                "error"
            );

            return;
        }


        // Get the login button
        const loginButton =
            loginForm.querySelector(
                'button[type="submit"]'
            );


        // Loading state
        loginButton.disabled = true;
        loginButton.textContent = "Logging in...";


        try {

            // Call the authentication API
            const result =
                await loginUser(
                    email,
                    password
                );


            // Successful login
            if (result.success) {

                // Store basic user information locally
                // This is for frontend UI state only.
                // Authentication is still controlled by
                // the PHP session on the backend.
                localStorage.setItem(
                    "foodByKUser",
                    JSON.stringify(result.data)
                );


                showMessage(
                    "Login successful! Redirecting...",
                    "success"
                );


                // Redirect after a short delay
                setTimeout(function () {

                    window.location.href =
                        "../../index.html";

                }, 1000);

            } else {

                // Display backend error
                showMessage(
                    result.error ||
                    "Login failed. Please try again.",
                    "error"
                );

            }

        } catch (error) {

            console.error(
                "Login Error:",
                error
            );

            showMessage(
                "Something went wrong. Please try again.",
                "error"
            );

        } finally {

            // Restore button
            loginButton.disabled = false;
            loginButton.textContent = "Login";

        }

    });

}


/* =========================================
   MESSAGE FUNCTION
   ========================================= */

function showMessage(message, type) {

    const messageContainer =
        document.getElementById("message");

    if (!messageContainer) {
        return;
    }

    messageContainer.innerHTML = `
        <div class="message ${type}">
            ${message}
        </div>
    `;

}
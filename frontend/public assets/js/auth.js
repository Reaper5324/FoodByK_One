/* =========================================
   FOOD BY K - AUTHENTICATION PAGE LOGIC
   ========================================= */


/* =========================================
   LOGIN
   ========================================= */

const loginForm =
    document.getElementById("loginForm");

if (loginForm) {

    loginForm.addEventListener(
        "submit",
        async function (event) {

            // Stop the browser from reloading
            event.preventDefault();


            // Get form values
            const email =
                document
                    .getElementById("loginEmail")
                    .value
                    .trim();

            const password =
                document
                    .getElementById("loginPassword")
                    .value;


            // Clear previous messages
            const messageContainer =
                document.getElementById("message");

            if (messageContainer) {
                messageContainer.innerHTML = "";
            }


            // Frontend validation
            if (!email || !password) {

                showMessage(
                    "Please enter your email and password.",
                    "error"
                );

                return;
            }


            // Get login button
            const loginButton =
                loginForm.querySelector(
                    'button[type="submit"]'
                );


            // Loading state
            loginButton.disabled = true;
            loginButton.textContent =
                "Logging in...";


            try {

                // Call login API
                const result =
                    await loginUser(
                        email,
                        password
                    );


                if (result.success) {

                    // Store user information for frontend UI
                    localStorage.setItem(
                        "foodByKUser",
                        JSON.stringify(result.data)
                    );


                    showMessage(
                        "Login successful! Redirecting...",
                        "success"
                    );


                    // Redirect to homepage
                    setTimeout(function () {

                        window.location.href =
                            "../../src/index.html";

                    }, 1000);

                } else {

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

                loginButton.disabled = false;
                loginButton.textContent =
                    "Login";

            }

        }
    );

}


/* =========================================
   REGISTRATION
   ========================================= */

const registerForm =
    document.getElementById("registerForm");

if (registerForm) {

    registerForm.addEventListener(
        "submit",
        async function (event) {

            // Stop browser reload
            event.preventDefault();


            // Get form values
            const firstName =
                document
                    .getElementById("firstName")
                    .value
                    .trim();

            const lastName =
                document
                    .getElementById("lastName")
                    .value
                    .trim();

            const email =
                document
                    .getElementById("email")
                    .value
                    .trim();

            const password =
                document
                    .getElementById("password")
                    .value;

            const confirmPassword =
                document
                    .getElementById("confirmPassword")
                    .value;


            // Clear previous messages
            const messageContainer =
                document.getElementById("message");

            if (messageContainer) {
                messageContainer.innerHTML = "";
            }


            /* ---------- Validation ---------- */

            if (
                !firstName ||
                !lastName ||
                !email ||
                !password ||
                !confirmPassword
            ) {

                showMessage(
                    "Please complete all fields.",
                    "error"
                );

                return;
            }


            if (password !== confirmPassword) {

                showMessage(
                    "Passwords do not match.",
                    "error"
                );

                return;
            }


            if (password.length < 8) {

                showMessage(
                    "Password must be at least 8 characters long.",
                    "error"
                );

                return;
            }


            // Get registration button
            const registerButton =
                registerForm.querySelector(
                    'button[type="submit"]'
                );


            registerButton.disabled = true;
            registerButton.textContent =
                "Creating Account...";


            try {

                const userData = {

                    first_name: firstName,
                    last_name: lastName,
                    email: email,
                    password: password

                };


                // Call registration API
                const result =
                    await registerUser(userData);


                if (result.success) {

                    showMessage(
                        "Account created successfully! Redirecting to login...",
                        "success"
                    );


                    setTimeout(function () {

                        window.location.href =
                            "login.html";

                    }, 1500);

                } else {

                    showMessage(
                        result.error ||
                        "Registration failed. Please try again.",
                        "error"
                    );

                }

            } catch (error) {

                console.error(
                    "Registration Error:",
                    error
                );

                showMessage(
                    "Something went wrong. Please try again.",
                    "error"
                );

            } finally {

                registerButton.disabled = false;
                registerButton.textContent =
                    "Create Account";

            }

        }
    );

}


/* =========================================
   FORGOT PASSWORD
   ========================================= */

const forgotPasswordForm =
    document.getElementById("forgotPasswordForm");

if (forgotPasswordForm) {

    forgotPasswordForm.addEventListener(
        "submit",
        async function (event) {

            // Stop browser reload
            event.preventDefault();


            // Get email
            const email =
                document
                    .getElementById("forgotEmail")
                    .value
                    .trim();


            // Clear previous messages
            const messageContainer =
                document.getElementById("message");

            if (messageContainer) {
                messageContainer.innerHTML = "";
            }


            // Validation
            if (!email) {

                showMessage(
                    "Please enter your email address.",
                    "error"
                );

                return;
            }


            // Get button
            const forgotButton =
                forgotPasswordForm.querySelector(
                    'button[type="submit"]'
                );


            forgotButton.disabled = true;
            forgotButton.textContent =
                "Sending...";


            try {

                // Call forgot password API
                const result =
                    await forgotPassword(email);


                if (result.success) {

                    showMessage(
                        "If an account exists for this email, password reset instructions have been sent.",
                        "success"
                    );

                    forgotPasswordForm.reset();

                } else {

                    showMessage(
                        result.error ||
                        "Unable to process your request. Please try again.",
                        "error"
                    );

                }

            } catch (error) {

                console.error(
                    "Forgot Password Error:",
                    error
                );

                showMessage(
                    "Something went wrong. Please try again.",
                    "error"
                );

            } finally {

                forgotButton.disabled = false;
                forgotButton.textContent =
                    "Send Reset Instructions";

            }

        }
    );

}


/* =========================================
   RESET PASSWORD
   ========================================= */

const resetPasswordForm =
    document.getElementById("resetPasswordForm");

if (resetPasswordForm) {

    resetPasswordForm.addEventListener(
        "submit",
        async function (event) {

            // Stop browser reload
            event.preventDefault();


            // Get password values
            const newPassword =
                document
                    .getElementById("newPassword")
                    .value
                    .trim();

            const confirmPassword =
                document
                    .getElementById("confirmPassword")
                    .value
                    .trim();


            // Clear previous messages
            const messageContainer =
                document.getElementById("message");

            if (messageContainer) {
                messageContainer.innerHTML = "";
            }


            /* ---------- Validation ---------- */

            // Empty fields
            if (!newPassword || !confirmPassword) {

                showMessage(
                    "Please enter and confirm your new password.",
                    "error"
                );

                return;
            }


            // Password length
            if (newPassword.length < 8) {

                showMessage(
                    "Password must be at least 8 characters long.",
                    "error"
                );

                return;
            }


            // Password match
            if (newPassword !== confirmPassword) {

                showMessage(
                    "Passwords do not match.",
                    "error"
                );

                return;
            }


            /* ---------- Get Reset Token ---------- */

            const urlParams =
                new URLSearchParams(
                    window.location.search
                );

            const token =
                urlParams.get("token");


            if (!token) {

                showMessage(
                    "Password reset token is missing or invalid.",
                    "error"
                );

                return;
            }


            // Get reset button
            const resetButton =
                resetPasswordForm.querySelector(
                    'button[type="submit"]'
                );


            // Loading state
            resetButton.disabled = true;
            resetButton.textContent =
                "Resetting...";


            try {

                // Call reset password API
                const result =
                    await resetPassword(
                        token,
                        newPassword
                    );


                if (result.success) {

                    showMessage(
                        "Your password has been reset successfully. Redirecting to login...",
                        "success"
                    );


                    setTimeout(function () {

                        window.location.href =
                            "login.html";

                    }, 1500);

                } else {

                    showMessage(
                        result.error ||
                        "Unable to reset your password. Please try again.",
                        "error"
                    );

                }

            } catch (error) {

                console.error(
                    "Reset Password Error:",
                    error
                );

                showMessage(
                    "Something went wrong. Please try again.",
                    "error"
                );

            } finally {

                resetButton.disabled = false;
                resetButton.textContent =
                    "Reset Password";

            }

        }
    );

}


/* =========================================
   ACCOUNT PAGE
   ========================================= */

const accountFirstName =
    document.getElementById("accountFirstName");

const accountLastName =
    document.getElementById("accountLastName");

const accountEmail =
    document.getElementById("accountEmail");


if (
    accountFirstName &&
    accountLastName &&
    accountEmail
) {

    // Get user information stored after login
    const storedUser =
        localStorage.getItem("foodByKUser");


    if (storedUser) {

        try {

            const user =
                JSON.parse(storedUser);


            accountFirstName.textContent =
                user.first_name ||
                user.firstName ||
                "Not available";


            accountLastName.textContent =
                user.last_name ||
                user.lastName ||
                "Not available";


            accountEmail.textContent =
                user.email ||
                "Not available";

        } catch (error) {

            console.error(
                "Account Data Error:",
                error
            );

            accountFirstName.textContent =
                "Not available";

            accountLastName.textContent =
                "Not available";

            accountEmail.textContent =
                "Not available";

        }

    } else {

        // No user data stored
        accountFirstName.textContent =
            "Not logged in";

        accountLastName.textContent =
            "Not logged in";

        accountEmail.textContent =
            "Not logged in";

    }

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
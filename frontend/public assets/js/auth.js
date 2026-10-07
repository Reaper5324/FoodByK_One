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

                /* ---------- Login ---------- */

                const loginResult =
                    await loginUser(
                        email,
                        password
                    );


                /* ---------- Handle Login Failure ---------- */

                if (!loginResult.success) {

                    showMessage(
                        loginResult.error ||
                        "Login failed. Please try again.",
                        "error"
                    );

                    return;
                }


                /* ---------- Get Current User ---------- */

                const userResult =
                    await getCurrentUser();


                if (!userResult.success) {

                    showMessage(
                        userResult.error ||
                        "Login succeeded, but we could not retrieve your account details.",
                        "error"
                    );

                    return;
                }


                /* ---------- Store User Information ---------- */

                const user =
                    userResult.data;

                localStorage.setItem(
                    "foodByKUser",
                    JSON.stringify(user)
                );


                showMessage(
                    "Login successful! Redirecting...",
                    "success"
                );


                /* ---------- Role-Based Redirect ---------- */

                const roleDestinations = {

                    admin:
                        "../../admin/dashboard.html",

                    staff:
                        "../../admin/orders.html",

                    customer:
                        "../customer/menu.html"

                };


                const destination =
                    roleDestinations[user?.role];


                if (!destination) {

                    showMessage(
                        "Your account role could not be recognised.",
                        "error"
                    );

                    return;
                }


                setTimeout(function () {

                    window.location.href =
                        destination;

                }, 1000);


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


            /* ---------- Password Requirements ---------- */

            if (
                password.length < 12 ||
                password.length > 128 ||
                /\s/.test(password) ||
                !/[a-z]/.test(password) ||
                !/[A-Z]/.test(password) ||
                !/\d/.test(password) ||
                !/[^A-Za-z0-9]/.test(password)
            ) {

                showMessage(
                    "Password must be 12-128 characters and include upper-case, lower-case, number, and symbol characters, with no spaces.",
                    "error"
                );

                return;
            }


            // Get registration button
            const registerButton =
                registerForm.querySelector(
                    'button[type="submit"]'
                );


            // Loading state
            registerButton.disabled = true;
            registerButton.textContent =
                "Creating Account...";


            try {

                const userData = {

                    name:
                        `${firstName} ${lastName}`,

                    email:
                        email,

                    password:
                        password

                };


                // Call registration API
                const result =
                    await registerUser(
                        userData
                    );


                if (result.success) {

                    localStorage.setItem(
                        "foodByKUser",
                        JSON.stringify(result.data)
                    );


                    showMessage(
                        "Account created successfully! Redirecting...",
                        "success"
                    );


                    setTimeout(function () {

                        window.location.href =
                            "../../src/index.html";

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


            // Loading state
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

            // Empty fields
            if (!newPassword || !confirmPassword) {

                showMessage(
                    "Please enter and confirm your new password.",
                    "error"
                );

                return;
            }


            // Password requirements
            if (
                newPassword.length < 12 ||
                newPassword.length > 128 ||
                /\s/.test(newPassword) ||
                !/[a-z]/.test(newPassword) ||
                !/[A-Z]/.test(newPassword) ||
                !/\d/.test(newPassword) ||
                !/[^A-Za-z0-9]/.test(newPassword)
            ) {

                showMessage(
                    "Password must be 12-128 characters and include upper-case, lower-case, number, and symbol characters, with no spaces.",
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

    async function loadAccountInformation() {

        try {

            // Get the currently logged-in user
            const result =
                await getCurrentUser();


            if (!result.success) {

                accountFirstName.textContent =
                    "Not available";

                accountLastName.textContent =
                    "Not available";

                accountEmail.textContent =
                    "Not available";

                console.error(
                    "Account Error:",
                    result.error
                );

                return;
            }


            // Get current user data
            const user =
                result.data;


            // Update local storage
            localStorage.setItem(
                "foodByKUser",
                JSON.stringify(user)
            );


            // Display user information
            const nameParts = String(user.full_name || user.name || "").trim().split(/\s+/).filter(Boolean);
            accountFirstName.textContent =
                user.first_name ||
                user.firstName ||
                nameParts[0] ||
                "Not available";


            accountLastName.textContent =
                user.last_name ||
                user.lastName ||
                nameParts.slice(1).join(" ") ||
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

    }


    loadAccountInformation();

}


/* =========================================
   CHANGE PASSWORD
   ========================================= */

const changePasswordForm =
    document.getElementById("changePasswordForm");

if (changePasswordForm) {

    changePasswordForm.addEventListener(
        "submit",
        async function (event) {

            // Stop browser reload
            event.preventDefault();


            /* ---------- Get Form Values ---------- */

            const currentPassword =
                document
                    .getElementById("currentPassword")
                    .value;

            const newPassword =
                document
                    .getElementById("newPassword")
                    .value;

            const confirmNewPassword =
                document
                    .getElementById("confirmNewPassword")
                    .value;


            /* ---------- Clear Previous Message ---------- */

            const passwordMessage =
                document.getElementById(
                    "passwordMessage"
                );

            if (passwordMessage) {
                passwordMessage.innerHTML = "";
            }


            /* ---------- Validate Empty Fields ---------- */

            if (
                !currentPassword ||
                !newPassword ||
                !confirmNewPassword
            ) {

                showPasswordMessage(
                    "Please complete all password fields.",
                    "error"
                );

                return;
            }


            /* ---------- Validate Password ---------- */

            if (
                newPassword.length < 12 ||
                newPassword.length > 128 ||
                /\s/.test(newPassword) ||
                !/[a-z]/.test(newPassword) ||
                !/[A-Z]/.test(newPassword) ||
                !/\d/.test(newPassword) ||
                !/[^A-Za-z0-9]/.test(newPassword)
            ) {

                showPasswordMessage(
                    "Password must be 12-128 characters and include upper-case, lower-case, number, and symbol characters, with no spaces.",
                    "error"
                );

                return;
            }


            /* ---------- Check Password Match ---------- */

            if (
                newPassword !==
                confirmNewPassword
            ) {

                showPasswordMessage(
                    "New passwords do not match.",
                    "error"
                );

                return;
            }


            /* ---------- Prevent Same Password ---------- */

            if (
                currentPassword ===
                newPassword
            ) {

                showPasswordMessage(
                    "Your new password must be different from your current password.",
                    "error"
                );

                return;
            }


            /* ---------- Get Button ---------- */

            const changePasswordButton =
                changePasswordForm.querySelector(
                    'button[type="submit"]'
                );


            /* ---------- Loading State ---------- */

            changePasswordButton.disabled = true;

            changePasswordButton.textContent =
                "Changing Password...";


            try {

                /* ---------- Call API ---------- */

                const result =
                    await changePassword(
                        currentPassword,
                        newPassword
                    );


                /* ---------- Handle Success ---------- */

                if (result.success) {

                    showPasswordMessage(
                        "Your password has been changed successfully.",
                        "success"
                    );


                    // Clear form
                    changePasswordForm.reset();


                } else {

                    /* ---------- Handle Failure ---------- */

                    showPasswordMessage(
                        result.error ||
                        "Unable to change your password. Please try again.",
                        "error"
                    );

                }

            } catch (error) {

                console.error(
                    "Change Password Error:",
                    error
                );


                showPasswordMessage(
                    "Something went wrong. Please try again.",
                    "error"
                );

            } finally {

                // Restore button
                changePasswordButton.disabled =
                    false;

                changePasswordButton.textContent =
                    "Change Password";

            }

        }
    );

}


/* =========================================
   CHANGE PASSWORD MESSAGE
   ========================================= */

function showPasswordMessage(
    message,
    type
) {

    const passwordMessage =
        document.getElementById(
            "passwordMessage"
        );


    if (!passwordMessage) {
        return;
    }


    passwordMessage.innerHTML = `
        <div class="message ${type}">
            ${message}
        </div>
    `;

}


/* =========================================
   GENERAL MESSAGE FUNCTION
   ========================================= */

function showMessage(
    message,
    type
) {

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
/* =========================================
   CHECK IF USER IS LOGGED IN
   ========================================= */

async function isUserLoggedIn() {
    try {
        const result = await apiGet("/auth/me");

        return result.success;

    } catch (error) {
        console.error(
            "Authentication Check Error:",
            error
        );

        return false;
    }
}

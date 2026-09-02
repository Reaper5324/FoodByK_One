console.log("FOOD BY K AUTH.JS IS LOADED");
/* =========================================
FOOD BY K - AUTHENTICATION PAGE LOGIC
========================================= */

/* =========================================
LOGIN
========================================= */

const loginForm = document.getElementById("loginForm");

if (loginForm) {


loginForm.addEventListener("submit", async function (event) {

    event.preventDefault();

    const email = document
        .getElementById("loginEmail")
        .value
        .trim();

    const password = document
        .getElementById("loginPassword")
        .value;

    const messageContainer =
        document.getElementById("message");

    messageContainer.innerHTML = "";

    if (!email || !password) {

        showMessage(
            "Please enter your email and password.",
            "error"
        );

        return;
    }

    const loginButton =
        loginForm.querySelector(
            'button[type="submit"]'
        );

    loginButton.disabled = true;
    loginButton.textContent = "Logging in...";

    try {

        const result =
            await loginUser(
                email,
                password
            );

        if (result.success) {

            localStorage.setItem(
                "foodByKUser",
                JSON.stringify(result.data)
            );

            showMessage(
                "Login successful! Redirecting...",
                "success"
            );

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
        loginButton.textContent = "Login";

    }

});


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

        event.preventDefault();

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

        const messageContainer =
            document.getElementById("message");

        messageContainer.innerHTML = "";

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
                "Password must be at least 8 characters.",
                "error"
            );

            return;
        }

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

        event.preventDefault();

        const email =
            document
                .getElementById("forgotEmail")
                .value
                .trim();

        const messageContainer =
            document.getElementById("message");

        messageContainer.innerHTML = "";

        if (!email) {

            showMessage(
                "Please enter your email address.",
                "error"
            );

            return;
        }

        const forgotButton =
            forgotPasswordForm.querySelector(
                'button[type="submit"]'
            );

        forgotButton.disabled = true;
        forgotButton.textContent = "Sending...";

        try {

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
MESSAGE FUNCTION
========================================= */

function showMessage(message, type) {


const messageContainer =
    document.getElementById("message");

if (!messageContainer) {
    return;
}

messageContainer.innerHTML =
    '<div class="message ' +
    type +
    '">' +
    message +
    '</div>';
}
/* =========================================
   RESET PASSWORD
   ========================================= */

const resetPasswordForm = document.getElementById("resetPasswordForm");

if (resetPasswordForm) {

    resetPasswordForm.addEventListener("submit", async function (event) {

        event.preventDefault();

        console.log("Reset Password form submitted");

        const newPassword =
            document.getElementById("newPassword").value.trim();

        const confirmPassword =
            document.getElementById("confirmPassword").value.trim();

        console.log("New password length:", newPassword.length);
        console.log("Passwords match:", newPassword === confirmPassword);

        /* ---------- Empty Fields ---------- */

        if (newPassword === "" || confirmPassword === "") {

            showMessage(
                "Please enter and confirm your new password.",
                "error"
            );

            return;
        }

        /* ---------- Password Length ---------- */

        if (newPassword.length < 8) {

            showMessage(
                "Password must be at least 8 characters long.",
                "error"
            );

            return;
        }

        /* ---------- Password Match ---------- */

        if (newPassword !== confirmPassword) {

            showMessage(
                "Passwords do not match.",
                "error"
            );

            return;
        }

        /* ---------- Get Reset Token ---------- */

        const urlParams =
            new URLSearchParams(window.location.search);

        const token =
            urlParams.get("token");

        if (!token) {

            showMessage(
                "Password reset token is missing or invalid.",
                "error"
            );

            return;
        }

        /* ---------- Reset Button ---------- */

        const resetButton =
            resetPasswordForm.querySelector(
                'button[type="submit"]'
            );

        resetButton.disabled = true;
        resetButton.textContent = "Resetting...";

        try {

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

                    window.location.href = "login.html";

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
            resetButton.textContent = "Reset Password";

        }

    });

}
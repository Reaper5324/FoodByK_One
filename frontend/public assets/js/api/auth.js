/* =========================================
   FOOD BY K - AUTHENTICATION API SERVICE
   ========================================= */


/* ---------- Register ---------- */

async function registerUser(userData) {

    return apiPost("/auth/register", userData);

}


/* ---------- Login ---------- */

async function loginUser(email, password) {

    return apiPost("/auth/login", {
        email: email,
        password: password
    });

}


/* ---------- Logout ---------- */

async function logoutUser() {

    return apiPost("/auth/logout", {});

}


/* ---------- Get Current User ---------- */

async function getCurrentUser() {

    return apiGet("/auth/me");

}


/* ---------- Change Password ---------- */

async function changePassword(currentPassword, newPassword) {

    return apiPost("/auth/change-password", {
        current_password: currentPassword,
        new_password: newPassword
    });

}


/* ---------- Forgot Password ---------- */

async function forgotPassword(email) {

    return apiPost("/auth/forgot-password", {
        email: email
    });

}


/* ---------- Reset Password ---------- */

async function resetPassword(token, password) {

    return apiPost("/auth/reset-password", {
        token: token,
        password: password
    });

}
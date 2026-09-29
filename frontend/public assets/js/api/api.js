/* =========================================
   FOOD BY K - CENTRAL API CLIENT
   ========================================= */

async function apiRequest(endpoint, options = {}) {

    const url = API_BASE_URL + endpoint;
    const method = (options.method || "GET").toUpperCase();

    const defaultOptions = {
        headers: {
            "Content-Type": "application/json"
        },
        credentials: "include"
    };

    const requestOptions = {
        ...defaultOptions,
        ...options,

        headers: {
            ...defaultOptions.headers,
            ...(options.headers || {}),
            ...(method !== "GET" && method !== "HEAD" && window.foodByKCsrfToken
                ? { "X-CSRF-Token": window.foodByKCsrfToken }
                : {})
        }
    };

    try {

        const csrfExempt = ["/auth/login", "/auth/register", "/auth/forgot-password", "/auth/reset-password"];
        if (method !== "GET" && method !== "HEAD" && method !== "OPTIONS"
            && !csrfExempt.includes(endpoint.split("?")[0])
            && !window.foodByKCsrfToken) {
            const csrfResponse = await fetch(API_BASE_URL + "/auth/me", {
                method: "GET",
                credentials: "include"
            });
            window.foodByKCsrfToken = csrfResponse.headers.get("X-CSRF-Token");
            if (window.foodByKCsrfToken) {
                requestOptions.headers["X-CSRF-Token"] = window.foodByKCsrfToken;
            }
        }

        const response = await fetch(url, requestOptions);
        const csrfToken = response.headers.get("X-CSRF-Token");
        if (csrfToken) window.foodByKCsrfToken = csrfToken;

        const result = await response.json();

        return result;

    } catch (error) {

        console.error("API Request Error:", error);

        return {
            success: false,
            data: null,
            error: "Unable to connect to the server. Please try again."
        };
    }
}


/* =========================================
   HTTP HELPER FUNCTIONS
   ========================================= */

async function apiGet(endpoint) {

    return apiRequest(endpoint, {
        method: "GET"
    });
}


async function apiPost(endpoint, data) {

    return apiRequest(endpoint, {
        method: "POST",
        body: JSON.stringify(data)
    });
}


async function apiPut(endpoint, data) {

    return apiRequest(endpoint, {
        method: "PUT",
        body: JSON.stringify(data)
    });
}


async function apiDelete(endpoint) {

    return apiRequest(endpoint, {
        method: "DELETE"
    });
}

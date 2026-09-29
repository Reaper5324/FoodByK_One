/* =========================================
   FOOD BY K - CENTRAL API CLIENT
   ========================================= */


/* =========================================
   1. CSRF EXEMPT ENDPOINTS
   ========================================= */

const CSRF_EXEMPT_ENDPOINTS = [
    "/auth/login",
    "/auth/register",
    "/auth/forgot-password",
    "/auth/reset-password"
];


/* =========================================
   2. CENTRAL API REQUEST
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


    /* -----------------------------------------
       Build request options
       ----------------------------------------- */

    const requestOptions = {
        ...defaultOptions,
        ...options,

        headers: {
            ...defaultOptions.headers,
            ...(options.headers || {})
        }
    };


    /* -----------------------------------------
       Add CSRF token where required
       ----------------------------------------- */

    const endpointPath = endpoint.split("?")[0];

    const needsCsrf =
        method !== "GET" &&
        method !== "HEAD" &&
        method !== "OPTIONS" &&
        !CSRF_EXEMPT_ENDPOINTS.includes(endpointPath);


    if (needsCsrf && window.foodByKCsrfToken) {

        requestOptions.headers["X-CSRF-Token"] =
            window.foodByKCsrfToken;
    }


    try {

        /* -----------------------------------------
           Make request
           ----------------------------------------- */

        const response = await fetch(url, requestOptions);


        /* -----------------------------------------
           Save CSRF token returned by backend
           ----------------------------------------- */

        const csrfToken =
            response.headers.get("X-CSRF-Token");

        if (csrfToken) {

            window.foodByKCsrfToken = csrfToken;
        }


        /* -----------------------------------------
           Try to read JSON response
           ----------------------------------------- */

        let result = null;

        try {

            result = await response.json();

        } catch (jsonError) {

            console.warn(
                "API response was not valid JSON:",
                jsonError
            );

        }


        /* -----------------------------------------
           Handle 401 - Unauthorized
           ----------------------------------------- */

        if (response.status === 401) {

            console.warn("401 Unauthorized");

            return {
                success: false,
                data: null,
                error: result?.error ||
                    "Your session has expired. Please log in again.",
                status: 401
            };
        }


        /* -----------------------------------------
           Handle 403 - Forbidden
           ----------------------------------------- */

        if (response.status === 403) {

            console.warn("403 Forbidden");

            return {
                success: false,
                data: null,
                error: result?.error ||
                    "You do not have permission to perform this action.",
                status: 403
            };
        }


        /* -----------------------------------------
           Handle 404 - Not Found
           ----------------------------------------- */

        if (response.status === 404) {

            return {
                success: false,
                data: null,
                error: result?.error ||
                    "The requested resource could not be found.",
                status: 404
            };
        }


        /* -----------------------------------------
           Handle 429 - Too Many Requests
           ----------------------------------------- */

        if (response.status === 429) {

            return {
                success: false,
                data: null,
                error: result?.error ||
                    "Too many requests. Please wait a moment and try again.",
                status: 429
            };
        }


        /* -----------------------------------------
           Handle server errors
           ----------------------------------------- */

        if (response.status >= 500) {

            return {
                success: false,
                data: null,
                error: result?.error ||
                    "The server is currently unavailable. Please try again later.",
                status: response.status
            };
        }


        /* -----------------------------------------
           Handle other unsuccessful responses
           ----------------------------------------- */

        if (!response.ok) {

            return {
                success: false,
                data: null,
                error: result?.error ||
                    "Something went wrong. Please try again.",
                status: response.status
            };
        }


        /* -----------------------------------------
           Successful response
           ----------------------------------------- */

        return {
            ...(result || {
                success: true,
                data: null,
                error: null
            }),

            status: response.status
        };


    } catch (error) {

        console.error(
            "API Request Error:",
            error
        );


        /* -----------------------------------------
           Network / connection error
           ----------------------------------------- */

        return {
            success: false,
            data: null,
            error:
                "Unable to connect to the server. Please try again.",
            status: 0
        };
    }
}


/* =========================================
   3. HTTP HELPER FUNCTIONS
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
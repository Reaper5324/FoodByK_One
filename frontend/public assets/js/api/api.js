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

const SESSION_TOKEN_KEY = "foodByKSessionToken";

let csrfTokenRequest = null;

async function ensureCsrfToken() {
    if (window.foodByKCsrfToken) return window.foodByKCsrfToken;

    // Share the bootstrap request across simultaneous writes so they all use
    // the same session token and none proceeds before it has arrived.
    if (!csrfTokenRequest) {
        csrfTokenRequest = fetch(API_BASE_URL + "/auth/me", {
            method: "GET",
            credentials: "include",
            headers: buildSessionHeaders()
        }).then((response) => {
            const token = response.headers.get("X-CSRF-Token");
            if (token) window.foodByKCsrfToken = token;
            return token;
        }).finally(() => {
            csrfTokenRequest = null;
        });
    }

    return csrfTokenRequest;
}


/* =========================================
   1b. SESSION TOKEN HELPERS (Safari/iOS fallback)
   ========================================= */

// Cross-site cookies (Netlify -> Railway) are frequently blocked by
// Safari's ITP even with SameSite=None; Secure correctly set. When present,
// this header lets the backend resume the session directly instead of
// depending on the cookie arriving at all - see bootstrap.php.
function buildSessionHeaders() {
    const token = localStorage.getItem(SESSION_TOKEN_KEY);
    return token ? { "X-Session-Token": token } : {};
}

function storeSessionToken(token) {
    if (token) localStorage.setItem(SESSION_TOKEN_KEY, token);
}

function clearSessionToken() {
    localStorage.removeItem(SESSION_TOKEN_KEY);
}


/* =========================================
   2. CENTRAL API REQUEST
   ========================================= */

async function apiRequest(endpoint, options = {}) {

    const url = API_BASE_URL + endpoint;
    const method = (options.method || "GET").toUpperCase();

    const defaultOptions = {
        headers: {
            "Content-Type": "application/json",
            ...buildSessionHeaders()
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


    try {
        if (needsCsrf) {
            const csrfToken = await ensureCsrfToken();
            if (csrfToken) {
                requestOptions.headers["X-CSRF-Token"] = csrfToken;
            }
        }

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
           Capture session token from login/register
           (body, not a header - see AuthService::login/register)
           ----------------------------------------- */

        if (result?.data?.session_token) {
            storeSessionToken(result.data.session_token);
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
/* =========================================
   FOOD BY K - CENTRAL API CLIENT
   ========================================= */

async function apiRequest(endpoint, options = {}) {

    const url = API_BASE_URL + endpoint;

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
            ...(options.headers || {})
        }
    };

    try {

        const response = await fetch(url, requestOptions);

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
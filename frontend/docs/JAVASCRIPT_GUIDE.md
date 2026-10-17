# Food by K Frontend JavaScript Guide

JavaScript is the programming language that runs in the browser. In Food by K it makes static pages interactive: it loads products and customer data, submits forms, updates navigation, shows feedback, and communicates with the backend API.

## Where the JavaScript lives

`frontend/public assets/js/` contains the shared browser code:

- `config.js` defines the backend API base URL (`API_BASE_URL`).
- `api/api.js` is the central HTTP client. It sends requests, includes credentials, manages CSRF/session details, parses the standard JSON response, and gives callers a consistent result object.
- `api/auth.js` has authentication/session helper functions used by pages.
- `navigation.js` enhances the shared navbar, resolves account-dependent links, creates the mobile menu, and loads the assistant UI when a navbar exists.
- `auth.js`, `account.js`, `privacy.js`, and `assistant.js` handle authentication forms, customer account actions, cookie choices, and the FAQ assistant.
- Page scripts such as `home.js`, `menu.js`, `menu-item.js`, `cart.js`, `checkout.js`, `orders.js`, `loyalty.js`, and `promotions.js` implement page-specific behavior.
- `dashboard.js` and `admin-live.js` power legacy dashboard and live staff/admin interactions.

The `frontend/js/` directory also contains older admin scripts. PHP helpers under `frontend/services/` are server-side legacy code and do not run in the browser as JavaScript.

## Typical execution pattern

Most page scripts wait for the browser's `DOMContentLoaded` event. That means the HTML has been parsed and elements can safely be found by ID or selector. For example, `menu.js` gets the category and product containers, requests data, then creates the page controls and cards.

Modern JavaScript features used in the project include:

- `const` and `let` for variables.
- Functions and arrow functions for reusable actions and callbacks.
- `async` and `await` for network requests that take time.
- `fetch()` for HTTP communication.
- `addEventListener()` for clicks, form submits, keyboard input, and field changes.
- `URLSearchParams` for reading query parameters.
- DOM methods such as `createElement`, `append`, `replaceChildren`, and `textContent` to build or update the page.

A common pattern is to set a loading state, await an API helper, then handle success and failure separately:

```js
region.setAttribute("aria-busy", "true");
const result = await apiGet("/products");
if (!result.success) {
  showMessage(result.error, true);
  region.setAttribute("aria-busy", "false");
  return;
}
renderProducts(result.data);
region.setAttribute("aria-busy", "false");
```

This is an illustrative pattern; use the helper names and response shape already present in the page being changed.

## How browser code calls the backend

The customer frontend and PHP API are deployed separately. JavaScript sends HTTP requests to the URL in `config.js`. It does not connect directly to MySQL and must not contain database credentials or payment secrets.

The central API client in `api/api.js`:

1. Builds the API URL from `API_BASE_URL` and the endpoint path.
2. Uses `fetch()` and JSON request bodies for most data operations.
3. Includes `credentials: "include"` so browser-managed session cookies can be sent where allowed.
4. Obtains and sends CSRF tokens on state-changing requests where required. A CSRF token helps protect an authenticated browser session from an unwanted cross-site form submission.
5. Sends the session fallback header when available for browsers that restrict cross-site cookies.
6. Parses the API JSON response and turns common HTTP errors such as 401, 403, 404, 429, and 428 into a predictable result for page scripts.

The backend normally returns a JSON envelope shaped like `{ success, data, error }`. The page can display `data` when successful or a human-readable `error` when not. Helper functions such as `apiGet`, `apiPost`, `apiPut`, and `apiDelete` wrap the lower-level request function.

A typical request path is:

```text
Customer clicks or submits a form
        ↓
Page script reads the relevant HTML fields
        ↓
api.js sends an HTTP request to the Railway PHP API
        ↓
Route selects a controller; middleware checks session, role, CSRF, or policy
        ↓
Controller calls the service that owns the operation
        ↓
Service reads/writes models and database
        ↓
JSON response returns through api.js to the page script
        ↓
JavaScript updates the page and announces feedback
```

## Page scripts and examples

- `menu.js` loads categories and products, performs search/filter requests, renders menu cards, and adds an item to a signed-in customer's cart. While loading, it inserts a known number of skeleton cards, then replaces them with data or an empty/error state.
- `cart.js` reads the cart, renders actual item rows and totals, and sends quantity/remove/clear actions to the API. Loading placeholders are cleared before real rows are rendered.
- `checkout.js` loads addresses and time slots, requests a server-calculated review total, and submits fulfilment and payment-token setup information. The browser's preview is for display; the backend recalculates and validates prices and business rules.
- `orders.js` fetches the customer's latest/current order and periodically refreshes it, then renders status progress. Collection and delivery labels differ. The backend's order status is authoritative; the customer page does not invent staff progress. When a completed order redirects to the menu, a one-time completion marker and query parameters control the thank-you dialog.
- `account.js` loads profile, saved addresses, and policy state; it validates fields for usability and submits changes through the API.
- `auth.js` handles login, registration, password recovery/reset, password strength guidance, and show/hide-password controls.
- `navigation.js` adds the mobile hamburger interaction and checks the current user before rendering the correct set of links. It temporarily shows navigation skeletons to avoid flashing the wrong logged-in or logged-out controls.
- `assistant.js` presents the FAQ chat interface and sends eligible product/policy questions to the backend assistant endpoint. Transactional order/payment decisions stay in regular platform flows.
- `admin-live.js` and `dashboard.js` perform staff/admin page operations and refresh live views through protected API endpoints.

## Rendering and safety

When adding dynamic content, prefer `textContent` and DOM construction over interpolating customer or API text into `innerHTML`. `innerHTML` parses a string as markup; unsafe interpolation can turn text into executable HTML. For static markup controlled by the project, existing code may use it, but user-provided text should remain text.

Use `aria-busy` during loading, `role="status"` or `aria-live` for updates, and remove skeleton nodes when the request settles. Handle failed network requests as well as unsuccessful JSON results: the visitor should receive a useful error and be able to retry. Do not put API keys, database passwords, or payment gateway secrets in browser files; browser code is public to every site visitor.

## Keeping JavaScript maintainable

Keep shared networking in the API client and page behavior in the corresponding page script. Avoid copying raw `fetch()` settings into every page. Keep business decisions on the backend: the browser can help the customer choose, but the server validates identity, role, prices, promotions, delivery, slot capacity, and order state. Check script load order in the HTML because a page script that uses `apiGet()` must load after `api.js`.

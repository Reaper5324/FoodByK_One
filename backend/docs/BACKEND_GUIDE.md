# Food by K Backend Guide

This guide explains the PHP backend in student-friendly terms. The backend is the part of the system that receives requests from the website, checks whether they are allowed, applies business rules, reads or changes data, and sends a result back. It runs on the backend host (for example Railway). The static customer pages can be hosted separately on Netlify.

## The request journey

The central pattern is **Controller → Service → Model → Database**:

1. A browser sends an HTTP request such as `GET /products` or `POST /checkout/submit`.
2. The router matches the URL and HTTP method to a controller action.
3. Middleware performs checks around the action, such as checking the signed-in user, role, CSRF token, or required policy acceptance.
4. A controller reads request values and calls the relevant service.
5. A service applies business rules and coordinates models. Multi-step changes and database transactions belong here.
6. Models read or write database records.
7. A `Response` converts the result into JSON and the browser uses it to update the page.

A **route** is the mapping from an HTTP method and URL to code. A **controller** adapts HTTP details into an application call. A **service** is where a business operation is carried out. A **model** represents data and provides persistence behavior. A **transaction** groups database writes so they either all succeed or all roll back.

## Repository entry points

### `backend/public/`

This is the web server's document root and HTTP entry point.

- `index.php` loads `bootstrap.php` and `routes.php`, configures CORS for approved frontend origins, handles browser preflight requests, creates the router, dispatches the captured request, and sends the response. CORS is the browser rule that controls which website origins may call this API.
- `router.php` is a helper for PHP's built-in development server. It lets real files be served directly and forwards API paths to `index.php`.

The frontend must use the deployed API base URL. It must not connect to the database directly.

### `backend/foodbyk/bootstrap.php`

This file prepares the PHP application before a request is handled. It sets the timezone, reads a local `.env` file when present, loads configuration, optionally loads Composer's vendor autoloader, starts a secure PHP session, and registers a custom class autoloader.

An **autoloader** finds and includes a class file when PHP first needs that class. The custom autoloader searches the `core`, `middleware`, `models`, `services`, and `controllers` directories. `Model` and `Database` are explicit special cases because their filenames do not exactly match the class name's case.

The session setup uses HTTP-only cookies and secure cookie settings for HTTPS. It also supports an `X-Session-Token` fallback for browsers that restrict cross-site cookies between the separately hosted frontend and backend.

## `backend/foodbyk/` folders

### `config/`

- `config.php` reads environment variables and exposes them as constants used by the application. These include database, PayFast, storage, email, WhatsApp/SMS, frontend-origin, and RAG service settings.
- `templates.md` records Twilio message-template names and IDs. It is configuration documentation, not executable PHP.

An **environment variable** is a value supplied outside the source code, often by the hosting provider. Secrets belong in the deployment environment, not in public frontend files or committed code.

### `core/`

These classes are the small HTTP framework used by the project; they replace framework packages.

- `Request.php` captures the method, path, query string, body, and headers. It parses JSON request bodies and provides helpers such as `input()`, `header()`, and `user()`.
- `Router.php` registers routes, matches route parameters such as `{id}`, runs the route's middleware in order, calls the controller, and converts unexpected exceptions into a safe server error response while logging details.
- `Response.php` builds JSON success/error responses, converts service results, supports redirects for browser navigation such as PayFast returns, and sends the HTTP status and body.

### `database/`

- `Database.php` creates and reuses the PDO database connection. PDO is PHP's database access interface; prepared statements pass values separately from SQL text and help avoid SQL injection.
- `migration.sql` is the main schema setup and seed for the platform: users, products, categories, addresses, carts, orders, payments, promotions, settings, and related constraints/indexes.
- `loyalty_rewards_migration.sql` adds the loyalty feature's database structures/changes.
- `add_address_province.sql` adds the address province field for structured delivery details.
- `harden_existing_schema.sql` adds defensive data constraints to an existing schema.
- `correct_loyalty_points_rate.sql` corrects the stored loyalty-points rate for the intended decimal earning behavior.
- `payment_refunds.sql` adds the refund request/status table.

SQL files are database instructions, not PHP classes. Review the target environment and migration history before running a schema file; do not treat a development migration as a safe production reset. Foreign keys connect related records, indexes speed up common lookups, and `CHECK` constraints reject values that violate defined rules.

### `middleware/`

Middleware runs before a controller action. It can allow the request to continue or stop it with an error response.

- `Middleware.php` defines the common middleware contract.
- `AuthMiddleware.php` loads and verifies the signed-in user.
- `RoleMiddleware.php` restricts actions to customers, staff, admins, or a permitted combination.
- `CsrfMiddleware.php` checks cross-site request forgery tokens on state-changing requests.
- `RateLimitMiddleware.php` limits repeated requests such as login, password reset, and assistant questions.
- `CustomerPolicyMiddleware.php` requires the customer to accept the current required policy version before protected customer actions can continue.

Middleware is a useful place for checks shared by many routes. It is not where order totals or other business workflows should be implemented.

### `models/`

Models represent records and their small domain behaviors. Most extend `Model` in `model.php`.

- `model.php` is the Active Record base class. **Active Record** means the object provides methods to find, save, and delete its own database row.
- `User.php`, `Customer.php`, `Staff.php`, `Admin.php`, and `Role.php` represent account identities and role-specific behavior. The user table stores the account role; customer-specific functionality includes addresses, cart, orders, and loyalty points.
- `Product.php` and `Category.php` represent menu entries and categories.
- `Address.php` represents a saved delivery address, including its structured fields and optional geocoded coordinates.
- `CartItem.php` represents one customer's cart row and its product/line-total relationship.
- `Order.php`, `OrderItem.php`, and `OrderStatusHistory.php` represent the order, its purchased items, and its status audit trail. `Order::canTransitionTo()` centralizes allowed status changes, while `Order::lockById()` supplies a row-locking read for safe concurrent updates.
- `Payment.php` represents the payment/token state for an order. `PaymentRefund.php` represents a refund request and its status.
- `BusinessSettings.php` reads operational settings such as trading hours, delivery limits, and slot capacity.
- `Promotion.php` represents a discount code and its conditions.
- `PasswordReset.php` represents time-limited password-reset state.

Models may contain persistence logic and small domain checks, but they do not own multi-model transactions. The service layer owns those workflows.

### `services/`

Services implement business operations. They use models, validate rules, coordinate external APIs, and return a consistent array result such as `['success' => true, 'data' => ..., 'error' => null]`.

- `AuthService.php` handles registration, login, session/profile operations, password changes and resets, staff/customer account administration, policy acceptance, and account deletion.
- `AddressService.php` validates, creates, updates, deletes, and chooses customer delivery addresses.
- `CartService.php` manages cart contents and serializes cart edits against checkout.
- `CategoryService.php` and `ProductService.php` serve menu/category data and admin product/category operations.
- `CheckoutService.php` prepares the customer-facing checkout preview and checks the cart, delivery address, and requested time window.
- `DeliveryService.php` geocodes addresses and calculates fulfilment eligibility and delivery charges. It uses a `Geocoder` interface and a delivery-fee strategy so these implementations can change without rewriting the order workflow.
- `SlotService.php` lists available order windows, validates a requested window, and reserves capacity safely during order changes.
- `OrderService.php` submits customer orders, loads order views, and handles staff confirmation, adjustment, decline, cancellation, fulfilment progress, and refund orchestration. It uses transactions and locking reads for concurrent updates.
- `PaymentService.php` implements PayFast token setup, token/charge webhooks, token charging, and refund handling. Under the platform's Ad Hoc Tokenization flow, card details are tokenized first; the order is charged after staff confirmation. Payment webhooks can be retried, so handlers need idempotent behavior.
- `PromotionService.php` validates and calculates promotion discounts.
- `LoyaltyService.php` reads balances/history and awards, redeems, or restores points.
- `NotificationService.php` defines the `OrderNotifier` interface and email, WhatsApp, and SMS implementations. It assembles messages for order events.
- `AnalyticsService.php` builds staff/admin reporting data.
- `AdminSettingsService.php` manages business configuration and promotion/admin settings.
- `PolicyService.php` serves policy versions and records acceptances.
- `RagAssistantService.php` calls the separately hosted RAG FAQ service through the backend proxy. The model is limited to approved menu and policy information; transactional decisions remain in the regular backend code.

A **strategy** is a replaceable implementation of one operation, such as calculating a delivery fee. An **interface** describes methods that implementations must provide. An **observer** pattern is used for notifications: the notification service calls registered notifier implementations rather than embedding every delivery channel in order logic.

### `controllers/`

Controllers translate requests into service calls and responses. They should not contain SQL or business workflows.

- `Controller.php` provides common controller response helpers.
- `HealthController.php` serves the health-check route.
- `AuthController.php` handles account and authentication endpoints.
- `AddressController.php` handles saved-address endpoints.
- `CartController.php` handles cart endpoints.
- `CheckoutController.php` handles checkout preview, available-slot, and order submission requests.
- `OrderController.php` handles customer and staff order views and order actions.
- `PaymentController.php` handles PayFast return/cancel navigation and webhook endpoints.
- `ProductController.php` and `CategoryController.php` serve menu and category endpoints, including staff availability actions.
- `PromotionController.php` serves active promotions.
- `LoyaltyController.php` serves customer loyalty balance/history endpoints.
- `AnalyticsController.php` serves staff and admin summaries.
- `AdminController.php` groups admin endpoints for managing customers, staff, products, categories, promotions, images, and business settings.
- `AssistantController.php` accepts a customer FAQ question and passes it to the assistant service.

### `routes.php`

`routes.php` maps each HTTP method and URL to a controller action and its middleware. It is the API route list. For example, a customer cart mutation requires authentication, customer role, CSRF protection, and current customer policy acceptance. Public product browsing has no customer login requirement.

Routes define access protection explicitly, so when adding an endpoint, choose middleware according to the actor and whether the operation changes data.

### Root files and supporting files

- `README.md` contains backend setup and operation notes.
- `POPIA_REVIEW.md` records the platform's privacy review.
- `composer.json` describes optional PHP package dependencies. The application itself uses its own classes and autoloader rather than a framework.
- `bootstrap.php` is described above and initializes the request environment.

## Database safety and important rules

- Use prepared SQL statements for values supplied by users.
- Keep multi-step writes inside service-owned transactions.
- Use `Order::lockById()` inside a transaction before changing an order status. This prevents two staff members from applying conflicting changes at once.
- Keep status transitions in the order state machine (`Order::canTransitionTo()`), not in controller conditionals.
- The server calculates order prices and discounts. Frontend totals are previews, not trusted payment amounts.
- The payment token is sensitive and must not be returned to the browser or written to logs.
- Store credentials in environment variables, never in frontend assets.

## A short example of adding behavior

Suppose a customer page needs to request their current order. The HTML calls an API helper for `GET /orders`; `routes.php` maps that route to `OrderController::index`; `AuthMiddleware` and the customer role/policy checks run; the controller calls `OrderService`; the service loads the order and related data through models; `Response` sends the JSON envelope. The browser then renders the order card.

This separation makes it easier to understand which layer to change: HTTP parsing belongs in the controller, business rules belong in a service, record persistence belongs in a model, and table structure belongs in SQL migrations.
